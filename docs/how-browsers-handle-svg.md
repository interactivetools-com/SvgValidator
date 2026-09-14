# How Browsers Handle SVG

What a browser does with an SVG file shown through an `<img>` tag, where that behavior is
written down, how the three engines differ, and how SvgValidator's rules relate to it: where
they mirror Chrome, where they are stricter, and where they are looser. The short answers
come first; the rest is for anyone who wants to check the rules against the sources.

Contents:

- [Short Answers](#short-answers)
- [What the Specs Say](#what-the-specs-say)
- [What Chrome Does](#what-chrome-does)
- [Firefox and Safari](#firefox-and-safari)
- [How the Rules Compare](#how-the-rules-compare)
- [Email Clients](#email-clients)
- [Sources](#sources)

## Short Answers

**Does the browser filter the SVG?** No. Chrome parses the whole file, `<script>`, `onload`
and `javascript:` included, into a real document, then turns capabilities off at the page
level. Nothing is removed. That is why a file that renders safely in `<img>` can still be
dangerous when opened directly: the dangerous parts are all still there.

**What does it turn off?** Script, plugins, interaction, and every load whose URL is not
`data:`. Animation still runs. `<foreignObject>` still renders, without script.

**Is there a written test plan for this?** No. The tests are the plan: web-platform-tests
under `svg/embedded/` and `svg/as-image/`, plus Chromium's, Firefox's and WebKit's own
`svg/as-image/` directories. SvgValidator's corpus tools download all of them.

**Do email clients render SVG?** Gmail does not, in any form. Outlook on the web stopped
in 2025. Nothing in email will exercise these rules.

## What the Specs Say

Two specifications share the rule, and neither states it in full.

The **HTML Living Standard** has one paragraph, in the image processing model:

> User agents must not run executable code (e.g. scripts) embedded in the image resource.
> ... User agents must not allow the resource to act in an interactive fashion, but should
> honour any animation in the resource.

No script and no interaction are "must not"; animation is "should". HTML says nothing about
loading resources from inside an SVG image.

**SVG 2** defines the processing modes, in its conformance chapter:

| Mode                | Script | External references | Declarative animation | Interactivity |
|---------------------|--------|---------------------|-----------------------|---------------|
| dynamic interactive | yes    | yes                 | yes                   | yes           |
| secure animated     | no     | no                  | yes                   | no            |
| secure static       | no     | no                  | no                    | no            |

Secure animated mode is required for SVG's own `<image>` element, and "expected" for HTML's
`<img>`: "The same processing modes are expected to be used for other cases where SVG is
used in place of a raster image, such as an HTML 'img' element". Expected, not must. CSS
Images Level 4 does make it a requirement for SVG used as a CSS image (secure static, or
secure animated if the browser animates images).

Two definitions in SVG 2 are behind the allowlists:

- **What counts as external.** External references are network access "except for:
  same-document URL references ... [and] data URL references". So `href="#gradient"`,
  `url(#filter)` and `<image href="data:image/png;base64,...">` are inside secure animated
  mode, and the rules allow all three.
- **Interaction off does not mean markup removed.** "Any user input events that would be
  targetted at the document ... must have no effect." An `<a>` element and a
  `begin="click"` stay in the file; they just do nothing.

And `<foreignObject>` is explicitly permitted: "any content within a 'foreignObject' element
must have scripts, interactivity, and external file references disabled, but should have
declarative animation enabled."

## What Chrome Does

Chrome's implementation is a render-time restriction, not a filter. The SVG is loaded into
an isolated document with two settings turned off, script and plugins, and every other
setting inherited from a normal page. Fonts, CSS, filters, masks, gradients and layout all
work as in a document.

- **Script.** A `<script>` element stays in the DOM and is never run. An `onload`
  attribute stays as text and compiles to no listener. SMIL events are not dispatched.
- **Loads.** One check in the fetch path blocks every request from an SVG image whose URL
  is not `data:`. It looks only at the scheme, never at the resource type, so `<image>`,
  external `<use>`, `<feImage>`, CSS `url()` for fonts and backgrounds, `@import`, and
  anything an element inside `<foreignObject>` tries to fetch are all blocked the same way.
  Same-origin URLs are blocked. Cached bytes are blocked. Blocked loads fail silently, with
  nothing in the developer console.
- **Relative URLs never resolve.** The document has no base URL, so `href="photo.jpg"`
  produces no request at all.
- **`<use>` is same-file only.** Since Chrome 120, `<use href="data:...">` is rejected as
  well; Firefox 122 followed, and Safari never supported it.
- **Interaction.** The isolated page has no widget and no navigation, so link activation,
  `javascript:` hrefs, `:hover`, `:focus` and `cursor` never apply. Neither `:link`
  nor `:visited` matches.
- **`<foreignObject>` renders**, and any `<iframe>`, `<object>`, `<embed>` or media inside it
  loads nothing. Drawing such an image to a canvas taints the canvas.
- **Animation runs**, driven by the image's own 60 Hz timer. CSS animation runs by a
  separate path. Only SVG used as a CSS image gets animation disabled.
- **One remaining case:** an external `<?xml-stylesheet?>` is blocked, but a same-document one
  (`href="#id"`) is still processed and can transform the document. SvgValidator rejects
  every processing instruction for this reason, except Adobe's inert `xpacket` XMP markers.

Chrome added this in June 2014 to match Firefox, which had it first.

## Firefox and Safari

The three engines agree on everything an allowlist cares about. The differences:

| Behavior                          | Chrome                      | Firefox                           | Safari          |
|-----------------------------------|-----------------------------|-----------------------------------|-----------------|
| `data:` loads inside the SVG      | allowed                     | allowed                           | allowed         |
| `blob:` loads inside the SVG      | blocked                     | allowed                           | blocked         |
| Relative URLs                     | never resolve (no base URL) | resolve, then the load is blocked | never resolve   |
| `<foreignObject>` renders         | yes                         | yes                               | yes             |
| `<iframe>` or `<embed>` inside it | no                          | no                                | no              |
| `:visited` matches                | never                       | never                             | never           |
| `<use href="data:...">`           | removed in Chrome 120       | removed in Firefox 122            | never supported |

The one place they differ, `blob:` URLs, does not matter here: the rules reject every URL
scheme except `#id` and the embedded `data:` forms, so a `blob:` reference never reaches a
browser.

## How the Rules Compare

The rules mirror Chrome's `<img>` mode wherever the file would render the same either way,
and depart from it in two directions.

**Mirrored.** Same-file references and embedded `data:` images are allowed because the spec
says they are not external. Animation is allowed because the spec says it should run.
External loads of every kind are refused because Chrome refuses them, in the same
scheme-only way: the rules look at the URL, not at which element carries it.

**Stricter than Chrome.** Chrome's protection is render-time only, so it vanishes when the
same file is opened directly, saved and reopened, or drawn by a server-side rasterizer.
Wherever Chrome neutralizes something instead of refusing it, the rules refuse it:

| Chrome in `<img>`                         | Same file opened directly         | Rule                                  |
|-------------------------------------------|-----------------------------------|---------------------------------------|
| `<script>` parsed, never runs             | runs                              | `element-not-allowed`                 |
| `on*` attributes compile to nothing       | fire                              | `event-handler`                       |
| `javascript:` href is inert               | navigates                         | `href-not-allowed`                    |
| `<a href="https://...">` is inert         | clickable                         | `href-not-allowed`                    |
| external `url()` silently blocked         | loads from that server            | `url-not-fragment`, `css-not-allowed` |
| `<?xml-stylesheet href="#id"?>` processed | processed                         | `processing-instruction`              |
| `<foreignObject>` renders, no script      | scripts inside run                | `element-not-allowed`                 |
| DTD entities expand                       | expand                            | `doctype-not-allowed`                 |
| reference loops broken by the renderer    | hang or crash other renderers     | `reference-expansion-too-large`       |
| `<!-->` is a comment to the XML parser    | an HTML parser closes it early    | `comment-not-allowed`                 |
| SVG in `<image>` rendered in secure mode  | rasterizers give it no protection | checked with every rule               |

**Looser than Chrome, or not mirrored.** `:hover`, `:visited` and `:focus` in CSS and
`begin="click"` in SMIL are accepted. Chrome makes them do nothing in `<img>`; opened
directly they work. Nothing loads and nothing runs when they fire, so the file stays safe;
it just renders differently in the two contexts. On the other side, `<animateColor>` and
`<discard>` are rejected as unknown elements although Chrome would tolerate them: both are
dead in every current browser and no design tool exports them.

## Email Clients

Nothing in email renders an SVG the way a browser does. Gmail's HTML sanitizer drops inline
`<svg>`, its image proxy will not serve an SVG referenced by `<img>`, and `.svg`
attachments are accepted but not shown inline. Outlook on the web and the new Outlook for
Windows stopped rendering inline SVG in 2025, citing XSS. Apple Mail, Thunderbird and
ProtonMail render inline SVG; Yahoo and AOL do not. For anything sent by email, send a PNG.

## Sources

Verified against the cited text or source file in September 2026.

- HTML Living Standard, image processing model:
  https://html.spec.whatwg.org/multipage/images.html#images-processing-model
- SVG 2, conformance chapter (processing modes, external references, `foreignObject`):
  https://www.w3.org/TR/SVG2/conform.html
- CSS Images Level 4, image file formats: https://www.w3.org/TR/css-images-4/#image-file-formats
- Chromium: `third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc`
  (the isolated document and its two disabled settings) and
  `third_party/blink/renderer/core/loader/base_fetch_context.cc` (the `data:`-only fetch rule)
- Chromium, `<use>` and `data:` URLs: https://developer.chrome.com/blog/migrate-way-from-data-urls-in-svg-use
- Firefox: `dom/base/nsDataDocumentContentPolicy.cpp`; Mozilla bug 628747
- WebKit: `Source/WebCore/loader/cache/CachedResourceLoader.cpp`
- web-platform-tests: https://github.com/web-platform-tests/wpt/tree/master/svg/embedded
- Email support data: https://www.caniemail.com/features/html-svg/ and
  https://www.caniemail.com/features/image-svg/
- Microsoft message center MC1130385 (Outlook inline SVG removal, 2025)
- Google, "Securely hosting user data": https://web.dev/articles/securely-hosting-user-data

---

[← Security Model](security-model.md) | [Documentation Index](README.md) | [Next: Method Reference →](method-reference.md)
