# Method Reference

Every public method, property and constant, with its type and what it returns. The three
classes are `final` and in the `Itools\SvgValidator` namespace. Method names link to the
guide section that covers each.

Contents:

- [SvgValidator](#svgvalidator)
- [Result](#result)
- [Violation](#violation)
- [Error Codes](#error-codes)
- [Limits](#limits)

## SvgValidator

Three static methods; the class cannot be instantiated.

| Method                                                                                         | Returns  | Description                                                                                                                                                                                                        |
|------------------------------------------------------------------------------------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| [`SvgValidator::checkFile(string $path)`](getting-started.md#your-first-check---checkfile)     | `Result` | Streams the file at `$path` through every rule. A path that is not a readable file gives a result with one `file-unreadable` error; nothing throws                                                                 |
| [`SvgValidator::checkString(string $svg)`](getting-started.md#checking-a-string---checkstring) | `Result` | The same check on SVG source in a string. Same result as `checkFile()` for the same bytes                                                                                                                          |
| [`SvgValidator::rules()`](what-gets-through.md)                                                | `array`  | The allowlists, keyed `elements`, `attributes`, `namespacedAttributes` (by namespace URI), `inertNamespaces`, `inertProcessingInstructions`, `imageElements`, `dataImageTypes`. Read-only: the lists are constants |

Neither check looks at the file name, extension, MIME type or size. Check those at upload
time; [Security Model](security-model.md#at-upload-time) says what to refuse.

## Result

What both check methods return. Two readonly properties, set in the constructor.

| Property          | Type          | Description                                                                                                                                               |
|-------------------|---------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| `$result->ok`     | `bool`        | `true` when `$errors` is empty                                                                                                                            |
| `$result->errors` | `Violation[]` | One entry per distinct problem (same `code` and `detail` reported once), in file order, at most 50. `malformed-xml` is always the last entry when present |

The prolog checks (`file-unreadable`, `not-svg`, `not-utf8`, `doctype-not-allowed`) stop
the check, so those arrive as the only error.

## Violation

One rule the file broke. Four readonly strings, plus the template table.

| Member                 | Type     | Description                                                                                                                                          |
|------------------------|----------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `$violation->code`     | `string` | One of the 21 codes below. Never renamed once released, so application code can switch on it                                                         |
| `$violation->detail`   | `string` | What was found: an element or attribute name, a namespace, or the first 60 characters of a value (then `...`). Taken from the file, not HTML-encoded |
| `$violation->template` | `string` | The English message with one `%s` where the detail goes                                                                                              |
| `$violation->message`  | `string` | `sprintf($template, $detail)`                                                                                                                        |
| `Violation::TEMPLATES` | `array`  | Every template keyed by code, so a translation system can register them all up front                                                                 |

For translation, `template` goes through the translation function and `detail` goes back in
with `sprintf()`. The template holds literal `<` and `>`, so the whole result is encoded:

```php
echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));
```

## Error Codes

Every code with its template. [What Gets Rejected](what-gets-rejected.md) explains each
rule with an example and the fix.

| Code                            | Template                                                                                |
|---------------------------------|-----------------------------------------------------------------------------------------|
| `file-unreadable`               | `Cannot read file %s`                                                                   |
| `not-svg`                       | `This is not an SVG file: it starts with %s`                                            |
| `not-utf8`                      | `SVG files must be UTF-8, this one is %s`                                               |
| `malformed-xml`                 | `The SVG is not well-formed XML: %s`                                                    |
| `doctype-not-allowed`           | `The DOCTYPE declaration is not allowed because %s`                                     |
| `processing-instruction`        | `Processing instructions like <?%s?> are not allowed`                                   |
| `comment-not-allowed`           | `A comment starting with <!--%s is not allowed, HTML parsers close it there`            |
| `root-not-svg`                  | `The root element must be <svg>, not <%s>`                                              |
| `root-namespace-wrong`          | `The root <svg> element must declare xmlns="http://www.w3.org/2000/svg", but it has %s` |
| `element-not-allowed`           | `<%s> is not allowed in uploaded SVGs`                                                  |
| `namespace-not-allowed`         | `Elements from the XML namespace %s are not allowed`                                    |
| `event-handler`                 | `%s= event handler attributes are not allowed`                                          |
| `attribute-not-allowed`         | `The %s attribute is not allowed`                                                       |
| `href-not-allowed`              | `href must reference an element in the same file (#id), not %s`                         |
| `image-href-not-allowed`        | `Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not %s`   |
| `embedded-svg-not-allowed`      | `An embedded SVG image was rejected: %s`                                                |
| `url-not-fragment`              | `url() in the %s attribute must reference an element in the same file (#id)`            |
| `reference-expansion-too-large` | `The references in this file %s`                                                        |
| `css-not-allowed`               | `CSS containing %s is not allowed`                                                      |
| `animation-target-not-allowed`  | `Animating the %s attribute is not allowed`                                             |
| `animation-value-not-allowed`   | `The %s animation attribute contains a URL or scheme`                                   |

## Limits

Fixed values, not configurable.

| Limit                                | Value         | Where it comes from                                    |
|--------------------------------------|---------------|--------------------------------------------------------|
| Bytes searched for the root tag      | 64 KB         | SvgValidator; past this, `malformed-xml`               |
| Errors reported per file             | 50            | SvgValidator; reading stops at the fiftieth            |
| Embedded SVG nesting                 | 3 levels      | SvgValidator; the fourth level rejects                 |
| Elements rendered through references | 100,000       | SvgValidator; `reference-expansion-too-large` above it |
| Ids and references recorded          | 100,000       | SvgValidator; `reference-expansion-too-large` above it |
| Value length in `detail`             | 60 characters | SvgValidator; longer values end with `...`             |
| Element nesting depth                | 256           | libxml2 default; deeper is `malformed-xml`             |
| Single text node                     | 10 MB         | libxml2 default; larger is `malformed-xml`             |
| File size                            | none          | the file is streamed; cap it at upload time            |

---

[← How Browsers Handle SVG](how-browsers-handle-svg.md) | [Documentation Index](README.md) | [Next: Performance →](performance.md)
