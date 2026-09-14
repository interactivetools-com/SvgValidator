# Getting Started

Install SvgValidator, check an uploaded file, and read the result. By the end of this page
you will have an upload handler that stores accepted SVG files and tells the uploader
exactly why a rejected one failed.

Contents:

- [Installation](#installation)
- [Your First Check - `checkFile()`](#your-first-check---checkfile)
- [Reading a Result](#reading-a-result)
- [The Mental Model](#the-mental-model)
- [Checking a String - `checkString()`](#checking-a-string---checkstring)
- [Showing Errors to the Uploader](#showing-errors-to-the-uploader)
- [What Next](#what-next)

## Installation

```bash
composer require itools/svgvalidator
```

Requirements: PHP 8.1+ with `ext-xmlreader` and `ext-libxml`. Both are part of a default
PHP build. There are no other dependencies.

## Your First Check - `checkFile()`

`checkFile()` takes the path of the uploaded file. The result says whether it passed and, if
not, why:

```php
use Itools\SvgValidator\SvgValidator;

$result = SvgValidator::checkFile($_FILES['logo']['tmp_name']);

if ($result->ok) {
    move_uploaded_file($_FILES['logo']['tmp_name'], 'uploads/logo.svg');
} else {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";
    }
}
```

The file is read once, front to back, and never changed. When it passes, store the bytes you
were given. When it fails, there is nothing to store: the uploader fixes the file and tries
again, and the messages tell them what to fix.

Nothing throws. A missing or unreadable path comes back as a rejection too, with the code
`file-unreadable`, so an upload handler has one code path for every outcome.

## Reading a Result

`checkFile()` and `checkString()` return a `Result` with two readonly properties:

```php
$result->ok;       // true when the file passed
$result->errors;   // Violation[] in file order, empty when ok, at most 50
```

Each `Violation` has four readonly strings:

```php
$violation->code;       // 'element-not-allowed'                      stable; switch on this
$violation->detail;     // 'script'                                    what was found, from the file
$violation->template;   // '<%s> is not allowed in uploaded SVGs'      the message with a %s for the detail
$violation->message;    // '<script> is not allowed in uploaded SVGs'  template and detail combined
```

`code` is one of 21 fixed strings that never change between releases, so your code can react
to a specific rule. Text from the uploaded file goes in `detail`: an element name, an
attribute name, or the first 60 characters of a URL. It is not HTML-encoded, so encode it
(or `message`) before output, as the example above does.

The same problem is reported once: five `<script>` elements give one error, and so do five
`onclick` attributes; `onclick` and `onload` together give two. A file that is not
well-formed XML ends the list with `malformed-xml`, since nothing after a parse error can be
checked.

## The Mental Model

Every rule is an allowlist, except CSS. Elements, attributes, XML namespaces, URL forms and
animation targets each have a list of what passes, and anything not on a list is rejected;
CSS passes as written except the few tokens that could load or escape. A new browser
feature is closed until someone adds it, which costs a re-export and a one-line change
rather than an XSS.

The lists match what Chrome lets an SVG do when it is shown through an `<img>` tag: no
script, no interaction, and no loading of anything outside the file except `data:` URLs.
Same-file references (`fill="url(#gradient)"`, `<use href="#icon">`) and embedded images
are fine, because they never leave the file. Where Chrome only neutralizes something at
render time, such as a `<script>` element it parses but never runs, SvgValidator rejects
it, because that protection is gone the moment the file is opened directly.

With that model, the rest of the rules are predictable: if a construct could run code,
load something, or render differently in an `<img>` than on its own (hover and click effects
excepted, since nothing loads or runs when they fire), it is rejected.
[What Gets Rejected](what-gets-rejected.md) lists every rule with an example.

## Checking a String - `checkString()`

The same check on SVG source you already have in memory, for a file fetched from storage or
a test:

```php
$result = SvgValidator::checkString('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
$result->ok;   // true
```

The two methods give the same result for the same bytes. `checkFile()` streams from disk,
so memory use does not grow with the file; `checkString()` already has the whole string.

## Showing Errors to the Uploader

The messages are written for the person who uploaded the file, not for a log:

```text
<script> is not allowed in uploaded SVGs
Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not https://example.com/photo.jpg
```

Each names the finding and stops. It does not tell the uploader what to do about it,
because that depends on their design tool. [Common Patterns](common-patterns.md#adding-a-fix-hint-per-code)
shows how to add a hint per code, and
[Troubleshooting](troubleshooting.md) has the fix for each message. For translation,
`template` is the message with `%s` where the detail goes: translate it, then put `detail`
back with `sprintf()`. `Violation::TEMPLATES` lists every template by code.

## What Next

- [What Gets Rejected](what-gets-rejected.md) - each of the 21 codes, with the smallest
  file that triggers it and the fix.
- [What Gets Through](what-gets-through.md) - the allowlists themselves.
- [Security Model](security-model.md) - the headers to serve accepted files with. An
  accepted file is still XML that a browser will render, so this page is worth reading
  before the upload folder goes live.

---

[← Documentation Index](README.md) | [Next: What Gets Rejected →](what-gets-rejected.md)
