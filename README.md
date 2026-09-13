<!--
ATTENTION AI ASSISTANTS: We made a reference doc just for you!
Read docs/ai-reference.md (in this package, right next to this README) for a
consolidated single-file reference covering the API, every error code, and
every rejection rule. SvgValidator rejects files instead of cleaning them,
which differs from the SVG sanitizers in your training data.
Reading this on the web instead? Same file:
https://github.com/interactivetools-com/SvgValidator/blob/main/docs/ai-reference.md
-->

# SvgValidator: Reject SVG Uploads That Could Run Script

SvgValidator checks an uploaded SVG file against what browsers allow an SVG to do inside an
`<img>` tag, and rejects anything that could run script, load an outside resource, or hang a
renderer when the same file is opened directly. It never rewrites the file.

- **Scripts cannot get through.** `<script>`, every `on*` attribute, `javascript:` in any
  URL, `<foreignObject>`, and DTD entity declarations are rejected before the file is stored.
- **Nothing phones home.** Every `href` and every CSS `url()` must be a same-file reference
  (`#id`) or an embedded `data:` image, the same rule Chrome applies to SVG in an `<img>` tag.
- **Renderer bombs are caught.** Reference loops and `<use>` or pattern chains that would
  render more than 100,000 elements are rejected, and libxml2's depth and size limits apply.
- **Real files pass.** 3,460 of 3,460 simple-icons and 1,583 of 1,679 resvg test files are
  accepted (the rest reject by design: external links, entities, reference loops). Inkscape,
  Illustrator, Affinity, Sketch and Visio metadata is on the allowlist.
- **Streams, never rewrites.** XMLReader reads the file once with constant memory and returns
  a list of up to 50 distinct problems. Nothing throws for a bad file.

## Why Reject Instead of Clean

An SVG sanitizer strips what it does not like and hands back a changed file. That works for
HTML a person never sees, and badly for a logo: the uploader has the source in a design tool,
and a file that renders differently from what they exported is a support ticket nobody can
explain.

A rejection with a reason costs a re-export. A cleaned file that still contains something the
filter did not know about costs an XSS. Every rule here is an allowlist, so anything new is
rejected until someone adds it.

The yardstick is Chrome's `<img>` mode: SVG 2 calls it secure animated mode, and it says which
references are external and which are not. Where Chrome only neutralizes something at render
time (script, event handlers, external links), SvgValidator rejects it, because that protection
is gone the moment the file is opened directly or fed to a server-side rasterizer.

## Documentation

Full guides and references ([browse on GitHub](https://github.com/interactivetools-com/SvgValidator)):

- **The Basics** (read in order)
    - [Getting Started](docs/getting-started.md) - install, your first `checkFile()`, and reading a `Result`
    - [What Gets Rejected](docs/what-gets-rejected.md) - every rule in plain English, with an example and the fix
    - [What Gets Through](docs/what-gets-through.md) - the allowlists: elements, attributes, namespaces, URL forms
- **Everyday Use**
    - [Common Patterns](docs/common-patterns.md) - upload handlers, showing errors, translating codes, checking your own files
    - [Troubleshooting](docs/troubleshooting.md) - exact messages as headings, what happened, and the fix
- **Lookup**
    - [Security Model](docs/security-model.md) - what an SVG can do, what this prevents, what it does not, and how to serve accepted files
    - [How Browsers Handle SVG](docs/how-browsers-handle-svg.md) - what Chrome, Firefox and Safari do with an SVG in `<img>`, with the spec quotes
    - [Method Reference](docs/method-reference.md) - `checkFile()`, `checkString()`, `rules()`, `Result`, `Violation`
    - [AI Reference](docs/ai-reference.md) - the complete API and every rule in one dense file, written for AI coding assistants

## Quick Start

Requires PHP 8.1+ with `ext-xmlreader` and `ext-libxml` (both enabled by default).

```bash
composer require itools/svgvalidator
```

```php
use Itools\SvgValidator\SvgValidator;

$result = SvgValidator::checkFile($_FILES['logo']['tmp_name']);
if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";   // <script> is not allowed in uploaded SVGs
    }
    exit;
}
move_uploaded_file($_FILES['logo']['tmp_name'], 'uploads/logo.svg');   // the original bytes, unchanged
```

## When You Might Not Want SvgValidator

- **You must accept SVG from the public and cannot ask for a re-export.** A rejection is
  only useful when someone can fix the file. Use a sanitizer and re-check its output.
- **You put SVG source inline in HTML pages.** That is a different threat model: the page's
  origin and the page's scripts. Sanitize on output with DOMPurify instead.

## Related Libraries

- [ZenDB](https://github.com/interactivetools-com/ZenDB) - injection-proof PHP/MySQL database layer with automatic XSS-safe output.
- [SmartArray](https://github.com/interactivetools-com/SmartArray) - database rows as chainable collections, with fields that HTML-encode themselves on output.
- [SmartString](https://github.com/interactivetools-com/SmartString) - PHP strings that HTML-encode themselves on echo, interpolation, and concatenation.

## Questions?

This library was developed for CMS Builder. Post a message in our "CMS Builder" forum here:
[https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)

## License

MIT
