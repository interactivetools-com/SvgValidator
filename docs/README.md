# SvgValidator Documentation

Welcome to the SvgValidator docs. SvgValidator checks an uploaded SVG file against what
browsers allow an SVG to do inside an `<img>` tag, and rejects anything that could run
script, load an outside resource, or hang a renderer. It reports every problem it finds and
never rewrites the file.

New to SvgValidator? Read the first three pages in order. The rest are standalone: open
whichever matches your task.

## The Basics (read in order)

1. [Getting Started](getting-started.md) - Install, check your first upload, and read a `Result`.
2. [What Gets Rejected](what-gets-rejected.md) - Every rule in plain English, one section per error code, with an example and the fix.
3. [What Gets Through](what-gets-through.md) - The allowlists: elements, attributes, namespaces, URL forms, CSS and animation.

## Everyday Use

- [Common Patterns](common-patterns.md) - Copy-paste recipes: an upload handler, showing errors, translating codes, logging, checking a folder of your own files.
- [Troubleshooting](troubleshooting.md) - Exact messages as headings, what happened, and the fix, including the design-tool exports that reject and why.

## Lookup

- [Security Model](security-model.md) - What an SVG can do, what this library prevents, what it does not, and the headers to serve accepted files with.
- [How Browsers Handle SVG](how-browsers-handle-svg.md) - What Chrome, Firefox and Safari do with an SVG in `<img>`, where the rules mirror them and where they are stricter.
- [Method Reference](method-reference.md) - `checkFile()`, `checkString()`, `rules()`, and the `Result` and `Violation` fields.
- [AI Reference](ai-reference.md) - The complete API and every rule in one dense file, written for AI coding assistants.

---

[← Back to main README](../README.md)
