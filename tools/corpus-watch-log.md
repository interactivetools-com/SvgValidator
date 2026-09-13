# Corpus watch log

One entry per run of `tools/corpus-watch.md`, newest last. The date of the last entry is the
"since" date for the next run.

## 2026-09-13

Baseline. All 15 sources downloaded for the first time; tree hashes recorded in each
`corpus/<name>/SOURCE.json`. Tally: 5193 accepted, 221 rejected, no unexpected acceptance in a
must-reject set. No advisory search yet; the next run covers everything published after this date.

## 2026-09-13 (source research, not a scheduled run)

Six candidate sources researched and rated. Added: librsvg crash fixtures (GitLab, 84 files,
LGPL-2.1, xmlns fixup). Not added: W3C SVG 1.1 suite, CairoSVG, Batik, html5sec/PortSwigger,
Loofah/OWASP/.NET HtmlSanitizer; reasons are in tools/corpus-watch.md section 1.

- librsvg's pattern chain bomb (errors/bug515-pattern-billion-laughs.svg) was accepted: the
  expansion check only followed <use>. It now follows every same-file reference that renders
  its target (url(#id) in any attribute, href on use, pattern, gradients, filter and feImage).
  The code is now `reference-expansion-too-large`. 33 resvg cycle tests moved from accepted to
  rejected as a result; all are loops by design.
