# What Gets Through

The allowlists. Every element, attribute, namespace, URL form, CSS function and animation
target has to be on one of these lists to pass; anything not listed is rejected with the
code named in [What Gets Rejected](what-gets-rejected.md). Names are case-sensitive:
`clipPath` is an element, `clippath` is not.

The lists below are generated from `SvgValidator::rules()` by `tools/rules-doc.php`, and a
test fails when they fall out of step with the code. The same arrays are available at
runtime:

```php
$rules = SvgValidator::rules();
$rules['elements'];    // ['svg', 'g', 'defs', 'symbol', 'use', ...]
```

Contents:

- [Elements](#elements)
- [Attributes](#attributes)
- [Prefixed Attributes](#prefixed-attributes)
- [Inert Namespaces](#inert-namespaces)
- [URL Forms](#url-forms)
- [CSS](#css)
- [Animation](#animation)
- [A File That Passes](#a-file-that-passes)

## Elements

Elements in the SVG namespace, in the order the source lists them: structure, shapes and
text, paint servers and masks, filters, animation.

<!-- rules:elements -->
```text
svg g defs symbol use title desc metadata switch a view style path rect circle ellipse line polyline
polygon text tspan textPath image linearGradient radialGradient stop pattern clipPath mask marker
filter feBlend feColorMatrix feComponentTransfer feComposite feConvolveMatrix feDiffuseLighting
feDisplacementMap feDistantLight feDropShadow feFlood feFuncA feFuncB feFuncG feFuncR feGaussianBlur
feImage feMerge feMergeNode feMorphology feOffset fePointLight feSpecularLighting feSpotLight feTile
feTurbulence animate set animateTransform animateMotion mpath
```
<!-- /rules:elements -->

Worth noticing: `<a>` is allowed, with a same-file `href` only. `<style>` is allowed, with
the [CSS rules](#css). `<image>` is allowed, with embedded images only. Every filter
primitive is allowed, including `<feImage>`, which follows the image rules. A nested
`<svg>` is allowed. `<switch>`, `<view>`, `<title>`, `<desc>` and `<metadata>` are allowed
and often present in design-tool output.

Not on the list, on purpose: `<script>`, `<foreignObject>`, `<handler>`, `<iframe>`,
`<tref>`, `<cursor>`, the font elements, `<animateColor>` and `<discard>`. The reasons are
under [`element-not-allowed`](what-gets-rejected.md#element-not-on-the-allowlist---element-not-allowed).

## Attributes

Unprefixed attributes, on any allowed element. Any attribute starting with `data-` or `aria-`
is also allowed.

<!-- rules:attributes -->
```text
id class style lang role requiredExtensions requiredFeatures systemLanguage
externalResourcesRequired version baseProfile viewBox preserveAspectRatio zoomAndPan
contentStyleType x y width height cx cy r rx ry x1 y1 x2 y2 fx fy fr d points pathLength href
transform transform-origin transform-box dx dy rotate textLength lengthAdjust startOffset method
spacing side gradientUnits gradientTransform spreadMethod offset patternUnits patternContentUnits
patternTransform clipPathUnits maskUnits maskContentUnits markerUnits markerWidth markerHeight refX
refY orient filterUnits primitiveUnits filterRes in in2 result mode type values tableValues slope
intercept amplitude exponent k1 k2 k3 k4 operator radius stdDeviation edgeMode kernelMatrix order
divisor bias targetX targetY kernelUnitLength preserveAlpha surfaceScale diffuseConstant
specularConstant specularExponent z azimuth elevation pointsAtX pointsAtY pointsAtZ
limitingConeAngle xChannelSelector yChannelSelector scale baseFrequency numOctaves seed stitchTiles
attributeName attributeType begin dur end min max restart repeatCount repeatDur calcMode keyTimes
keySplines from to by additive accumulate path keyPoints origin alignment-baseline baseline-shift
clip clip-path clip-rule color color-interpolation color-interpolation-filters color-rendering
direction display dominant-baseline enable-background fill fill-opacity fill-rule filter flood-color
flood-opacity font font-family font-kerning font-size font-size-adjust font-stretch font-style
font-variant font-weight glyph-orientation-horizontal glyph-orientation-vertical image-rendering
isolation kerning letter-spacing lighting-color marker marker-end marker-mid marker-start mask
mask-type mix-blend-mode opacity overflow paint-order pointer-events shape-rendering stop-color
stop-opacity stroke stroke-dasharray stroke-dashoffset stroke-linecap stroke-linejoin
stroke-miterlimit stroke-opacity stroke-width text-anchor text-decoration text-rendering
unicode-bidi vector-effect visibility white-space word-spacing writing-mode
```
<!-- /rules:attributes -->

Every presentation attribute in SVG 1.1 and SVG 2 is here, so `fill`, `stroke`, `opacity`,
`transform`, `filter`, `mask` and `clip-path` all pass. Their values are still checked:
a `url()` in any of them must be `url(#id)`.

Not on the list, on purpose: `tabindex`, `target`, `crossorigin`, `cursor`, and every `on*`
attribute. The reasons are under
[`attribute-not-allowed`](what-gets-rejected.md#attribute-not-on-the-allowlist---attribute-not-allowed).

## Prefixed Attributes

The only prefixed attributes allowed on SVG elements, by namespace. `xmlns` declarations
always pass. `xlink:href` follows the same rules as `href`.

<!-- rules:namespacedAttributes -->
| Namespace                              | Attributes            |
|----------------------------------------|-----------------------|
| `http://www.w3.org/XML/1998/namespace` | `id`, `lang`, `space` |
| `http://www.w3.org/1999/xlink`         | `href`, `title`       |
<!-- /rules:namespacedAttributes -->

Not on the list, on purpose: `xml:base`, `xlink:show`, `xlink:actuate`.

## Inert Namespaces

Design tools and metadata standards add their own elements and attributes to an SVG file:
Inkscape's layer names, Illustrator's slice data, Dublin Core titles. Browsers ignore all of
it. Elements and attributes in these namespaces pass without inspection, except that an
`on*` attribute is rejected in any namespace. Matching is by prefix, so every namespace under
`http://ns.adobe.com/` or `http://purl.org/dc/` counts.

<!-- rules:inertNamespaces -->
```text
http://www.inkscape.org/namespaces/inkscape
http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd
http://www.w3.org/1999/02/22-rdf-syntax-ns#
http://www.w3.org/2000/01/rdf-schema#
http://purl.org/dc/
http://creativecommons.org/ns#
http://web.resource.org/cc/
http://ns.adobe.com/
adobe:ns:meta/
http://www.serif.com/
http://www.bohemiancoding.com/sketch/ns
http://schemas.microsoft.com/visio/2003/SVGExtensions/
http://www.w3.org/2001/XMLSchema-instance
```
<!-- /rules:inertNamespaces -->

Any other namespace is rejected with `namespace-not-allowed`. That includes XHTML, MathML,
XInclude and XML Events, which is where the historical attacks live.

## URL Forms

What a reference may point at, by where it appears. A same-file reference is `#` followed
by the `id` of an element in the same file.

| Where                                        | Allowed                                                                        | Rejects with             |
|----------------------------------------------|--------------------------------------------------------------------------------|--------------------------|
| `href` on any element but the two image ones | a same-file reference (`#id`)                                                  | `href-not-allowed`       |
| `href` on the image elements (below)         | `#id`, or a base64 `data:` URL of one of the image types below                 | `image-href-not-allowed` |
| `url()` in any attribute except `style`      | `url(#id)`, quotes and whitespace allowed                                      | `url-not-fragment`       |
| `url()` in CSS (`<style>` or `style=`)       | `url(#id)`, or an embedded font: `url(data:font/...)`, `url(data:;base64,...)` | `css-not-allowed`        |

Image elements: <!-- rules:imageElements -->
`image`, `feImage`
<!-- /rules:imageElements -->

Image types, as `data:image/TYPE;base64,`: <!-- rules:dataImageTypes -->
`png`, `jpeg`, `jpg`, `gif`, `webp`, `svg+xml`
<!-- /rules:dataImageTypes -->

An embedded SVG image (`data:image/svg+xml`) is decoded and checked with every rule, three
levels deep.

## CSS

In a `<style>` element or a `style` attribute, everything passes except the tokens listed
under [`css-not-allowed`](what-gets-rejected.md#css-that-loads-imports-or-escapes---css-not-allowed).
In practice that means:

- Any selector, any property, any value without a backslash.
- `@font-face` with an embedded font (`src: url(data:font/woff2;base64,...)`).
- `@media`, `@keyframes`, `@supports` and other at-rules except `@import` and `@charset`.
- Comments, though their contents are still scanned.
- `:hover`, `:focus` and `:visited`. Chrome makes these do nothing in `<img>` mode and honors
  them when the file is opened directly. Nothing loads and nothing runs either way, so they
  are accepted; this is looser than Chrome.

## Animation

`<animate>`, `<set>`, `<animateTransform>`, `<animateMotion>` and `<mpath>` are allowed, and
so is every timing and value attribute. Chrome runs SMIL animation in `<img>` mode. Two
limits: `attributeName` may not be `href`, `xlink:href`, `style`, `class` or an `on*`
name, and no item in `from`, `to`, `by` or `values` may start with a URL scheme.
`begin="click"` and the other event-based timings are accepted; Chrome ignores them in
`<img>` mode, and nothing loads or runs when they fire.

## A File That Passes

A gradient, a pattern, an embedded font, a `<use>`, an animation, and Inkscape metadata,
all in one file that is accepted.

```xml
<svg xmlns="http://www.w3.org/2000/svg" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" viewBox="0 0 100 40">
  <style>
    @font-face { font-family: Brand; src: url(data:font/woff2;base64,d09GMgABAAAAAAA=); }
    .label { font-family: Brand; fill: url(#ink); }
  </style>
  <defs>
    <linearGradient id="ink"><stop offset="0" stop-color="#036"/><stop offset="1" stop-color="#39c"/></linearGradient>
    <pattern id="dots" width="4" height="4" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1" fill="url(#ink)"/></pattern>
    <symbol id="mark" viewBox="0 0 10 10"><circle cx="5" cy="5" r="5"/></symbol>
  </defs>
  <g inkscape:label="Logo" inkscape:groupmode="layer">
    <rect width="100" height="40" fill="url(#dots)"/>
    <use href="#mark" x="2" y="15" width="10" height="10" fill="url(#ink)"/>
    <text class="label" x="16" y="26">Brand</text>
    <circle cx="90" cy="20" r="4"><animate attributeName="r" values="4;6;4" dur="2s" repeatCount="indefinite"/></circle>
  </g>
</svg>
<!-- accepted -->
```

---

[← What Gets Rejected](what-gets-rejected.md) | [Documentation Index](README.md) | [Next: Common Patterns →](common-patterns.md)
