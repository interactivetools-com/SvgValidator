# Browser Research Report

The research report SvgValidator's rules were built from, kept whole for its source
citations and Blink details. Written 2026-09-12, before the library existed, by seven
research agents plus one fact checker per finding, then hand-verified against Chromium
source. The public summary is [docs/how-browsers-handle-svg.md](../how-browsers-handle-svg.md).

Read section 7 with that date in mind: it recommends building a sanitizer on top of
enshrined/svg-sanitize, and the decision recorded in
[design-decisions.md](design-decisions.md) went the other way (reject, never rewrite). The
allow and deny lists in that section are the seed of the current allowlists, not the
current allowlists; `SvgValidator::rules()` is.

---

# SVG in `<img>`: what Chrome allows, and how to match it in a PHP sanitizer

Research notes, 2026-09-12. Every claim below was fact-checked against the cited
source. Refuted or corrected items are listed in section 8.

## 1. Short answer

- **Does Chrome filter the SVG?** No. Blink parses the whole file, `<script>`,
  `onload=`, `javascript:` and all, into a real Document, then turns off
  capabilities at the page level. Nothing is stripped.
- **What does it turn off?** Script, plugins, and every subresource fetch whose
  URL is not `data:`. SMIL and CSS animation still run. `foreignObject` still
  renders. Interaction is dead because the isolated page has no widget.
- **Is there a test plan?** No written plan exists. The tests are the plan:
  web-platform-tests under `svg/embedded/` and `svg/as-image/`, plus Chromium's
  own `web_tests/svg/as-image/` and `http/tests/security/`.
- **Gmail?** Gmail does not render SVG at all. Inline `<svg>` fails in all four
  Gmail clients, `<img src=x.svg>` fails because Google's image proxy will not
  serve SVG, and `.svg` attachments are allowed but not shown inline. Nothing in
  email will exercise your allow-list.

## 2. The standard

Two specs, split awkwardly.

**HTML Living Standard** is the only normative user-agent rule, and it is one
paragraph in the image processing model
(https://html.spec.whatwg.org/multipage/images.html#images-processing-model):

> User agents must not run executable code (e.g. scripts) embedded in the image
> resource. ... User agents must not allow the resource to act in an interactive
> fashion, but should honour any animation in the resource.

Note the split: no script and no interaction are `must not`; animation is only
`should`. HTML says nothing about external resource loads from inside an SVG
image. A search of the full single-page spec (15,593,584 bytes) finds zero hits
for "secure animated", "secure static", or "svg-integration".

HTML also has an authoring rule on `<img src>`
(https://html.spec.whatwg.org/multipage/embedded-content.html#the-img-element):

> If the src attribute is present, it must contain a valid non-empty URL ...
> referencing a non-interactive, optionally animated, image resource that is
> neither paged nor scripted. ... these definitions preclude SVG files with
> script

That binds the page author, not the browser.

**SVG 2** (W3C Candidate Recommendation, 4 October 2018) is where the processing
modes live: https://www.w3.org/TR/SVG2/conform.html. Three modes:

| Mode | script | external refs | declarative animation | interactivity |
|---|---|---|---|---|
| dynamic interactive | yes | yes | yes | yes |
| secure animated | no | no | yes | no |
| secure static | no | no | no | no |

The referencing-modes section makes secure mode a `must` for SVG's own `<image>`
element, then says of HTML:

> The same processing modes are expected to be used for other cases where SVG is
> used in place of a raster image, such as an HTML 'img' element or in any CSS
> property that takes an `<image>` data type.

"Expected", not "must". So no spec normatively requires Chrome's `<img>`
behaviour. It is inferred from HTML's must-not-script plus SVG 2's expectation.

Two details that matter for an allow-list:

- **Fragment and data: URLs are not "external".** SVG 2 defines external
  references as network access "except for: same-document URL references ...
  [and] data URL references". So `xlink:href="#gradient1"`, `url(#filter1)` and
  `<image href="data:image/png;base64,...">` are all inside secure animated
  mode. A sanitizer that strips them is stricter than the spec.
- **Interaction off does not mean markup removed.** "any user input events that
  would be targetted at the document ... must have no effect". The `<a>` element
  and `begin="click"` stay in the file, they just do nothing.

**foreignObject** is not forbidden. SVG 2:

> if an SVG document is being used in secure animated mode due to being
> referenced by an HTML 'img' or SVG 'image' element, then any content within a
> 'foreignObject' element must have scripts, interactivity, and external file
> references disabled, but should have declarative animation enabled.

**CSS.** CSS Images Level 4 (Working Draft, 30 Sep 2025) normatively requires
SVG referenced from an `<image>` value to use secure static mode, or secure
animated mode if the UA supports animated images
(https://www.w3.org/TR/css-images-4/#image-file-formats). CSS Images Level 3 has
no such text. CSS Basic User Interface Level 4 imposes the same rule on `cursor`.

## 3. How Chrome implements it

**Filter or render-time restriction? Render-time, entirely.**

`SVGImage::DataChanged` (`third_party/blink/renderer/core/svg/graphics/svg_image.cc`)
builds an `IsolatedSVGDocumentHost` with `ProcessingMode::kAnimated` and
`NullUrl()` as the base URL. That class
(`third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc`)
does the work:

```cpp
page = Page::CreateNonOrdinary(chrome_client, agent_group_scheduler, ...);
Settings& settings = page->GetSettings();
settings.SetScriptEnabled(false);
settings.SetPluginsEnabled(false);
...
frame->ForceSynchronousDocumentInstall(AtomicString("image/svg+xml"), *data, base_url);
```

Its header comment: "Encapsulation of an (SVG)Document that is
isolated/independent from other documents. Does not run scripts. Used by
SVGImage."

Those two setters are the **only** Settings turned off. `CopySettingsFrom()`
then inherits font families, font sizes, image animation policy,
prefers-reduced-motion, preferred color scheme, forced colors and
accept-languages from a normal page. Images, CSS, fonts, filters, masks,
gradients, text and layout all work exactly as in a document.

**Script: two independent gates.**

- `ScriptLoader::PrepareScript()` returns at spec step 16 ("If scripting is
  disabled for el, then return"), so a `<script>` element stays in the DOM, is
  never fetched, never runs.
- `JSEventHandlerForContentAttribute::Create()` returns `nullptr` when
  `CanExecuteScripts()` is false. An `onload=` attribute stays as attribute text
  but compiles to no listener at all.
- `SVGImage::ServiceAnimations()` asserts with a `ScriptForbiddenScope`.

**Resource loading: one line, scheme-only.** In
`third_party/blink/renderer/core/loader/base_fetch_context.cc`,
`BaseFetchContext::CanRequestInternal` around line 305:

```cpp
// SVG images/resource documents have unique security rules that prevent all
// subresource requests except for data urls.
if (IsIsolatedSVGChromeClient() && !url.ProtocolIsData()) {
  return ResourceRequestBlockedReason::kOrigin;
}
```

The test looks only at the chrome client and the URL scheme. Never the resource
type, never the initiating element. So `<image href>`, external `<use href>`,
`<feImage href>`, CSS `url()` for fonts, backgrounds, filters, masks and
cursors, `@import`, `<link rel=stylesheet>`, and anything an `<img>`, `<object>`
or `<video>` inside `<foreignObject>` tries to fetch all land in the same
function. Same-origin http is blocked. `blob:` is blocked. Cached bytes are
blocked, because the check runs before the memory cache is consulted (that was
the original 2014 bug, crbug 380885).

A second barrier sits behind it: the isolated frame's
`LocalFrameClient::GetURLLoaderFactory()` returns a factory whose only handler
is `NOTREACHED()`. A request that got past the fetch check would crash the
renderer, not reach the network.

Blocked loads **fail silently**. The SVG branch returns before
`PrintAccessDeniedMessage`, and `EmptyChromeClient::AddMessageToConsole` has an
empty body, so DevTools shows nothing.

**Relative URLs never resolve.** The base URL is `NullUrl()`, so `href="pic.png"`
produces an invalid KURL and no request is created.

**`<use>` is now fragment-only.** `RemoveDataUrlInSvgUse` is `status: "stable"`
in `runtime_enabled_features.json5`, and a check right after the isolated-SVG
block rejects `data:` when the initiator is `kUse`. Chrome 120 shipped this
(https://developer.chrome.com/blog/migrate-way-from-data-urls-in-svg-use). So
inside an `<img>` SVG, `<use>` can only reference `#id`.

**foreignObject renders.** HTML inside it is laid out. Blink only flags it:
`SVGImage::HasSingleSecurityOrigin()` walks the flat tree and returns false on
the first `SVGForeignObjectElement`, which taints any canvas the image is drawn
into. `<iframe>`, `<object>`, `<embed>` and media inside it are dead ends:
`EmptyLocalFrameClient::CreateFrame()`, `CreateFencedFrame()`, `CreatePlugin()`
and `CreateWebMediaPlayer()` all return `nullptr`.

**Animation runs.** `<img>` gets `kAnimated`. Only `kStatic` (used by
`SVGResourceDocumentContent`, the path for CSS `url()` and external `<use>`
targets) sets `kImageAnimationPolicyNoAnimation`. The animation is driven by
`SVGImageChromeClient`'s own timer at `base::Hertz(60)`, not the host page's
animation frame, "Because a single SVGImage can be shared by multiple pages".
SMIL DOM events are off: `SMILTimeContainer` is constructed with
`should_dispatch_events_(!SVGImage::IsInSVGImage(&owner))`, pinned by
`TEST_F(SVGImageTest, DisablesSMILEvents)`.

**Interactivity.** There is no single switch. The Page is non-ordinary, uses
`EmptyChromeClient` and `EmptyLocalFrameClient`, has no widget, and
`EmptyLocalFrameClient::BeginNavigation()` has an empty body. So link
activation, `javascript:` hrefs, `:hover`, `:focus` and `cursor` never come into
play. Blink's web test `svg/as-image/svg-canvas-link-not-colored.html` shows
neither `:link` nor `:visited` matches.

**Origin.** The document is installed with a null URL and null policy container,
which routes to an opaque origin. `<link rel=preconnect>` and `rel=dns-prefetch`
do nothing, because `LocalFrame::PrescientNetworking()` returns `nullptr` with
no `WebLocalFrameImpl` behind the frame (regression test:
`web_tests/svg/as-image/preconnect-in-svg.html`, crbug 1069289). No DNS or TCP
side channel.

**XSLT is a live edge.** `ProcessingInstruction::CheckStyleSheet` blocks
*external* `xml-stylesheet` in an image context, but a same-document sheet
(`href="#id"`) is still processed and can replace the document. Blink has a use
counter `kXSLPIInSVGImage` and a test `SVGImageSimTest, SVGWithXSLT`. Put
`<?xml-stylesheet?>` on your deny list.

## 4. Firefox and WebKit differences

| Behaviour | Chrome (Blink) | Firefox (Gecko) | Safari (WebKit) |
|---|---|---|---|
| Where enforced | `BaseFetchContext::CanRequestInternal` | `nsDataDocumentContentPolicy::ShouldLoad` | `CachedResourceLoader::canRequest` |
| Script off by | `SetScriptEnabled(false)` | no docshell + `ScriptLoader::SetEnabled(false)` | `setScriptEnabled(false)` |
| `data:` subresources | allowed | allowed | allowed |
| `blob:` subresources | blocked | **allowed** (`URI_LOADABLE_BY_SUBSUMERS`) | blocked |
| Relative URLs | null base URL, never resolve | real document URI kept, resolve then get blocked | empty URL, never resolve |
| `foreignObject` renders | yes | yes | yes |
| iframe/embed inside it | no (`nullptr` clients) | no (`nsObjectLoadingContent`) | no |
| Canvas tainted by foreignObject | yes | unverified | yes |
| `:visited` | never matches | `Gecko_VisitedStylesEnabled` returns false | (WebKit FIXME names the leak) |
| `<use href="data:...">` | removed in Chrome 120 | removed in Firefox 122 | never supported |
| Extra backstop | loader factory `NOTREACHED()` | - | full sandbox flags asserted |

The rule landed in Blink in June 2014 (commit `ee281f7cac9d`, "Enforce SVG image
security rules"), explicitly to match Gecko: "With this patch we now match
Gecko's behavior on both testcases." Gecko's side is Mozilla bug 628747.

## 5. Tests

**There is no written test plan and no design doc.** The model exists as code
comments in three Blink files plus the SVG 2 conformance chapter. The tests are
the plan. Useful ones:

web-platform-tests:

- `svg/embedded/image-embedding-nested-http-url.sub.html` - mismatch reftest: an
  `<image href>` to a network URL must not paint.
- `svg/embedded/image-embedding-nested-data-url.html` - match reftest: a nested
  `data:` SVG must paint.
- `svg/embedded/image-embedding-nested-data-url-png.html`, `-nesteder-`,
  `-from-canvas` - same rule at more nesting levels and from canvas.
- `svg/embedded/image-embedding-nested-external-data-url-png.html` - an
  externally loaded `.svg` holding a `data:` PNG, so the rule is about the
  subresource URL, not the outer one.
- `svg/as-image/external-resource-inline-sheet.html` - the same file in `<img>`
  and `<object>` side by side; the inline `<style>` `background-image:
  url(/images/blue.png)` must load in one and not the other. Added 2025 from
  Mozilla bug 1982344.
- `html/semantics/embedded-content/the-img-element/svg-img-with-external-stylesheet.html`
  - an XHTML `<link rel=stylesheet>` inside the SVG must not load.
- `svg/embedded/image-embedding-svg-nested-svg-in-foreignobject.html` - passes
  everywhere, so `foreignObject` does render in `<img>`.

Chromium `third_party/blink/web_tests/`:

- `http/tests/security/svg-image-with-cached-remote-image.html` - warms a remote
  image through `<object>`, then loads the same SVG through `<img>`; the cached
  bytes must not be reused (crbug 380885).
- `http/tests/security/svg-image-with-css-import.html` - `@import` blocked
  (crbug 382296).
- `svg/as-image/data-font-in-css.html` - a `@font-face` with `data:font/ttf` src
  does load, and the img load event waits for it.
- `svg/as-image/svg-canvas-not-tainted.html` vs `svg-canvas-xhtml-tainted.html` -
  plain SVG does not taint a canvas; any `foreignObject` does.
- `svg/as-image/svg-canvas-link-not-colored.html` - neither `:link` nor
  `:visited` matches in image mode.
- `svg/as-image/preconnect-in-svg.html` - no preconnect from an SVG image.
- `http/tests/svg/use-contenttype-blocked.html`, `use-no-contenttype-blocked.html`
  - content-type checks on external `<use>`.

Gecko `layout/reftests/svg/as-image/reftest.list` is the clearest written
statement of the iframe and embed rule, with an explicit comment block, and it
pairs each external-resource test with a `data:` twin (`svg-image-datauri-1`,
`svg-stylesheet-datauri-1`). Its `svg-stylesheet-external.svg` uses an
`<?xml-stylesheet?>` PI.

## 6. Gmail and other mail clients

Nothing in email renders SVG the way a browser does, so email will not exercise
your allow-list. Evidence quality varies; dates matter.

**Inline `<svg>` in Gmail: no.** The caniemail dataset
(`_features/html-svg.md`) records "n" for all four Gmail clients (desktop
webmail, iOS, Android, mobile webmail), `last_test_date: "2020-02-06"`.
Community testing, not vendor documentation, and not retested since 2020. The
dataset records pass or fail, not mechanism, so it does not say whether Gmail
strips the element or renders a blank box.

**`<img src=x.svg>` in Gmail: no.** Same dataset, `_features/image-svg.md`:
Gmail desktop webmail "n" at 2020-02, 2023-01 and 2024-07. The iOS and Android
apps are partial, with the note "Partially supported. Only works with non Google
accounts." That note points at the proxy, not the HTML sanitizer. Google
documents the proxy at
https://knowledge.workspace.google.com/admin/gmail/advanced/set-up-an-image-url-proxy-allowlist:
"Gmail uses Google's secure proxy servers to serve images."

Google support told a MediaWiki developer in February 2016
(https://phabricator.wikimedia.org/T127794): "they've confirmed there are
currently no plans to support SVG images in the proxy. They said they account
for only 1 in 100,000 email images." Wikimedia fixed its broken notification
icons by serving rasterized PNGs through the same proxy. Later community reports
of a 404 from `ci*.googleusercontent.com` are unconfirmed.

**Attachments: allowed.** `.svg` is not on Gmail's blocked list
(https://support.google.com/mail/answer/6590). SVG phishing attachments were a
mainstream 2025 campaign type (Kaspersky, https://securelist.com/svg-phishing/116256/,
2025-04-21). Google Drive lists `.SVG` as a previewable image type
(https://support.google.com/drive/answer/37603). Whether Gmail shows an inline
thumbnail, and whether Drive's preview renders live or a server-side raster, is
undocumented.

**Google's own position.** web.dev "Securely hosting user data" (David Dworken,
updated 2023-06-08) groups SVG with HTML as active content and recommends
isolation headers (`Content-Security-Policy: sandbox`, `nosniff`,
`Content-Disposition: attachment`) rather than discussing sanitizing at all.
AMP's SVG allow-list (`validator/validator-svg.protoascii`) has 60 tag entries
covering 59 distinct tags, no script, style, foreignObject, anchor or animation,
and the string `AMP4EMAIL` appears zero times in the file: Google's own email
format bans SVG outright.

**Other clients** (caniemail). Inline `<svg>`: Apple Mail macOS 13 partial
("Requires a background on the `<body>`"), Apple Mail iOS 13 yes, Thunderbird
yes, ProtonMail yes, Outlook.com no, Yahoo and AOL no. Linked SVG in `<img>`:
Yahoo, AOL, Outlook.com, Outlook 2019, Apple Mail macOS 14 and iOS 15 all yes.

Microsoft moved the other way. Message center MC1130385
(https://mc.merill.net/message/MC1130385, published 2025-08-06): "Inline SVG
images will no longer be displayed in Outlook for Web or the new Outlook for
Windows. Instead, users will see blank spaces where these images would have
appeared. ... SVG images sent as classic attachments will continue to be
supported." Rolled out early September to mid-October 2025. Microsoft's reason
is XSS, and it claims the change "aligns with current email client behavior,
which already restricts inline SVG rendering."

## 7. Sanitizer design that matches Chrome

### The model

Target secure animated mode. Three rules cover almost everything:

1. **No script.** Remove `<script>`. Remove every `on*` attribute. Remove
   `javascript:` in any URL position. Remove `<?xml-stylesheet?>` processing
   instructions outright, same-document ones included.
2. **URLs: `#fragment` or `data:` only.** Reject every other scheme in every
   URL-bearing attribute and every CSS `url()`. That includes `http`, `https`,
   `blob:`, `file:`, `filesystem:`, and protocol-relative `//host/x`. Also
   reject relative paths: Chrome cannot resolve them, so they are already dead
   in `<img>`, and they would resolve when the file is opened directly.
3. **`<use>` is `#id` only.** No `data:`, no external. All three engines now
   agree on this.

### Allow / deny

**Elements, allowed:** `svg`, `g`, `defs`, `symbol`, `title`, `desc`,
`metadata`, `switch`; shapes (`path`, `rect`, `circle`, `ellipse`, `line`,
`polyline`, `polygon`); `text`, `tspan`, `textPath`; `image` (data: only);
`use` (`#id` only); paint servers (`linearGradient`, `radialGradient`, `stop`,
`pattern`); `clipPath`, `mask`, `marker`, `filter` and the `fe*` primitives;
`style`; SMIL (`animate`, `set`, `animateTransform`, `animateMotion`, `mpath`).

**Elements, denied:** `script`, `foreignObject`, `handler`, `listener`, `audio`,
`video`, `iframe`, `object`, `embed`, `font-face-uri`, and anything not on the
allow-list. Deny CDATA sections: convert each to an escaped text node.

**Attributes, denied:** every `on*` (unconditionally, before anything else);
`xlink:actuate`, `xlink:show`; `requiredExtensions` pointing outside SVG. On
`animate` and `set`, reject `attributeName` naming any URL-typed or
stylesheet-typed attribute (`href`, `xlink:href`, `style`, `filter`, `mask`,
`clip-path`, `fill`, `stroke`, `cursor`). That is the SMIL escape hatch: without
it, `<set attributeName="href" to="javascript:...">` slips past an attribute
allow-list.

**URL schemes:** `#...` always; `data:image/(png|gif|jpeg|jpg|webp)` and
`data:image/svg+xml` on `<image href>`; `data:font/*` and `data:;base64,` inside
`@font-face src`. Everything else rejected.

**CSS.** Parse it, do not regex it. In `<style>` text and in `style=`
attributes: reject `@import` and `@charset` outright; reject the `image()`,
`image-set()` and `src()` functions; require every `url()` to start with `#`,
with the `@font-face` data: carve-out above; reject `expression(`,
`-moz-binding`, and `behavior`. MediaWiki's `includes/Upload/SvgCssChecker.php`
is a working model: it runs a real CSS tokenizer and rejects malformed URL
tokens too.

### Where you must be stricter than Chrome

Chrome's blocks are render-time, so they vanish the moment the same file is
opened as a document, saved to disk, or fed to a different renderer. Diverge
from Chrome on these:

| Thing | Chrome in `<img>` | Same file opened directly | Sanitizer should |
|---|---|---|---|
| `<script>` | parsed, never runs | **runs** | remove |
| `on*` attributes | no listener compiled | **fire** | remove |
| `javascript:` href | inert, no navigation | **navigates** | remove |
| `<a href="https://...">` | inert | **clickable** | remove or rewrite |
| `:hover`, `:focus`, `begin="click"` | no effect | **works** | remove for identical rendering |
| `:link`, `:visited` | never match | match, leaks history | remove |
| external `url()` | silently blocked | **loads, phones home** | remove |
| `<?xml-stylesheet href="#id"?>` | **processed by Chrome** | processed | remove |
| `<foreignObject>` | renders, no script | renders, **scripts run** | remove |

The `foreignObject` line is a deliberate divergence. Chrome renders it, so
stripping it changes the rendering. Keep it only if you also strip everything
inside it that becomes dangerous in document mode, which is most of HTML. Simpler
to drop the element.

Also strip on general principle, because they do nothing useful in an image and
are historic sinks: `<handler>`, `<listener>`, `xlink:actuate="onLoad"`, DTDs and
entity declarations (billion-laughs), and any namespace other than SVG, xlink,
and the inert metadata namespaces (Inkscape, sodipodi, Adobe Illustrator, XMP,
Dublin Core) if you want design-tool output to survive.

### Where you can be looser than the common libraries

Fragment references and `data:` URLs are legal in secure animated mode. Do not
strip `url(#filter1)`, `fill="url(#grad)"`, `<use href="#icon">` or a base64
raster in `<image>`. Stripping them breaks legitimate files and buys nothing.

### How the existing libraries compare

| Library | Fits Chrome's `<img>` model? | Gaps |
|---|---|---|
| **DOMPurify** | no | Allows `http`/`https` in `href` and `xlink:href` by default (`IS_ALLOWED_URI`). Does not parse CSS at all: `style` is in `DEFAULT_URI_SAFE_ATTRIBUTES`, so `_isValidAttribute` returns true before any URL check, and `<style>` text is never inspected. Its own README: "DOMPurify per default allows CSS". Strips `use`, `foreignObject`, `animate`, `set`, `script` by default (not in the allow-list), but keeps `animateColor`, `animateMotion`, `animateTransform`, `mpath`. Built for DOM insertion, not files; its CVE history is mostly mXSS from re-parsing sanitized output in a different context (CVE-2024-47875, CVE-2024-45801, CVE-2026-65914). |
| **enshrined/svg-sanitize** (PHP) | no, out of the box | `isHrefSafeValue` returns true for `http://` and `https://`, and `removeRemoteReferences` defaults to `false`, so external references survive unless you turn the flag on. `data:` is limited to five raster prefixes, stricter than Chrome. `<use>` is fragment-only with a depth cap. No `feImage`, `feDropShadow`, `animate`, `set`, `foreignObject`. Two CVEs, both CDATA (CVE-2022-23638 real, fixed 0.15.0; CVE-2023-28426 later withdrawn as a false positive). Current fix rewrites every CDATA node into an escaped text node. |
| **MediaWiki** (rejector, not sanitizer) | closest match | `UploadVerification.php` `checkSvgScriptCallback`: href must be `#` or `data:`, except `<a>` which may be `http(s)`; `data:` href must match `image/(gif|jpeg|jpg|a?png|webp|avif)`; rejects `set`/`animate` with `attributeName` starting `on`, `set/@attributeName` containing `href`, `set/@to` matching `(http|https|data|script):`. `SvgCssChecker.php` tokenizes CSS. Also carries a large allow-list of Illustrator, Inkscape, sodipodi, XMP, Photoshop and Dublin Core namespaces, which answers the design-tool question. Rejects the file instead of rewriting it. |
| **Cloudflare svg-hush** (Rust) | best mechanism | Types every attribute (`Url`, `UrlFunc`, `StyleSheet`, `Keyword`, `Number`, `Text`, `AnyAscii`) and lets `animate`/`set` target only inert types. Keeps `animate`, `set`, `animateTransform`, `animateMotion`. Drops all `data:` URLs unless you supply an `image_filter` callback, so embedded rasters and fonts are lost by default. |
| **librsvg** (for server-side thumbnails) | no | "ignores animations, scripts, and events" but does resolve referenced images (https://gnome.pages.gitlab.gnome.org/librsvg/devel-docs/security.html). Static mode with network, not Chrome's `<img>`. |

The practical recommendation: start from enshrined/svg-sanitize (PHP, maintained,
already handles CDATA and `<use>` depth), turn `removeRemoteReferences` on,
replace `isHrefSafeValue` with a fragment-or-data-only rule, add a real CSS
tokenizer pass modelled on `SvgCssChecker.php`, add the SMIL `attributeName`
rule from svg-hush, and add `<?xml-stylesheet?>` and DTD removal.

### Unit test cases, derived from the browser tests

Positive (must survive, must still render):

1. `<use href="#icon">` referencing a `<symbol>` in the same file.
2. `fill="url(#grad)"` with a `linearGradient`, and `filter="url(#f)"`.
3. `<image href="data:image/png;base64,...">` (wpt `image-embedding-nested-data-url-png.html`).
4. A nested `data:image/svg+xml` inside `<image href>`, two levels deep
   (wpt `image-embedding-nesteder-data-url.html`).
5. `@font-face { src: url(data:font/ttf;base64,...) }` in `<style>`
   (Blink `svg/as-image/data-font-in-css.html`).
6. A SMIL `<animate attributeName="cx" ...>` and a CSS `@keyframes` animation.
7. Inkscape and Illustrator namespace attributes on a real design-tool export.

Negative (must be removed):

8. `<script>alert(1)</script>` and `<script href="data:text/javascript,...">`.
9. `onload=`, `onclick=`, `onmouseover=` on any element, including `<svg>` itself.
10. `<a href="javascript:alert(1)">` and `<a xlink:href="javascript:...">`.
11. `<image href="https://evil/x.png">` (wpt `image-embedding-nested-http-url.sub.html`).
12. `<image href="/same-origin/x.png">` and `href="x.png"` (Chrome blocks both).
13. `<link rel="stylesheet" href="red-bg.css">` in the XHTML namespace inside the
    SVG (wpt `svg-img-with-external-stylesheet.html`).
14. `<style>@import url(https://evil/x.css);</style>` (Chromium `svg-image-with-css-import.html`).
15. `<style>svg { background-image: url(https://evil/x.png) }</style>`
    (wpt `external-resource-inline-sheet.html`).
16. `<use href="data:image/svg+xml,...">` and `<use href="https://evil/x.svg#a">`.
17. `<set attributeName="href" to="javascript:alert(1)">` and
    `<animate attributeName="onload" to="alert(1)">` (MediaWiki's rules).
18. `<foreignObject><iframe src="data:text/html,<script>alert(1)</script>">`
    (Gecko `img-foreignObject-iframe-1a.html`).
19. `<![CDATA[<p/><img src=x onerror=alert(1)>]]>` inside a `<style>` or `<title>`
    (CVE-2022-23638).
20. `<?xml-stylesheet type="text/xsl" href="#s"?>` with an inline XSLT stylesheet
    (Blink `SVGImageSimTest, SVGWithXSLT`).
21. A DTD with an entity expansion (billion laughs).
22. `a:visited { fill: blue }` (Blink `svg-canvas-link-not-colored.html`).
23. `<link rel=preconnect href="https://evil">` inside the SVG
    (Blink `preconnect-in-svg.html`).

Round-trip test: sanitize, then render the output twice, once via `<img>` and
once as a top-level document, and diff the two renderings. Any difference is a
bug in the sanitizer, because that difference is exactly the attack surface
Chrome's render-time blocks are hiding.

## 8. Open questions, and what the fact checkers refuted

Refuted or corrected:

- **Refuted:** "CSS Images Level 4 is the only CSS spec that ties `<image>`
  values to the secure modes." CSS Basic User Interface Level 4 does the same
  for `cursor`.
- **Refuted:** "`<use href="data:...">` works in Firefox and Safari but not
  Chrome." All three reject it. Chrome removed it in 120, Firefox in 122
  (bug 1806964), WebKit never supported it.
- **Corrected:** SVG 2 CR is dated 4 October 2018, not 1 October.
- **Corrected:** HTML does cite SVG 2 (263 links), but only to rendering
  chapters, never the conformance chapter. The "no spec-level limit on external
  loads from `<img>` SVG" conclusion still stands.
- **Corrected:** HTML's `<img src>` sentence is an authoring rule, but browsers
  are still required to disable script, just by SVG Integration / SVG 2 rather
  than by HTML's img prose.
- **Corrected:** Blink's data: carve-out works because data: URLs never reach the
  loader factory, not because the factory allows them.
- **Corrected:** Blink's `kImageAnimationPolicyNoAnimation` comment covers SMIL
  and image animation, not CSS animation. CSS animation runs in `<img>` by a
  different path.
- **Corrected:** the wpt pass-status claims cite aligned run `9db4b48c1f`
  (Chrome 153, Firefox 155, Safari 26.6). Some of those per-run statuses could
  not be re-confirmed; the test files and their assertions were verified.
- **Corrected:** `MC1130385` covers Outlook for Web and new Outlook for Windows
  only, not classic Win32 Outlook or Outlook mobile.

Still open:

- No spec normatively requires secure animated mode for HTML `<img>`. Open
  WHATWG issue 10641 (canvas origin-clean for SVG-as-image) and svgwg 358 do not
  close the gap.
- Whether Firefox taints a canvas when drawing an SVG image containing
  `foreignObject`. Chrome and WebKit clearly do.
- Whether a same-document XSLT stylesheet in an `<img>` SVG can emit output that
  behaves differently from the pre-transform document.
- Whether there is any size or nesting limit on `data:` subresources.
- Whether Gmail strips `<svg>` from the message DOM or keeps it and renders
  nothing. caniemail records pass/fail only.
- Whether Gmail's proxy rejects SVG by Content-Type or fetches and fails to
  convert. Only community 404 reports.
- Whether Google Drive's SVG preview renders live or a server-side raster, and
  whether Drive sanitizes first.
- The caniemail inline-`<svg>` numbers are from 2020-02-06 and have not been
  rerun. A 2026 retest is needed before treating them as current.
- Chromium issue 40094872 ("Top-level navigation to SVG documents isn't
  restricted like `<img src>` embedding of same image") is directly relevant to
  the render-identically goal but requires sign-in to read.
- ImageMagick's current `policy.xml` guidance on the SVG/MSVG coders could not
  be quoted: `imagemagick.org/script/security-policy.php` redirects and
  `/security-policy/` returns 404.

## 9. Sources

**Specs**
- https://html.spec.whatwg.org/multipage/images.html#images-processing-model
- https://html.spec.whatwg.org/multipage/embedded-content.html#the-img-element
- https://html.spec.whatwg.org/
- https://www.w3.org/TR/SVG2/conform.html
- https://www.w3.org/TR/SVG2/conform.html#referencing-modes
- https://www.w3.org/TR/SVG2/conform.html#secure-animated-mode
- https://www.w3.org/TR/SVG2/conform.html#features
- https://svgwg.org/svg2-draft/conform.html
- https://www.w3.org/TR/svg-integration/
- https://svgwg.org/specs/integration/
- https://www.w3.org/TR/css-images-4/#image-file-formats
- https://drafts.csswg.org/css-images-4/
- https://fetch.spec.whatwg.org/

**Chromium / Blink source**
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc
- https://chromium.googlesource.com/chromium/src/+/main/third_party/blink/renderer/core/svg/graphics/isolated_svg_document_host.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/svg_image.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/graphics/svg_image_chrome_client.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/loader/base_fetch_context.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/loader/empty_clients.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/animation/smil_time_container.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/svg/svg_use_element.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/bindings/core/v8/js_event_handler_for_content_attribute.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/core/frame/local_frame.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/platform/loader/fetch/resource_fetcher.cc
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/renderer/platform/runtime_enabled_features.json5
- https://github.com/chromium/chromium/commit/ee281f7cac9d
- https://github.com/chromium/chromium/commit/5580670229
- https://github.com/chromium/chromium/commit/4f3ebd119d
- https://developer.chrome.com/blog/migrate-way-from-data-urls-in-svg-use

**Chromium web_tests**
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/svg-canvas-link-not-colored.html
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/data-font-in-css-crash.html
- https://raw.githubusercontent.com/chromium/chromium/main/third_party/blink/web_tests/svg/as-image/preconnect-in-svg.html
- https://github.com/chromium/chromium/tree/main/third_party/blink/web_tests/svg/as-image/resources

**web-platform-tests**
- https://github.com/web-platform-tests/wpt/tree/master/svg/embedded
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/embedded/image-embedding-nested-http-url.sub.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/embedded/image-embedding-nested-data-url.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/svg/as-image/external-resource-inline-sheet.html
- https://github.com/web-platform-tests/wpt/blob/master/svg/as-image/external-resource-inline-sheet.html
- https://raw.githubusercontent.com/web-platform-tests/wpt/master/html/semantics/embedded-content/the-img-element/svg-img-with-external-stylesheet.html

**Gecko and WebKit**
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/dom/base/nsDataDocumentContentPolicy.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/dom/base/Document.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/netwerk/build/components.conf
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/image/SVGDocumentWrapper.cpp
- https://searchfox.org/mozilla-central/source/image/SVGDocumentWrapper.cpp
- https://raw.githubusercontent.com/mozilla/gecko-dev/master/layout/reftests/svg/as-image/reftest.list
- https://bugzilla.mozilla.org/show_bug.cgi?id=628747
- https://bugzilla.mozilla.org/show_bug.cgi?id=629682
- https://bugzilla.mozilla.org/show_bug.cgi?id=1190881
- https://bugzilla.mozilla.org/show_bug.cgi?id=1901414
- https://raw.githubusercontent.com/WebKit/WebKit/main/Source/WebCore/svg/graphics/SVGImage.cpp
- https://raw.githubusercontent.com/WebKit/WebKit/main/Source/WebCore/loader/cache/CachedResourceLoader.cpp
- https://bugs.webkit.org/show_bug.cgi?id=180301

**Sanitizers**
- https://github.com/cure53/DOMPurify/blob/main/src/tags.ts
- https://raw.githubusercontent.com/cure53/DOMPurify/main/src/tags.ts
- https://raw.githubusercontent.com/cure53/DOMPurify/main/src/purify.ts
- https://github.com/cure53/DOMPurify/blob/main/src/regexp.ts
- https://github.com/darylldoyle/svg-sanitizer/blob/1.0.0/src/Sanitizer.php
- https://raw.githubusercontent.com/darylldoyle/svg-sanitizer/1.0.0/src/Sanitizer.php
- https://github.com/advisories/GHSA-fqx8-v33p-4qcc
- https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/Upload/UploadVerification.php
- https://raw.githubusercontent.com/wikimedia/mediawiki/master/includes/Upload/SvgCssChecker.php
- https://raw.githubusercontent.com/cloudflare/svg-hush/main/src/lib.rs
- https://gnome.pages.gitlab.gnome.org/librsvg/devel-docs/security.html

**Email**
- https://raw.githubusercontent.com/hteumeuleu/caniemail/main/_features/html-svg.md
- https://raw.githubusercontent.com/hteumeuleu/caniemail/main/_features/image-svg.md
- https://www.caniemail.com/features/html-svg/
- https://knowledge.workspace.google.com/admin/gmail/advanced/set-up-an-image-url-proxy-allowlist
- https://phabricator.wikimedia.org/T127794
- https://support.google.com/mail/answer/6590
- https://support.google.com/drive/answer/37603
- https://securelist.com/svg-phishing/116256/
- https://mc.merill.net/message/MC1130385
- https://raw.githubusercontent.com/ampproject/amphtml/main/validator/validator-svg.protoascii
- https://raw.githubusercontent.com/ampproject/amphtml/main/docs/spec/email/amp-email-html.md
- https://raw.githubusercontent.com/googlearchive/caja/master/README.md
- https://raw.githubusercontent.com/google/closure-library/master/closure/goog/html/sanitizer/tagwhitelist.js
- https://web.dev/articles/securely-hosting-user-data

**Issue trackers (not readable without sign-in, cited from commits)**
- https://github.com/whatwg/html/issues/10641
- https://github.com/w3c/svgwg/issues/358
