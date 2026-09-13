# SvgValidator Design Decisions

Settled decisions with their rationale. Check here before proposing a feature, a rule change,
or a rename: if it has a heading below, it was already debated. Decisions can be reopened, but
reopen them against the reasons recorded here, not from scratch.

Only the road-not-taken half lives here. Current behavior is self-documenting in the source,
the docblocks, the changelog, and the tests.

---

Contents:

- [Design Philosophy](#design-philosophy)
- [Reject, Never Rewrite - DECIDED (2026-09)](#reject-never-rewrite---decided-2026-09)
- [The Target: Chrome's `<img>` Mode, Stricter Where It Matters - DECIDED (2026-09)](#the-target-chromes-img-mode-stricter-where-it-matters---decided-2026-09)
- [Allowlists, Not Denylists - DECIDED (2026-09)](#allowlists-not-denylists---decided-2026-09)
- [Parser: XMLReader Streaming - DECIDED (2026-09)](#parser-xmlreader-streaming---decided-2026-09)
- [No Line Numbers - DECIDED (2026-09)](#no-line-numbers---decided-2026-09)
- [A List of Errors, Not the First One - DECIDED (2026-09)](#a-list-of-errors-not-the-first-one---decided-2026-09)
- [Three Classes, No Options - DECIDED (2026-09)](#three-classes-no-options---decided-2026-09)
- [Messages Report the Finding, Not the Fix - DECIDED (2026-09)](#messages-report-the-finding-not-the-fix---decided-2026-09)
- [DOCTYPE: Only the Internal Subset Rejects - DECIDED (2026-09)](#doctype-only-the-internal-subset-rejects---decided-2026-09)
- [External Links in `<a>` Reject - DECIDED (2026-09)](#external-links-in-a-reject---decided-2026-09)
- [CSS by Regex, Not a Tokenizer - DECIDED (2026-09)](#css-by-regex-not-a-tokenizer---decided-2026-09)
- [`url()` Is Checked in Every Attribute - DECIDED (2026-09)](#url-is-checked-in-every-attribute---decided-2026-09)
- [Embedded SVG Images Get the Full Check - DECIDED (2026-09)](#embedded-svg-images-get-the-full-check---decided-2026-09)
- [Reference Loops and Expansion Bombs - DECIDED (2026-09)](#reference-loops-and-expansion-bombs---decided-2026-09)
- [Design-Tool Namespaces Are Inert - DECIDED (2026-09)](#design-tool-namespaces-are-inert---decided-2026-09)
- [Out of Scope: `.svgz`, HTML Uploads, Editor Output - DECIDED (2026-09)](#out-of-scope-svgz-html-uploads-editor-output---decided-2026-09)
- [The Corpus Stays Out of the Repo - DECIDED (2026-09)](#the-corpus-stays-out-of-the-repo---decided-2026-09)
- [Naming - DECIDED (2026-09)](#naming---decided-2026-09)

## Design Philosophy

- **A validator, not a sanitizer.** It answers one question about a file and never changes
  the file. The uploader owns the file and can re-export it.
- **Allowlists.** Every element, attribute, namespace, URL form, CSS function and animation
  target has to be on a list to pass. Anything new is rejected until someone adds it.
- **Chrome's `<img>` mode is the yardstick.** A file passes when it stays inside what a
  browser lets an SVG do when it is shown as an image, and is stricter only where that
  protection is render-time and vanishes when the same file is opened directly or rasterized
  on a server.
- **Streaming and constant memory.** The file is read once, front to back, with a pull parser.
  Size and depth limits come from libxml2, not from us.
- **No configuration.** One rule set, so every install behaves the same and the docs can say
  exactly what gets through.
- **Small on purpose.** Three classes, no dependencies beyond ext-xmlreader and ext-libxml.
  Security code benefits from being short enough to read in one sitting.

---

## Reject, Never Rewrite - DECIDED (2026-09)

The library reports what is wrong and leaves the file alone. It does not strip the bad parts
and return a cleaned file, the way enshrined/svg-sanitize and DOMPurify do.

1. **The uploader can fix it.** An SVG upload comes from someone who has the source in a
   design tool and can re-export in two minutes. Strict rules cost them a re-export. Loose
   rules cost the site an XSS.
2. **A rewritten file is a support ticket.** A logo that renders differently from what was
   uploaded, because a filter or an animation attribute was silently removed, is something
   nobody can explain to the person who uploaded it.
3. **A rejection is easy to verify.** The output of a sanitizer has to be re-checked to
   know it is safe. A reject-or-accept answer has no output to get wrong.

The mirror image holds for input the author never sees, such as WYSIWYG editor output: there,
filtering and reporting what was removed is the right call, and it is not this library's job.

---

## The Target: Chrome's `<img>` Mode, Stricter Where It Matters - DECIDED (2026-09)

Browsers do not filter an SVG shown through `<img>`. They parse everything, then turn off
script, plugins, interaction and every subresource fetch that is not a `data:` URL, at render
time. SVG 2 calls this secure animated mode, and it is the model for the allowlists: fragment
references (`#id`) and `data:` images are allowed because the spec says they are not external
references; animation is allowed because the spec says it should run.

The render-time protection disappears the moment the same file is opened directly, saved and
reopened, or fed to a server-side rasterizer. So the rules are stricter than Chrome wherever
Chrome merely neutralizes something instead of refusing it:

| Chrome in `<img>`                          | Same file opened directly       | Rule                                       |
|--------------------------------------------|---------------------------------|--------------------------------------------|
| `<script>` parsed, never runs              | runs                            | `element-not-allowed`                      |
| `on*` attributes compile to nothing        | fire                            | `event-handler`                            |
| `javascript:` href is inert                | navigates                       | `href-not-allowed`                         |
| `<a href="https://...">` is inert          | clickable                       | `href-not-allowed`                         |
| external `url()` silently blocked          | loads, phones home              | `url-not-fragment`, `css-not-allowed`      |
| `<?xml-stylesheet href="#id"?>` processed  | processed                       | `processing-instruction`                   |
| `<foreignObject>` renders, no script       | scripts inside run              | `element-not-allowed`                      |
| DTD entities expand                        | expand                          | `doctype-not-allowed`                      |
| reference loops broken by the renderer     | hang or crash other renderers   | `reference-expansion-too-large`            |
| `<!-->` is a comment to the XML parser     | an HTML parser closes it early  | `comment-not-allowed`                      |

And the rules are looser than Chrome, or simply do not mirror it, in a few places where there
is no attack. These are worth stating plainly in the public docs, since "matches Chrome" is the
pitch:

- `:hover`, `:visited`, `:focus` in CSS and `begin="click"` in SMIL are accepted. Chrome makes
  them do nothing in `<img>`; opened directly they work. Nothing loads and nothing runs, so
  the file stays safe, it just renders differently in the two contexts.
- `animateColor` and `discard` are rejected as unknown elements. Chrome would render or ignore
  them. They are dead in every current browser and add nothing a design tool exports.
- `foreignObject` is rejected even though Chrome renders it. Keeping it would mean allowing
  most of HTML inside it and checking all of that too.

The one place the rules are stricter than a browser's own behaviour for no browser reason is
embedded SVG images, covered below.

---

## Allowlists, Not Denylists - DECIDED (2026-09)

Every list in the class is a list of what passes. A denylist of known-bad elements and
attributes fails the first time a browser adds a feature, and browsers add features every
release. An allowlist fails the other way: a new harmless feature rejects until someone adds
it, which costs a re-export and a one-line change, not an XSS.

The corpus tally (see below) is how the lists get calibrated: thousands of real icons and
design-tool exports have to pass, and the lists were grown until they did.

---

## Parser: XMLReader Streaming - DECIDED (2026-09)

The file is read with `XMLReader`, libxml2's pull parser, with `LIBXML_NONET` set and
`LOADDTD` and `SUBST_ENTITIES` off.

1. **Constant memory.** An 8 MB file costs the same memory as an 8 KB one, and the walk is
   about half a second for the 8 MB one.
2. **The prolog is seen before the content.** DOCTYPE and processing instructions come out of
   the reader before the root element, so the dangerous ones are found first. A byte-level
   check on the first 64 KB runs before the parser for the things the parser hides: the
   encoding declaration, a UTF-16 or UTF-32 BOM, a DOCTYPE with an internal subset, and a root
   tag that never arrives.
3. **libxml2's defaults are free denial-of-service limits.** Depth is capped at 256 and a
   single text node at 10 MB. `XML_PARSE_HUGE` is never passed, and switching parsers would
   silently remove these caps.
4. **Same parser family as the browsers' tests.** Chrome, Firefox and the sanitizers all sit
   on libxml2 or something that agrees with it on well-formedness.

Rejected: `DOMDocument` (parses the whole file before you see anything, so a 100 MB upload
is parsed in full before the first check), the `ext/xml` SAX API (callbacks make the walk
harder to read for no gain), and a hand-written tokenizer (a second XML parser is a second
set of parsing differences to get wrong).

One consequence worth knowing: libxml2 parses in chunks of a few hundred bytes, and a
well-formedness error throws away the chunk it is in. A small malformed file therefore reports
only `malformed-xml`; a larger one reports the errors found in earlier chunks too. The tests
pin both cases.

---

## No Line Numbers - DECIDED (2026-09)

`Violation` has a code and a detail, no line. `XMLReader` only exposes a line number through
`expand()`, which parses the whole subtree into a DOM and gives up the streaming guarantee.
The code plus the detail (the element name, the attribute name, the first 60 characters of
the URL) identify the problem well enough for someone who has the file open, and the person
who uploaded it will re-export rather than edit line 12.

---

## A List of Errors, Not the First One - DECIDED (2026-09)

`Result::$errors` holds every distinct problem found, up to 50, deduplicated by code and
detail. An early draft stopped at the first violation, since streaming never sees the rest of
the file anyway. It changed because a real file usually has several problems of the same kind
(one `onload` per element, one external image per icon), and reporting all of them lets the
uploader fix the file once instead of once per upload.

- **Dedupe by code and detail**, so five `<script>` elements are one error and five different
  event handlers are five.
- **Cap at 50**, so a hostile file cannot make the result itself large.
- **`malformed-xml` always ends the list.** After a parse error nothing else is trustworthy.
- **No exceptions for a failed file.** A hostile upload is expected input, not an error.
  `checkFile()` on a missing path returns a result with `file-unreadable`, same as any other
  rejection, so the caller has one code path.

---

## Three Classes, No Options - DECIDED (2026-09)

`SvgValidator`, `Result`, `Violation`. The first drafts had one class and an array-shaped
result. Typed readonly properties won because `$result->ok` and `$violation->code` are
checked by the IDE and static analysis, and an array is not. Nothing else gets a class.

No options in v1. Every install checks the same way, the docs can say exactly what passes, and
there is no "we turned that check off" support ticket. Add an option when a real caller needs
one, and record here why.

`rules()` exposes the allowlists as arrays for documentation and debugging. It is read-only;
the lists are constants.

---

## Messages Report the Finding, Not the Fix - DECIDED (2026-09)

`Violation::$message` names what was found ("`<script>` is not allowed in uploaded SVGs") and
stops. It does not add "re-export without editing data" or similar hints. The application
knows its users and its design tools; the library does not. `Violation::TEMPLATES` holds
every message as an `sprintf` template keyed by code, so an application can translate or
replace them and add hints of its own.

---

## DOCTYPE: Only the Internal Subset Rejects - DECIDED (2026-09)

A DOCTYPE is fine. A DOCTYPE with an internal subset (`[ ... ]`, where entities are declared)
is not. External DTD references are ignored because the parser runs with `LOADDTD` off and
`LIBXML_NONET` set, so nothing is fetched and no entity is defined; an entity reference in
such a file comes back as `malformed-xml`, not as an expansion.

Known false reject: Adobe Illustrator's "preserve Illustrator editing capabilities" export
puts its private data in entity declarations. Those files are large, carry no benefit for a
web image, and re-export without that option in one click.

An earlier draft rejected every DOCTYPE whose public id was not one of the five W3C SVG DTDs,
the MediaWiki rule. Dropped: the internal subset is the only part that can do anything, and
the corpus has real files with odd but harmless DOCTYPEs.

---

## External Links in `<a>` Reject - DECIDED (2026-09)

`<a href="https://...">` rejects with `href-not-allowed`, the same rule as every other href.
Chrome makes the link inert in `<img>`; opened directly it is a clickable link to anywhere,
which is a phishing surface in a file that renders as the site's own logo. MediaWiki allows
it. The corpus tally found external links in test files and almost none in real icons or
design-tool exports, so the cost of the strict rule is close to zero. Revisit with evidence
from real uploads.

---

## CSS by Regex, Not a Tokenizer - DECIDED (2026-09)

`<style>` text and `style=` values are checked with two regular expressions. MediaWiki runs a
real CSS tokenizer. The regex works because the first thing it bans is the backslash: with no
escape syntax, every CSS token reads exactly as written, and there is no way to spell
`url(` that a regex sees differently from a browser.

- **Comments are allowed but not stripped.** Their contents are scanned like everything
  else. Stripping them first is unsafe because `content: "/*"` inside a string can fake a
  comment opener and hide a token after it.
- **The font carve-out applies anywhere in the CSS**, not only inside `@font-face`.
  `url(data:font/...)` and `url(data:;base64,...)` pass wherever they appear. Finding the
  block would need the tokenizer this decision avoids, and a font data URL outside
  `@font-face` loads nothing.
- **What is banned:** the backslash, `@import`, `@charset`, `image(`, `image-set(`, `src(`,
  `expression(`, `-moz-binding`, `behavior:`, and every `url()` that is not `#id` or an
  embedded font.

---

## `url()` Is Checked in Every Attribute - DECIDED (2026-09)

The first draft checked `url(` only in the nine presentation attributes that take a paint
server or a reference (`fill`, `stroke`, `filter`, `mask`, `clip-path`, the four `marker`
attributes). It now runs on every attribute value. Cost is one regex per attribute, and it
closes every attribute that turns out to accept a URL later, including animation values.

---

## Embedded SVG Images Get the Full Check - DECIDED (2026-09)

`<image href="data:image/svg+xml;base64,...">` is decoded and validated as its own file, up
to three levels deep. Browsers do not need this: an SVG inside `<image>` renders in secure
mode in every engine, script and all. Server-side rasterizers (ImageMagick, librsvg, and the
thumbnailers built on them) give it no such protection, and an uploaded file is very likely to
meet one. The check costs one base64 decode and a recursive call.

A gzipped SVG (`.svgz` bytes) inside such a URL rejects as not an SVG file: it starts with the
gzip magic number, not with `<`.

---

## Reference Loops and Expansion Bombs - DECIDED (2026-09)

A `<use>` renders a copy of its target, and the copy can hold more `<use>` elements. Ten
levels of ten references each is a hundred-line file that renders ten billion elements. The
same is true of `fill="url(#pattern)"` when the pattern's content is filled with the next
pattern, and of `<feImage href="#id">`, markers, masks and clip paths. Browsers break the
loops and cap the nesting; other renderers hang or crash.

The rule follows every same-file reference that renders its target: `url(#id)` in any
attribute including `style=`, and `href` on `use`, `pattern`, `linearGradient`,
`radialGradient`, `filter` and `feImage`. It counts how many elements the references in the
file would render and rejects above 100,000, and it rejects any loop. One code,
`reference-expansion-too-large`, two details, since the fix is the same either way.

Decisions inside the rule:

- **`<a href="#id">` and the animation elements are not references.** Linking to an ancestor
  and animating an ancestor are both common and neither renders the target.
- **Content inside `defs`, `symbol`, `pattern`, `mask`, `marker`, `clipPath` and `filter`
  counts only when something references it**, the same as in a renderer. A loop in a
  `<symbol>` nothing uses is accepted, because it never renders.
- **References made through CSS selectors are not followed.** `<style>rect { fill: url(#p) }</style>`
  would need selector matching to know which rects are inside which pattern, and a
  design tool's `.st0 { fill: url(#gradient) }` is in nearly every Illustrator export. This
  is the known gap in the rule; a renderer's own cycle guard covers the loop case, and the
  fan-out case needs class selectors written for the purpose.
- **The count is iterative**, not recursive, because a reference chain can be longer than the
  PHP call stack (Xdebug stops at 512 frames), and a 2,000-deep chain of single references is
  legal.

---

## Design-Tool Namespaces Are Inert - DECIDED (2026-09)

Elements and attributes in the Inkscape, sodipodi, RDF, Dublin Core, Creative Commons, Adobe,
Serif, Sketch, Visio and XML Schema namespaces pass without inspection, except that an `on*`
attribute rejects in any namespace. Browsers ignore these vocabularies entirely, and every
design tool emits them. Adobe alone uses dozens of namespaces under `ns.adobe.com`, so the
match is by prefix. Any other namespace rejects: XHTML, XML Events, MathML, XInclude and the
unknown ones are exactly where the historical attacks live.

---

## Out of Scope: `.svgz`, HTML Uploads, Editor Output - DECIDED (2026-09)

- **`.svgz` (gzipped SVG)** is not checked. Browsers only render it when the server sends
  `Content-Encoding: gzip`, which upload folders do not, so the file is dead on arrival.
  Refuse the extension at upload time.
- **HTML files** are a different problem. A served `.html` upload is a same-origin document
  with the whole browser attack surface; no upload field needs one. Refuse the extension.
- **WYSIWYG editor output** should be filtered, not rejected, because the author never saw
  the HTML. Not this library.

The deciding question for each input type was whether the person can fix what they submitted.

---

## The Corpus Stays Out of the Repo - DECIDED (2026-09)

The allowlists were calibrated against about 5,000 files from sixteen public test suites and
icon sets, downloaded by `tools/fetch-corpus.php` into a gitignored folder and scored by
`tools/tally.php`. Nothing from the corpus is copied into `tests/`: some of the sets are GPL
or LGPL, and the rest would add thousands of files to every clone. The committed fixtures are
our own, one per error code plus one per accepted feature, written in our own words from the
ideas the corpus surfaced.

---

## Naming - DECIDED (2026-09)

`SvgValidator`, `itools/svgvalidator`, `Itools\SvgValidator`. The three sibling libraries set
the pattern: CamelCase folder, repo and namespace, all-lowercase Packagist name with no hyphen.

Rejected: `SVGValidator` (PSR naming treats acronyms as words, as in `XmlReader`);
`SafeSvg`, `SvgGuard`, `SvgRules` (hide that it rejects files); `SvgChecker` (fine, and the
runner-up, but "validate" is the verb upload code already uses for extension and MIME checks).

Error codes are lowercase, hyphenated, one per rule, and never renamed once released, because
applications map them to their own messages.
