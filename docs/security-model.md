# Security Model

What an uploaded SVG file can do, what SvgValidator prevents, what it does not, and what
the application still has to do at upload time and when serving the file. Read this page
before an upload folder goes live.

Contents:

- [What an SVG Can Do](#what-an-svg-can-do)
- [What SvgValidator Prevents](#what-svgvalidator-prevents)
- [What It Does Not Prevent](#what-it-does-not-prevent)
- [At Upload Time](#at-upload-time)
- [Serving Accepted Files](#serving-accepted-files)
- [Where the File Is Shown](#where-the-file-is-shown)
- [Reporting a Bypass](#reporting-a-bypass)

## What an SVG Can Do

An SVG file is an XML document, and a browser treats it as one. Shown through an `<img>`
tag, the browser runs it with script, interaction and every outside load turned off. Opened
directly, by typing its URL or following a link to it, the same file is a full document on
the site's own origin. In that mode it can:

- **Run script.** `<script>`, `onload="..."` on any element, `<a href="javascript:...">`, and
  HTML inside `<foreignObject>` all execute, with the site's cookies and same-origin access.
  This is the XSS an SVG upload is known for.
- **Load anything.** `<image href="https://...">`, `@import`, `url()` in CSS, and
  `<?xml-stylesheet?>` fetch from other servers, which tells that server who opened the file
  and when.
- **Link anywhere.** `<a href="https://...">` around a logo is a clickable link to any site.
- **Expand.** A DOCTYPE can declare entities that expand to gigabytes, and a chain of
  `<use>` or pattern references can render billions of elements. Browsers cap these;
  server-side rasterizers hang or crash.

## What SvgValidator Prevents

Every rule is an allowlist matched to what Chrome permits in `<img>` mode, and stricter
where Chrome's protection is render-time only. An accepted file:

- **Contains no script.** No `<script>`, no `on*` attribute in any namespace, no
  `javascript:` in any `href`, no `<foreignObject>`, no `<?xml-stylesheet?>`, and no
  animation that could change an `href` or a `style` after the check.
- **Loads nothing.** Every `href` is a same-file reference (`#id`), except on `<image>` and
  `<feImage>` where it may be an embedded `data:` image. Every `url()` in an attribute or in
  CSS is `url(#id)`, except embedded fonts in CSS. There is no `@import`, no external DTD
  effect (nothing is fetched for one), and no element from a namespace that could load.
- **Links nowhere.** `<a href>` is a same-file reference or the file is rejected.
- **Cannot expand.** No DOCTYPE with declarations. Reference loops and chains that would
  render more than 100,000 elements are rejected. libxml2's own limits (256 levels of
  nesting, 10 MB per text node) apply because the parser is never told to lift them.
- **Renders the same in `<img>` and opened directly**, except for `:hover`, `:visited` and
  `begin="click"`, which Chrome ignores in `<img>` and honours when the file is opened.
  Nothing loads and nothing runs when they fire, so they are accepted.
- **Has been checked all the way down.** An SVG embedded as a `data:` image inside the
  file gets the same check, three levels deep, for the sake of server-side rasterizers
  that would otherwise render it with no protection at all.

[What Gets Rejected](what-gets-rejected.md) has each rule with an example.
[How Browsers Handle SVG](how-browsers-handle-svg.md) has the browser side, with the cases
where the rules are stricter than Chrome listed.

## What It Does Not Prevent

Stating the limits plainly is the point of this page.

- **Anything about the picture.** An accepted file can be blank, enormous in pixels,
  offensive, or a copy of another site's logo. The check is about what the file can do,
  not what it shows.
- **File size.** The file is streamed, so a 100 MB upload is checked in constant memory and
  may pass. Cap the size at upload time.
- **The extension and the MIME type.** The check reads the bytes it is given. A `.svgz`
  (gzipped SVG) is rejected as not an SVG file, since it starts with the gzip header rather
  than `<`; an `.html` file is rejected because its root element is not `<svg>`. Refuse
  those extensions at upload time anyway, so nothing depends on the check.
- **Bugs in the renderer.** An accepted file is still parsed and drawn by libxml2, a browser,
  or a rasterizer such as ImageMagick or librsvg. A well-formed file that triggers a bug in
  one of those is outside what an allowlist can see. Keep the rasterizer patched and, for
  ImageMagick, keep its `policy.xml` restrictive.
- **Files served with the wrong headers.** The protection described on this page assumes the
  file reaches the browser as `image/svg+xml`. Serving it as `text/html`, or letting the
  browser guess, changes everything. See the next two sections.
- **SVG pasted into HTML.** Putting the file's text inline in a page (as `<svg>` markup, not
  an `<img>`) makes it part of the page's document, with the page's origin and the page's
  scripts. The rules here were not written for that case; sanitize on output with a DOM
  sanitizer such as DOMPurify.

## At Upload Time

The check covers the contents. Four things stay with the upload handler:

1. **Accept only the `.svg` extension.** Refuse `.svgz` (browsers only render it with a
   `Content-Encoding: gzip` header, which upload folders do not send), `.html`, `.htm`,
   `.xhtml` and `.xml`.
2. **Cap the size.** A logo is kilobytes; an illustration is under a few megabytes.
3. **Choose the stored name yourself.** Never use the uploaded name as the path.
4. **Check before you move.** `checkFile()` on the temporary file, and
   `move_uploaded_file()` only when `ok` is true. [Common Patterns](common-patterns.md#an-upload-handler---checkfile)
   has the complete handler.

## Serving Accepted Files

An accepted file is safe to open directly with no special headers: it has nothing to run
and nothing to load. Three response headers add a second layer in case a rule is ever
bypassed, and they cost nothing:

```text
Content-Type: image/svg+xml
X-Content-Type-Options: nosniff
Content-Security-Policy: sandbox
```

- `Content-Type: image/svg+xml` is what an `<img>` tag needs. Most servers set it from the
  extension already.
- `X-Content-Type-Options: nosniff` stops the browser from guessing a different type from
  the contents, so a file can never be treated as HTML because it looks like HTML.
- `Content-Security-Policy: sandbox` puts the document in an opaque origin with script
  disabled when it is opened directly. The `<img>` display is not affected. This is the
  header Google's own guidance recommends for user-uploaded SVG; with it, even a file that
  somehow got past every rule cannot reach the site's cookies or run script.

Apache, in the upload folder's `.htaccess` or the server config:

```apacheconf
<FilesMatch "\.svg$">
    Header set X-Content-Type-Options "nosniff"
    Header set Content-Security-Policy "sandbox"
</FilesMatch>
```

nginx:

```nginx
location ~* \.svg$ {
    add_header X-Content-Type-Options "nosniff";
    add_header Content-Security-Policy "sandbox";
}
```

If the files never need to be opened directly, `Content-Disposition: attachment` is
stronger still: the browser downloads the file instead of rendering it, while `<img>` tags
on your pages keep working.

## Where the File Is Shown

| Context                                       | What happens                                                                                                                                       |
|-----------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------|
| `<img src="logo.svg">`                        | The target case. Script, interaction and outside loads are off in every browser; animation runs.                                                   |
| CSS `background-image: url(logo.svg)`         | The same restrictions, and browsers also disable animation.                                                                                        |
| Opened directly                               | A full document on your origin. An accepted file has nothing to run or load; the CSP header above makes that hold even if a rule is ever bypassed. |
| Inline `<svg>` in an HTML page                | Not covered. The markup is part of the page. Sanitize on output.                                                                                   |
| Server-side thumbnails (ImageMagick, librsvg) | No browser protection at all, which is why embedded SVG images get the full check. Keep the rasterizer patched.                                    |
| Email                                         | Gmail does not render SVG in any form, and Outlook on the web stopped in 2025. Send a PNG.                                                         |

## Reporting a Bypass

A file that passes the check and can still run script, load an outside resource, or hang a
renderer when shown through `<img>` or opened directly is a bug in this library. Report it
privately through the repository's security advisory page on GitHub, with the file attached,
rather than in a public issue.

---

[← Troubleshooting](troubleshooting.md) | [Documentation Index](README.md) | [Next: How Browsers Handle SVG →](how-browsers-handle-svg.md)
