# Troubleshooting

The rejection messages that come up with real files from real design tools, what each one
means, and the fix. Headings quote the message, with the detail filled in the way it usually
appears, so you can find them by search. For the complete list of rules see
[What Gets Rejected](what-gets-rejected.md).

Contents:

- [Messages From Design-Tool Exports](#messages-from-design-tool-exports)
- [Messages From Hand-Edited Files](#messages-from-hand-edited-files)
- [Gotchas](#gotchas)

## Messages From Design-Tool Exports

These are files a design tool produced. The tool has an export option that makes the file
pass; nothing in the artwork has to change.

### "The DOCTYPE declaration is not allowed because it contains an internal DTD subset (entity declarations)"

**What happened:** Adobe Illustrator was asked to keep its editing data in the file, and it
stores that data behind entity declarations in the DOCTYPE. Entities can expand to anything,
so a DOCTYPE that declares them is refused.

**Fix:** in Illustrator's SVG Options, uncheck "Preserve Illustrator Editing Capabilities"
and export again. The file also gets much smaller. Keep the `.ai` file as the editable copy.

### "Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not photo.jpg"

**What happened:** the file contains a placed raster image and the export linked to it by
file name instead of embedding it. The detail is the file name or URL. A browser showing
the SVG in an `<img>` tag could not load it either, so the picture would have been missing.

**Fix:** export with images embedded. Illustrator: "Image Location: Embed". Inkscape:
"Embed images" in the Save As dialog, or select the image and use Extensions → Images →
Embed Images. Affinity Designer: "Embed images" under More on the export panel. Figma
always embeds.

### "CSS containing @import is not allowed"

**What happened:** the file loads a web font with `@import` inside its `<style>` element.
Nothing outside the file may be loaded, and a browser would not load it in `<img>` mode
anyway, so the text would have fallen back to a default font.

**Fix:** embed the font or convert the text to outlines. Illustrator: "Fonts: SVG" with
subsetting, or Type → Create Outlines before export. Figma: "Outline Text" on export.
Inkscape: Path → Object to Path.

### "CSS containing url(https://fonts.example.com/roboto.woff2 is not allowed"

**What happened:** the same as above, with `@font-face { src: url(...) }` pointing at a
web address instead of `@import`. The detail shows up to 40 characters after `url(`, which
is why the closing parenthesis is missing.

**Fix:** the same: embed the font (`src: url(data:font/woff2;base64,...)` passes) or
convert the text to outlines.

### "<flowRoot> is not allowed in uploaded SVGs"

**What happened:** older Inkscape versions saved text drawn with a dragged text box as
`<flowRoot>`, an element from a draft of SVG 1.2 that no browser implements. The text
would have been invisible in every browser.

**Fix:** in Inkscape, select the text and use Text → Convert to Text (Inkscape 1.0+), or
Path → Object to Path, then save again.

### "<foreignObject> is not allowed in uploaded SVGs"

**What happened:** the file holds HTML inside the SVG. Diagram tools (draw.io, Excalidraw
in some modes) export formatted text labels this way, because HTML wraps text and SVG does
not. HTML inside an SVG can hold anything HTML can, so the element is refused as a whole.

**Fix:** export with text as plain SVG text or as paths. In draw.io, set the labels to plain
text (not formatted text) before exporting, or export as PNG if the diagram is only for
display.

### "href must reference an element in the same file (#id), not https://example.com/"

**What happened:** an `<a>` element wraps part of the artwork and links to a website. Files
saved from a web page or from a tool with a "link" feature have these. Chrome makes the link
inert inside an `<img>`; opened directly it is a clickable link, so it is refused.

**Fix:** remove the link from the artwork. If the image needs to be clickable, wrap the
`<img>` tag in the page with the link instead.

## Messages From Hand-Edited Files

These come from files that were written or edited in a text editor, or copied from a web page.

### "The root <svg> element must declare xmlns=\"http://www.w3.org/2000/svg\", but it has none"

**What happened:** the root element is `<svg>` but has no `xmlns` attribute. Inside an HTML
page a browser fills that in; as a file on its own it is an unknown XML element and renders
as nothing. Snippets copied out of a page and saved as `.svg` are the usual source.

**Fix:** add `xmlns="http://www.w3.org/2000/svg"` to the root element.

### "The SVG is not well-formed XML: Entity 'nbsp' not defined (line 12)"

**What happened:** the file uses an HTML entity like `&nbsp;` or `&copy;`. XML only knows
five (`&amp;`, `&lt;`, `&gt;`, `&quot;`, `&apos;`); every other name needs a DTD, and DTDs
with declarations are refused. A browser would stop rendering at that line.

**Fix:** use the numeric form: `&#160;` for a non-breaking space, `&#169;` for the
copyright sign, or type the character itself, since the file is UTF-8.

### "The SVG is not well-formed XML: Opening and ending tag mismatch: g line 8 and svg (line 40)"

**What happened:** a tag was opened and never closed, usually after a hand edit or a
truncated transfer. The message names the tag and the line.

**Fix:** close the tag, or re-export the file from the design tool.

### "This is not an SVG file: it starts with \211PNG"

**What happened:** a PNG (or a PDF, `%PDF`, or a ZIP, `PK`) was renamed to `.svg`. The
detail shows the file's first bytes, with unprintable ones escaped.

**Fix:** export as SVG from the design tool. Renaming does not convert the file. If the
source is a raster image, upload it as a PNG instead.

### "SVG files must be UTF-8, this one is UTF-16 or UTF-32"

**What happened:** the file was saved with a two- or four-byte encoding. Older Windows
Notepad did this when "Unicode" was picked in the Save dialog.

**Fix:** open the file and save it as UTF-8. Every design tool and code editor does this by
default.

## Gotchas

### Cannot read file php3F.tmp

The path passed to `checkFile()` was not a readable file. With `$_FILES`, the detail names
the temporary file. The usual causes: the upload failed (check `$_FILES['logo']['error']`
before calling), `move_uploaded_file()` ran first and the temporary file is gone, or
`$_FILES['logo']['name']` was passed instead of `$_FILES['logo']['tmp_name']`.

### A file reports only `malformed-xml`, but you can see other problems in it

libxml2 parses in chunks of a few hundred bytes and discards the chunk containing a
well-formedness error. In a small file that chunk is the whole file, so the parse error is
the only thing reported. Fix the XML and check again: the other problems will be reported
then. A larger file reports the problems found before the broken chunk, then `malformed-xml`
last.

### The same problem on five elements shows once

Errors are deduplicated by code and detail. Five `<script>` elements are one
`element-not-allowed` with the detail `script`; five different `on*` attributes are five
errors. The list stops at 50 distinct problems.

### An accepted file renders as nothing in the browser

Passing the check means the file cannot run script or load anything; it says nothing about
whether the drawing is visible. A root `<svg>` with no `width`, `height` or `viewBox`, a
drawing outside the `viewBox`, or a `fill` of the same color as the page all pass. Open the
file directly in a browser to see what it draws.

### The file passes here and shows a broken image in Chrome

Browsers only render an SVG in `<img>` when the server sends it as `image/svg+xml`. A
server that sends `text/plain` or `application/octet-stream` for `.svg` files shows a broken
image, whatever the file contains. Check the response headers; the
[Security Model](security-model.md#serving-accepted-files) page has the working
configuration.

---

[← Common Patterns](common-patterns.md) | [Documentation Index](README.md) | [Next: Security Model →](security-model.md)
