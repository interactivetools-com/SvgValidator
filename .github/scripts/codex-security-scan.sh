#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# Uses gpt-6-astra with the CLI's default reasoning effort and scan limits.
# Additional CLI flags can be passed as arguments. Run scans one repo at a time:
# concurrent scans share a sandbox dir in /tmp and kill each other's workers.
# Full pre-release scan:
#   .github/scripts/codex-security-scan.sh --mode deep
set -euo pipefail
cd "$(dirname "$0")/../.."

# There's no exclude flag, so build the path list here: everything except tests,
# gitignored files (vendor, corpus, caches, .idea) and __* scratch notes. Skipping
# those keeps the scan on shipped code; corpus/ is thousands of third-party SVG
# files, and the scratch notes get quoted back as evidence, which we don't want
# steering the results.
shopt -s dotglob
paths=()
for entry in *; do
    if [[ $entry == .git || $entry == tests || $entry == __* ]] || git check-ignore -q "$entry"; then
        continue
    fi
    paths+=(--path "$entry")
done

# The scan prompt lives here (written to a temp file at runtime) so the repo
# needs no scratch file. It says what the library promises and what it does not
# check on purpose; without that, the scanner reports the documented boundaries
# (no MIME check, unencoded detail, files that render as nothing) as findings
# instead of looking for a file that gets past the rules.
prompt_file=$(mktemp)
trap 'rm -f "$prompt_file"' EXIT
cat > "$prompt_file" <<'PROMPT'
This is a whole-library pre-release scan of SvgValidator, a PHP 8.1+ library
that checks an uploaded SVG file and rejects it when it could run script, load
an outside resource, or hang a renderer. It validates and never rewrites.
tests/, vendor/ and corpus/ are excluded on purpose. Review runtime source,
documentation examples, and maintainer tooling (tools/, .github/) in their
actual contexts; the tools download and tally third-party test files on a
developer machine and never run in a deployed web application. Assess the
current checkout for release readiness. Calibrate severity to the evidence;
not every finding is a release blocker.

Core security promises to verify:

- An accepted file cannot run script in any browser, whether shown through an
  <img> tag or opened directly as its own document: no <script>, no attribute
  whose local name starts with "on" in any namespace, no javascript: or any
  other scheme in href, no <foreignObject>, no animation that can set a
  forbidden attribute. Every rule is an allowlist (elements, attributes,
  namespaces, URL forms, data: image types), so anything unknown is rejected.
- An accepted file cannot load anything outside itself: href is a same-file
  reference (#id) or, on image elements, an embedded data: image; CSS may not
  @import, and url() in CSS may only be #id or an embedded font; no DOCTYPE
  with an internal subset; no xml-stylesheet instruction; the parser runs with
  LIBXML_NONET, DTD loading off, and entity substitution off.
- An accepted file cannot hang a renderer: nesting depth, embedded data: SVG
  depth (3 levels), reference chains through use, pattern, gradient, filter
  and feImage elements (expansion capped at 100,000), and libxml2's own limits.
- Every accepted file is safe by these rules alone, with no response headers.
  The headers docs/security-model.md recommends are a second layer.

Intended behavior, not findings:

- Violation detail and message are unencoded text copied from the file; the
  docs say to encode them for the output context. Flag a documentation
  example that puts them into HTML, JavaScript or a log unencoded; do not
  flag the library for returning them raw.
- The library does not check the file name, extension, MIME type or size, and
  the docs say the upload handler must. Do not report their absence.
- A file that passes may render as nothing; the check is about safety, not
  visibility.
- References made through CSS class selectors are not followed when counting
  reference expansion; docs/ai-reference.md lists this as a known gap.
- A small malformed file reports only malformed-xml because libxml2 parses in
  chunks; documented, not a bypass, because the file is rejected either way.
- Rejecting more than Chrome's <img> mode would (external links, foreignObject,
  unknown elements) is by design; over-strictness is not a security finding.

Known limitations and finding criteria:

- A bypass is a file that this library accepts and that runs script, loads an
  outside resource, or exhausts a renderer in current Chrome, Firefox or
  Safari, in <img> or opened directly, or in a server-side rasterizer such as
  librsvg or resvg. Give the concrete file, which rule should have caught it,
  and why it did not.
- Parser differences are the most likely source of a bypass: something
  libxml2 reads one way and a browser another. Check namespace handling,
  attribute and element name case, xlink:href against href, entity and CDATA
  tricks, byte-order marks and encoding declarations, NUL and control bytes,
  invalid UTF-8, nested data: SVG, and anything that could make the root
  element or prolog checks see a different document than the browser does.
- Treat the allowlists in SvgValidator::rules() as the specification. An
  allowed element, attribute or namespace that can itself run script or fetch
  in a browser is a finding; name the browser behavior.
- For resource exhaustion in the library itself, establish how the input
  controls the work: regular expressions with catastrophic backtracking on
  attacker-controlled CSS or URLs, reference counting that a crafted file can
  make quadratic, or memory that grows with input in a path meant to stream.
  checkString() holds the whole string by definition; that is the caller's
  choice, not a finding.

Use prior findings as leads, not proof against the current checkout. Verify
the current implementation and consolidate repeated manifestations of one
root cause. Separate known documented risks, hardening suggestions, and
confirmed current defects. Documentation is counterevidence, not an
exemption: report contradictions, unsafe recommended usage, and new attack
paths with concrete inputs, source-to-sink evidence, prerequisites, and
validation limits.

Prioritize, in order:

1. A file that passes and can run script or load an outside resource in a
   browser or rasterizer: allowlist gaps, parser differences, encoding
   tricks, animation reaching a forbidden attribute.
2. A file that passes and can hang or crash a renderer: reference cycles or
   expansion the counter misses, nesting the depth limits miss.
3. Work in the library that is not bounded by the input size: regex
   backtracking, reference counting, memory growth while streaming.
4. Documentation examples that put a detail or message into an output
   context unencoded.
PROMPT

codex-security scan . "${paths[@]}" \
    --model gpt-6-astra \
    --knowledge-base docs/ai-reference.md \
    --knowledge-base docs/security-model.md \
    --knowledge-base docs/what-gets-rejected.md \
    --knowledge-base docs/how-browsers-handle-svg.md \
    --scan-prompt-file "$prompt_file" \
    "$@"
