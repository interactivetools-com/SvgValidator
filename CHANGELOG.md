# SvgValidator Changelog

> **Upgrading?** See [UPGRADING.md](UPGRADING.md) for the checks that matter,
> per version - tagged releases roll up every change since the previous tag.
> Versions bundled with CMS Builder are marked on their sections.

## [UNRELEASED]

First release. SvgValidator checks an uploaded SVG file against what browsers allow an SVG
to do inside an `<img>` tag and rejects anything that could run script, load an outside
resource, or hang a renderer. It never rewrites the file.

### Added

- **Three static methods:** `SvgValidator::checkFile($path)`, `SvgValidator::checkString($svg)`,
  and `SvgValidator::rules()`. Nothing throws for a bad file; a missing path returns a
  result with `file-unreadable`.
- **A `Result` with `ok` and `errors`**, and a `Violation` with `code`, `detail`, `template`
  and `message`. Errors are deduplicated by code and detail and capped at 50.
  `Violation::TEMPLATES` holds every message template for translation.
- **21 error codes**, one per rule, listed in [docs/what-gets-rejected.md](docs/what-gets-rejected.md).
  Codes are stable from this release on.
- **Allowlists** for elements, attributes, prefixed attributes, design-tool namespaces, URL
  forms, CSS tokens and animation targets, matched to Chrome's `<img>` mode and stricter
  where that protection is render-time only. Calibrated against about 5,000 files from
  sixteen public test suites and icon sets.
- **Embedded SVG images** (`data:image/svg+xml`) get the full check, three levels deep, for
  server-side rasterizers.
- **Reference loops and expansion bombs** are rejected: every same-file reference that
  renders its target is followed, and a loop or more than 100,000 rendered elements fails
  with `reference-expansion-too-large`.

### Requirements

- PHP 8.1+, `ext-xmlreader`, `ext-libxml`. No other dependencies.
