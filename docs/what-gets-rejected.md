# What Gets Rejected

Every rule SvgValidator applies, one section per error code, with the smallest file that
breaks it and what to change so it passes. The yardstick is Chrome's `<img>` mode: where a
rule is stricter than Chrome, the section says so and why.

Each example is a complete file. The comment after it shows what `checkString()` reports:
the `code` and the `detail`, which together make the `message` (see
[Getting Started](getting-started.md#reading-a-result)). Codes never change once released;
message wording can.

Contents:

- [The File Itself](#the-file-itself) - `file-unreadable`, `not-svg`, `not-utf8`, `malformed-xml`, `doctype-not-allowed`, `processing-instruction`, `comment-not-allowed`
- [The Root Element](#the-root-element) - `root-not-svg`, `root-namespace-wrong`
- [Elements](#elements) - `element-not-allowed`, `namespace-not-allowed`
- [Attributes](#attributes) - `event-handler`, `attribute-not-allowed`
- [Links and Images](#links-and-images) - `href-not-allowed`, `image-href-not-allowed`, `embedded-svg-not-allowed`, `url-not-fragment`
- [CSS](#css) - `css-not-allowed`
- [Animation](#animation) - `animation-target-not-allowed`, `animation-value-not-allowed`
- [References](#references) - `reference-expansion-too-large`
- [What Is Not Checked](#what-is-not-checked)

## The File Itself

These checks run on the bytes before parsing starts, or come from the parser itself. Each of
the first five ends the check, so it is the last error reported for that file.

### Cannot Read the File - `file-unreadable`

The path given to `checkFile()` is not a file, or PHP cannot read it. Nothing throws; the
result carries this one error with the file's basename as the detail, so an upload handler
has one code path for every outcome.

```php
$result = SvgValidator::checkFile('/uploads/missing.svg');
$result->errors[0]->message;   // Cannot read file missing.svg
```

**Fix:** pass the upload's `tmp_name`, and check that the upload succeeded first.

### Not an SVG File - `not-svg`

After an optional UTF-8 byte order mark and leading whitespace, the first byte must be `<`.
Anything else is a PNG, a PDF, a text file, or an empty file saved with a `.svg` extension.
The detail is the first 20 bytes, with control and non-ASCII bytes escaped, or
`nothing (the file is empty)`.

```text
Not really an SVG.
```

```text
rejected: not-svg, detail "Not really an SVG."
```

**Fix:** export the file as SVG from the design tool. Renaming does not convert it.

### Not UTF-8 - `not-utf8`

The file starts with a UTF-16 or UTF-32 byte order mark, or its XML declaration names an
encoding other than UTF-8. Browsers handle other encodings; a file that two parsers decode
differently is where trouble starts, so only UTF-8 is accepted.

```xml
<?xml version="1.0" encoding="ISO-8859-1"?>
<svg xmlns="http://www.w3.org/2000/svg"/>
<!-- rejected: not-utf8, detail "declared as ISO-8859-1" -->
```

**Fix:** save the file as UTF-8. Every design tool does this by default.

### Not Well-Formed XML - `malformed-xml`

libxml2 could not parse the file: an unclosed tag, a bare `&`, an undefined namespace prefix,
invalid UTF-8 bytes, an entity the file never declared, or a root tag that does not start
within the first 64 KB. The detail is libxml2's own message with the line number. A browser
would render the file up to the error and stop, so nothing after it can be trusted.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <rect width="10" height="10">
</svg>
<!-- rejected: malformed-xml, detail "Opening and ending tag mismatch: rect line 2 and svg (line 3)" -->
```

**Fix:** the libxml2 message names the line. A file straight from a design tool is always
well-formed; this usually means it was edited by hand or truncated in transfer.

One consequence of streaming: libxml2 parses in chunks of a few hundred bytes and throws
away the chunk containing the error, so a small malformed file reports only `malformed-xml`.
A larger file reports the problems found in earlier chunks first, then `malformed-xml` last.

### DOCTYPE With Entity Declarations - `doctype-not-allowed`

A `<!DOCTYPE>` is fine on its own. One with an internal subset, the `[ ... ]` part where
entities are declared, is not. Entities can expand to any text at all, including markup
that no other rule sees, and a few kilobytes of nested entities expand to gigabytes (the
"billion laughs" file). A DOCTYPE with only a public and system id is accepted, and nothing
is fetched for it.

```xml
<!DOCTYPE svg [ <!ENTITY logo "<script>alert(1)</script>"> ]>
<svg xmlns="http://www.w3.org/2000/svg">&logo;</svg>
<!-- rejected: doctype-not-allowed, detail "it contains an internal DTD subset (entity declarations)" -->
```

**Fix:** re-export without the DOCTYPE. The one design tool that emits this is Adobe
Illustrator with "Preserve Illustrator Editing Capabilities" checked; uncheck it. Chrome
expands these entities in `<img>` mode, so this rule is stricter than Chrome.

### Processing Instructions - `processing-instruction`

Any `<?target ...?>` in the file. The common one, `<?xml-stylesheet?>`, attaches a
stylesheet; even a same-file `href="#style"` is a way to run CSS the `<style>` rules never
saw. The `<?xml ...?>` declaration at the top is not a processing instruction and is fine,
and so is `<?xpacket ...?>`, the pair of markers Adobe tools put around their XMP metadata
block, which nothing reads (see [What Gets Through](what-gets-through.md#inert-processing-instructions)).

```xml
<?xml-stylesheet type="text/css" href="theme.css"?>
<svg xmlns="http://www.w3.org/2000/svg"/>
<!-- rejected: processing-instruction, detail "xml-stylesheet" -->
```

**Fix:** move the CSS into a `<style>` element inside the file, or drop it. Chrome processes
`xml-stylesheet` in `<img>` mode, so this rule is stricter than Chrome.

### Comments That HTML Would Close Early - `comment-not-allowed`

A comment written `<!-->` or `<!--->`. To the XML parser, everything up to the next `-->`
is comment text. To an HTML parser, the comment ends right there, and what follows is live
markup. If a file like that is ever served as `text/html`, the "comment" runs. Every other
comment is fine.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <!--> to an HTML parser this comment is already closed <img src=x onerror=alert(1)> -->
</svg>
<!-- rejected: comment-not-allowed, detail ">" -->
```

**Fix:** put a space or any text after `<!--`.

## The Root Element

### The Root Is Not `<svg>` - `root-not-svg`

The first element must be `<svg>`. An HTML file with an SVG inside it, or any other XML
document, is rejected here and the check stops. The detail is the root element's name.

```xml
<html xmlns="http://www.w3.org/1999/xhtml">
  <body><svg xmlns="http://www.w3.org/2000/svg"/></body>
</html>
<!-- rejected: root-not-svg, detail "html" -->
```

**Fix:** upload the SVG file itself, not a page containing it.

### The Root Has No SVG Namespace - `root-namespace-wrong`

The `<svg>` root must declare `xmlns="http://www.w3.org/2000/svg"`. Without it, a browser
sees an unknown XML element and renders nothing, so the file was never going to display. A
prefixed root (`<svg:svg xmlns:svg="...">`) is fine. The detail is `none`, or the
`xmlns="..."` that was found.

```xml
<svg width="10" height="10">
  <rect width="10" height="10"/>
</svg>
<!-- rejected: root-namespace-wrong, detail "none" -->
```

**Fix:** add `xmlns="http://www.w3.org/2000/svg"` to the root element. Files from design
tools always have it; hand-written and copied snippets often do not.

## Elements

### Element Not on the Allowlist - `element-not-allowed`

An element in the SVG namespace that is not in the
[elements list](what-gets-through.md#elements), or an element with no namespace at all (an
undefined prefix, or inside `xmlns=""`). The rejected element's own attributes are not
reported; its children still are.

Never on the list, on purpose: `<script>` (runs code), `<foreignObject>` (holds HTML, which
can hold anything), `<handler>` and `<listener>` (script by another name), `<iframe>` and
`<embed>` (load documents), `<tref>` (copies text from any element by reference, and was
removed from SVG 2), `<font>` and the SVG font elements (dead in every browser),
`<animateColor>` and `<discard>` (dead too). Chrome parses `<script>` and `<foreignObject>`
in `<img>` mode and neutralizes them at render time; this rule is stricter than Chrome.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <script>alert(1)</script>
</svg>
<!-- rejected: element-not-allowed, detail "script" -->
```

**Fix:** remove the element. If a design tool put it there (Inkscape's text tool used to
emit `<flowRoot>`), re-export with a newer version or convert the text to a path.

### Element From Another Namespace - `namespace-not-allowed`

An element whose namespace is not SVG and not one of the design-tool namespaces the
library ignores (see [inert namespaces](what-gets-through.md#inert-namespaces)). XHTML,
MathML, XInclude, XML Events and anything unknown are rejected with the namespace URI as
the detail. Those namespaces are where the historical SVG attacks were.

```xml
<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml">
  <h:iframe src="https://example.com/"/>
</svg>
<!-- rejected: namespace-not-allowed, detail "http://www.w3.org/1999/xhtml" -->
```

**Fix:** remove the element. Inkscape, Illustrator, Affinity Designer, Sketch and Visio
metadata is already ignored and never triggers this.

## Attributes

### Event Handlers - `event-handler`

Any attribute whose name starts with `on`, in any letter case, in any namespace, on any
element: `onload`, `onclick`, `onbegin`, `xlink:onload`. These hold script. No allowed
attribute starts with `on`, so the check is a plain prefix test. Chrome ignores them in
`<img>` mode and runs them when the file is opened directly; this rule is stricter than
Chrome.

```xml
<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">
  <rect width="10" height="10"/>
</svg>
<!-- rejected: event-handler, detail "onload" -->
```

**Fix:** remove the attribute. Design tools never emit one.

### Attribute Not on the Allowlist - `attribute-not-allowed`

An attribute on an SVG element that is not in the
[attributes list](what-gets-through.md#attributes), not `data-*` or `aria-*`, and not one
of the four allowed prefixed names (`xml:lang`, `xml:space`, `xlink:href`, `xlink:title`).
The detail is the name as written.

Never on the list, on purpose: `tabindex` (makes an element focusable, which is interaction),
`target` (where a link opens), `crossorigin` (fetch behavior), `cursor` (loads a cursor
file), `xml:base` (changes what every relative URL means), `xlink:show` and `xlink:actuate`
(open links automatically), `xml:id` (browsers ignore it, but Batik treats it as an id, and
the reference-expansion check counts only `id`).

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <a href="#home" target="_top"><rect width="10" height="10"/></a>
</svg>
<!-- rejected: attribute-not-allowed, detail "target" -->
```

**Fix:** remove the attribute. If a current design tool emits an attribute this rejects,
open an issue with the file; the list grows when a real export needs it.

## Links and Images

### External or Script Links - `href-not-allowed`

On every element except `<image>` and `<feImage>`, an `href` or `xlink:href` must be a
same-file reference (`#id`). That covers `<use>` (renders a copy of another element),
`<a>` (a link), `<textPath>`, `<mpath>`, gradients, patterns and filters. `javascript:`
URLs, `data:` URLs, other files and web addresses are all rejected, and so is an empty
`href`. Leading and trailing whitespace is trimmed first. Nothing checks that the `#id`
exists.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <use href="https://example.com/icons.svg#logo"/>
</svg>
<!-- rejected: href-not-allowed, detail "https://example.com/icons.svg#logo" -->
```

**Fix:** copy the referenced element into this file and point at it with `#id`. For `<a>`,
remove the link: Chrome makes it inert in `<img>` mode, but opened directly it is a
clickable link to anywhere from a file that looks like your logo, so this rule is stricter
than Chrome.

### Images That Are Not Embedded - `image-href-not-allowed`

On `<image>` and `<feImage>`, the `href` must be a same-file reference (`#id`) or an
embedded image: a `data:` URL of type `image/png`, `image/jpeg`, `image/jpg`, `image/gif`,
`image/webp` or `image/svg+xml`, base64-encoded. A web address, a relative file name, or a
`data:` URL of any other type or without `;base64` is rejected.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <image href="https://example.com/photo.jpg" width="10" height="10"/>
</svg>
<!-- rejected: image-href-not-allowed, detail "https://example.com/photo.jpg" -->
```

**Fix:** embed the image. Every design tool has an "embed images" option on export;
Illustrator's is "Image Location: Embed". This is the same rule Chrome applies: in `<img>`
mode, an SVG cannot load any image but a `data:` one.

### Embedded SVG That Fails a Rule - `embedded-svg-not-allowed`

An `<image>` whose `data:image/svg+xml` URL decodes to an SVG that breaks any rule on this
page. The inner file gets the whole check, and each of its problems is reported with the
inner message as the detail. Two more details: `the data: URL is not valid base64`, and
`SVG images nested more than 3 levels deep`.

Browsers render an SVG inside `<image>` in their secure mode, so this rule is not needed
for them. Server-side rasterizers (ImageMagick, librsvg, and the thumbnailers built on them)
give the inner file no such protection, and an uploaded file is likely to meet one.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <image href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxzY3JpcHQ+YWxlcnQoMSk8L3NjcmlwdD48L3N2Zz4=" width="10" height="10"/>
</svg>
<!-- rejected: embedded-svg-not-allowed, detail "<script> is not allowed in uploaded SVGs" -->
```

**Fix:** fix the inner SVG, or embed a PNG instead.

### `url()` That Is Not a Same-File Reference - `url-not-fragment`

Attributes like `fill`, `stroke`, `filter`, `mask`, `clip-path` and `marker-start` point at
other elements with `url(#id)`. Every attribute value except `href` and `style` is scanned
for a `url(` that does not point at `#`. The detail is the attribute name. (The `style`
attribute is checked by the CSS rule below, which reports `css-not-allowed` instead.)

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <rect width="10" height="10" fill="url(https://example.com/pattern.svg#dots)"/>
</svg>
<!-- rejected: url-not-fragment, detail "fill" -->
```

**Fix:** define the gradient, pattern or filter in this file and reference it with
`url(#id)`. Chrome silently refuses to load the external one in `<img>` mode; this rule
rejects instead, so the file renders the same everywhere.

A backslash in any of these attributes rejects with `css-not-allowed` and `\` as the detail,
the same as in CSS: presentation attributes take CSS escapes, so `fill="u\72l(...)"` is
`url(...)` to a browser. Only `data-*` and `aria-*` values may contain one.

## CSS

### CSS That Loads, Imports or Escapes - `css-not-allowed`

Applies to every `<style>` element and every `style` attribute. Nine tokens reject on
sight, with the token as the detail: a backslash `\` (CSS escapes, which could spell any of
the others in a form a scanner would miss), `@import`, `@charset`, `image(`, `image-set(`,
`src(`, `expression(`, `-moz-binding`, and `behavior:`. And any `url()` rejects unless it
is a same-file reference (`#id`) or an embedded font (`data:font/...` or `data:;base64,...`).

Comments are not stripped first, so a banned token inside `/* */` still rejects: a string
containing `/*` can fake a comment opener and hide a token behind it. Selectors,
properties, `@media`, `@keyframes`, `:hover` and `!important` are not checked.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <style>@import "https://fonts.example.com/roboto.css";</style>
</svg>
<!-- rejected: css-not-allowed, detail "@import" -->
```

**Fix:** embed the font as a `data:font/woff2;base64,` URL inside `@font-face`, convert the
text to outlines, or remove the rule.
[Troubleshooting](troubleshooting.md#css-containing-import-is-not-allowed) has the export
setting for each design tool.

## Animation

SMIL animation (`<animate>`, `<set>`, `<animateTransform>`, `<animateMotion>`) can change
any attribute over time, which would let a file change an `href` or a `style` after every
rule above has run. Two rules close that.

### Animating a Checked Attribute - `animation-target-not-allowed`

The `attributeName` of an animation element may not be `href`, `style`, `class`, or
anything starting with `on`. The name is matched after any prefix, so `xlink:href` and
`q:href` are both `href`. Everything else (`fill`, `opacity`, `d`, `transform`, `x`...)
animates freely.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <use href="#a"><set attributeName="href" to="#b" begin="1s"/></use>
</svg>
<!-- rejected: animation-target-not-allowed, detail "href" -->
```

**Fix:** animate a presentation attribute instead, or switch between two elements with
`visibility`.

### Animation Values With a URL - `animation-value-not-allowed`

The `from`, `to`, `by` and `values` attributes may not contain an item that starts with a
URL scheme (`javascript:`, `data:`, `https:`) or with `//`, which browsers read as a URL on
the page's own scheme. Tab and newline characters inside an item are ignored, as browsers
ignore them in a URL, so `java&#x09;script:` still counts. Items in `values` are separated
by `;`. The detail is the attribute name.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <rect width="10" height="10">
    <animate attributeName="fill" values="red;javascript:alert(1)" dur="1s"/>
  </rect>
</svg>
<!-- rejected: animation-value-not-allowed, detail "values" -->
```

**Fix:** animation values are colors, numbers, lengths and paths; there is no legitimate
scheme in one.

## References

### Reference Loops and Expansion Bombs - `reference-expansion-too-large`

A `<use>` renders a copy of the element it points at, and that copy can contain more `<use>`
elements. A pattern used as a `fill` renders its content, and that content can be filled
with the next pattern. Ten levels of ten references each is a hundred-line file that renders
ten billion elements; a reference that leads back to itself never finishes. Browsers cap
the nesting and break the loops; Inkscape, ImageMagick and librsvg have all hung or crashed
on files like this.

After the whole file is read, every reference that renders its target is followed:
`url(#id)` in any attribute including `style`, and `href` on `<use>`, `<pattern>`,
`<linearGradient>`, `<radialGradient>`, `<filter>` and `<feImage>`. A loop rejects with the
path as the detail; more than 100,000 rendered elements rejects with
`expand to more than 100,000 elements`. Content inside `<defs>`, `<symbol>`, `<pattern>`,
`<mask>`, `<marker>`, `<clipPath>` and `<filter>` counts only when something references it,
so a loop in a `<symbol>` nothing uses is accepted, the same as in a renderer.

The bookkeeping for this check is capped as well, so a file cannot exhaust the check
instead of the renderer. Each reference is recorded once per element with an `id` around
it, and the same target inside the same `id` is one record. A file that needs more than
100,000 records, for example 500 references to different ids inside 250 nested groups that
all carry an `id`, rejects with
`point at more than 100,000 distinct ids, counting each once per id it is nested in`.

```xml
<svg xmlns="http://www.w3.org/2000/svg">
  <defs>
    <clipPath id="a" clip-path="url(#b)"><rect width="10" height="10"/></clipPath>
    <clipPath id="b" clip-path="url(#a)"><rect width="10" height="10"/></clipPath>
  </defs>
  <rect width="10" height="10" clip-path="url(#a)"/>
</svg>
<!-- rejected: reference-expansion-too-large, detail "form a loop (#a -> #b -> #a)" -->
```

**Fix:** the detail names the loop; break it. For either count, a real illustration never
needs a hundred thousand rendered elements or a hundred thousand distinct references;
flatten the repeated art in the design tool. One gap to know about: references made
through CSS selectors (`.a { fill: url(#p) }` in a `<style>` element) are not followed,
because that would need a selector engine.

## What Is Not Checked

A validator is only useful when its limits are known, so here they are:

- **Whether a `#id` target exists.** A dangling reference renders nothing and harms nothing.
- **The file name, extension, MIME type or size.** Check those at upload time; the
  [Security Model](security-model.md) page says what to refuse.
- **CSS selectors, properties and values** other than the tokens above.
- **What the image looks like.** Blank, huge, or the wrong logo all pass.
- **`:hover`, `:visited` and `begin="click"`.** Chrome makes them do nothing in `<img>`
  mode; opened directly they work. Nothing loads and nothing runs either way, so they are
  accepted. This is the one place the rules are looser than Chrome.

---

[← Getting Started](getting-started.md) | [Documentation Index](README.md) | [Next: What Gets Through →](what-gets-through.md)
