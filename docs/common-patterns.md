# Common Patterns

Recipes for the tasks that come up around an SVG upload field: the handler itself, showing
the uploader something they can act on, translating and logging, and checking files that
are already on disk. Each example is complete and ready to adapt.

Contents:

- [An Upload Handler - `checkFile()`](#an-upload-handler---checkfile)
- [Adding a Fix Hint per Code](#adding-a-fix-hint-per-code)
- [Translating Messages - `Violation::TEMPLATES`](#translating-messages---violationtemplates)
- [Logging Rejections](#logging-rejections)
- [Reacting to One Rule](#reacting-to-one-rule)
- [Checking Files Already on Disk](#checking-files-already-on-disk)
- [Checking Your Own Files in a Test - `checkString()`](#checking-your-own-files-in-a-test---checkstring)

## An Upload Handler - `checkFile()`

The whole handler: refuse anything that is not a `.svg` upload of sensible size, then check
the contents, then store the file under a name the server chose.

```php
use Itools\SvgValidator\SvgValidator;

$upload = $_FILES['logo'] ?? null;
if ($upload === null || $upload['error'] !== UPLOAD_ERR_OK) {
    exit('No file was uploaded.');
}
if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'svg') {
    exit('Only .svg files are accepted.');
}
if ($upload['size'] > 2 * 1024 * 1024) {
    exit('The file is larger than 2 MB.');
}

$result = SvgValidator::checkFile($upload['tmp_name']);
if (!$result->ok) {
    echo '<p>This SVG cannot be used as uploaded:</p><ul>';
    foreach ($result->errors as $violation) {
        echo '<li>', htmlspecialchars($violation->message), '</li>';
    }
    echo '</ul>';
    exit;
}

$stored = 'uploads/' . bin2hex(random_bytes(8)) . '.svg';
move_uploaded_file($upload['tmp_name'], $stored);
```

The extension and size checks are yours to do: the library looks only at the contents.
The [Security Model](security-model.md#at-upload-time) page says why `.svgz`, `.html` and
`.xml` stay refused and what headers the `uploads/` folder needs.

## Adding a Fix Hint per Code

A message names what was found and stops. The hint depends on the uploader's design tool,
which the library does not know and your application might. Map the codes you care about
to a hint and fall back to the message alone:

```php
const SVG_HINTS = [
    'doctype-not-allowed'     => 'In Illustrator, export again with "Preserve Illustrator Editing Capabilities" unchecked.',
    'image-href-not-allowed'  => 'Export with images embedded rather than linked.',
    'css-not-allowed'         => 'Export with fonts embedded, or convert text to outlines.',
    'element-not-allowed'     => 'Convert text and effects to paths and export again.',
];

foreach ($result->errors as $violation) {
    $hint = SVG_HINTS[$violation->code] ?? '';
    echo '<li>', htmlspecialchars($violation->message), $hint === '' ? '' : " $hint", '</li>';
}
// <li>The DOCTYPE declaration is not allowed because it contains an internal DTD subset (entity declarations) In Illustrator, export again with "Preserve Illustrator Editing Capabilities" unchecked.</li>
```

[Troubleshooting](troubleshooting.md) has the fix for each message, written for the person
who exported the file.

## Translating Messages - `Violation::TEMPLATES`

Each violation carries its message as a template with one `%s`, so a translation system can
translate the fixed part and keep the detail:

```php
$translated = sprintf(t($violation->template), $violation->detail);
echo htmlspecialchars($translated);
// with a German t(): <script> ist in hochgeladenen SVG-Dateien nicht erlaubt
```

`Violation::TEMPLATES` is every template keyed by code, so the strings can be registered up
front instead of discovered one rejection at a time:

```php
foreach (Violation::TEMPLATES as $code => $template) {
    registerTranslatableString($template);   // your translation system's registration call
}
```

## Logging Rejections

Log the code and the detail, not the message: the code is stable and greppable, and the
detail is what the file actually contained.

```php
if (!$result->ok) {
    $summary = implode(', ', array_map(fn($v) => "$v->code($v->detail)", $result->errors));
    error_log("SVG upload rejected from " . ($_SERVER['REMOTE_ADDR'] ?? '?') . ": $summary");
}
// SVG upload rejected from 203.0.113.9: element-not-allowed(script), event-handler(onload)
```

A rejection is normal traffic (a designer's export with a linked image), so log it at the
same level as any other validation failure. A run of `event-handler` and `href-not-allowed`
rejections from one address is worth a look.

## Reacting to One Rule

Switch on `code` when the application should treat one rule differently. Here an external
link, which is common in files exported from a web page, gets its own explanation:

```php
foreach ($result->errors as $violation) {
    echo match ($violation->code) {
        'href-not-allowed' => 'Links to other sites are not allowed in uploaded images. Remove the link and export again.',
        default            => htmlspecialchars($violation->message),
    }, '<br>';
}
```

Codes never change once released. Messages can be reworded, so match on `code`, never on
`message`.

## Checking Files Already on Disk

To see what the rules would say about SVG files a site already serves, loop a folder and
list the rejections. Nothing is changed or deleted; the loop only reports.

```php
use Itools\SvgValidator\SvgValidator;

foreach (glob('/var/www/uploads/*.svg') as $path) {
    $result = SvgValidator::checkFile($path);
    if ($result->ok) {
        continue;
    }
    echo basename($path), "\n";
    foreach ($result->errors as $violation) {
        echo "  $violation->code: $violation->detail\n";
    }
}
// header-logo.svg
//   image-href-not-allowed: photo.jpg
// old-banner.svg
//   href-not-allowed: https://example.com/
```

Two things to expect from real files: linked images (`image-href-not-allowed` with a file
name as the detail) and `<a>` links to a website (`href-not-allowed`). Both are covered on the
[Troubleshooting](troubleshooting.md) page.

## Checking Your Own Files in a Test - `checkString()`

When a project ships its own SVG files (icons, a logo), a test that checks them keeps a
future edit from adding something the upload rules would refuse:

```php
public function testShippedIconsPassTheUploadRules(): void
{
    foreach (glob(__DIR__ . '/../public/icons/*.svg') as $path) {
        $result = SvgValidator::checkString(file_get_contents($path));
        $this->assertTrue($result->ok, basename($path) . ': ' . implode('; ', array_map(fn($v) => $v->message, $result->errors)));
    }
}
```

---

[← What Gets Through](what-gets-through.md) | [Documentation Index](README.md) | [Next: Troubleshooting →](troubleshooting.md)
