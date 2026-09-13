# Performance: 0.02 ms per Icon, 10 ms per Megabyte

Checking a typical icon takes 0.02 ms. Larger files go through at about 100 MB/s, so a
1 MB illustration takes 10 ms and a 10 MB file 100 ms. Memory does not grow with the file:
XMLReader streams it, and even a 50 MB file adds under 3 MB to the PHP process. Files built
to hang a renderer (a reference bomb, an entity-expansion DOCTYPE, 100,000 levels of
nesting) are rejected in under 0.2 ms each, because the check refuses them before anything
expands.

All times on this page are in milliseconds (ms), thousandths of a second. For scale,
response-time research puts the point where people start to notice a delay at about
100 ms, and the upload itself took longer to arrive over the network than any check on this
page takes to run.

The rest of this page is the measurements behind those claims, and the one case where the
cost is worth a thought.

Contents:

- [What a Check Costs](#what-a-check-costs)
- [Memory](#memory)
- [Hostile Files](#hostile-files)
- [When to Care](#when-to-care)
- [Reproducing the Numbers](#reproducing-the-numbers)

## What a Check Costs

Generated files that pass the check and are shaped like a design-tool export: a `<style>`
block, ten gradients in `<defs>`, then groups with transforms and paths with fills, strokes
and class attributes until the file reaches the target size. Each time is the fastest of
seven `checkFile()` calls.

| File   | PHP 8.5  | PHP 8.1  |
|--------|----------|----------|
| 1 KB   | 0.087 ms | 0.10 ms  |
| 100 KB | 1.04 ms  | 1.44 ms  |
| 1 MB   | 10.1 ms  | 13.6 ms  |
| 10 MB  | 99.7 ms  | 135.3 ms |
| 50 MB  | 486.6 ms | 679.6 ms |

From 100 KB up the time is a straight line in the file size: about 100 MB/s on PHP 8.5 and
74 MB/s on PHP 8.1.

**The time goes with elements and attributes, not bytes.** A one-path icon costs 0.02 ms.
The 1 KB file above costs four times that, because its ten gradients are thirty elements
with sixty attributes, and every element is checked against the allowlists and every
attribute value is scanned for a reference. The same rule shows in real files: the slowest
one in the corpus is a 1.5 MB file made of 60,000 tiny elements, 40,000 of them `<use>`,
which takes 97 ms, six times the per-megabyte cost of a file made of long paths.

Real files. The corpus that `tools/fetch-corpus.php` downloads is 5,479 files from icon
sets, browser and renderer test suites, and sanitizer test suites. PHP 8.5 checks all of
them in 0.29 s, about 18,800 files per second.

| Size            | Files | Median   | Mean     | Slowest                                       |
|-----------------|-------|----------|----------|-----------------------------------------------|
| under 10 KB     | 5,436 | 0.019 ms | 0.027 ms | 0.53 ms (an 8 KB `<use>` test file)           |
| 10 KB to 100 KB | 38    | 0.038 ms | 0.081 ms | 0.73 ms (a 22 KB recursive `<use>` test file) |
| 100 KB to 1 MB  | 3     | 3.01 ms  | 11.2 ms  | 28.7 ms (a 506 KB `<use>` test file)          |
| over 1 MB       | 2     | 50.0 ms  | 50.0 ms  | 97.3 ms (the 1.5 MB `<use>` test file above)  |

The 3,460 simple-icons files are the closest thing in the corpus to everyday uploads: their
median is 0.019 ms and the slowest, a 53 KB icon, 0.086 ms.

## Memory

The file is streamed. XMLReader hands the check one element at a time and never builds the
document in memory. What stays in memory is the state the rules need: the open elements,
which ids each element references (kept until the end of the file, since a target can be
defined after the element that uses it), and the violations found, at most 50.

The table shows how much the PHP process's peak memory grew while it checked each file. It
is measured in a fresh process so that libxml2's own allocations count, which
`memory_get_peak_usage()` cannot see. The last column is enshrined/svg-sanitize, which
parses the same file into a DOM, for scale.

| File   | SvgValidator, PHP 8.5 | SvgValidator, PHP 8.1 | svg-sanitize (DOM) |
|--------|-----------------------|-----------------------|--------------------|
| 1 KB   | none measurable       | 684 KB                | 624 KB             |
| 100 KB | none measurable       | 684 KB                | 1.2 MB             |
| 1 MB   | none measurable       | 684 KB                | 9.2 MB             |
| 10 MB  | none measurable       | 684 KB                | 94.2 MB            |
| 50 MB  | 2.8 MB                | 3.2 MB                | 443.5 MB           |

The 684 KB on PHP 8.1 is the same for every size: it is libxml2's parser setting itself up
on first use, not the file. On PHP 8.5 the process's startup peak already covers it. A DOM
parser needs about nine times the file's size; this check does not, which is why the
[Security Model](security-model.md#at-upload-time) page says to cap upload size for a
different reason: a 100 MB file is checked in constant memory and can pass.

## Hostile Files

Files built to hang or exhaust a renderer, and what rejecting each one costs on PHP 8.5.
None of them adds measurable memory.

| File                                                                              | Size      | Rejected as                     | Time     |
|-----------------------------------------------------------------------------------|-----------|---------------------------------|----------|
| Reference bomb: seven layers of ten `<use>` elements, 10 million rectangles       | 1 KB      | `reference-expansion-too-large` | 0.12 ms  |
| Reference loop: two groups that `<use>` each other                                | 165 bytes | `reference-expansion-too-large` | 0.026 ms |
| Entity-expansion DOCTYPE (billion laughs): nine entity layers, a gigabyte of text | 583 bytes | `doctype-not-allowed`           | 0.004 ms |
| 100,000 levels of nesting                                                         | 684 KB    | `malformed-xml`                 | 0.10 ms  |
| 100,000 violations, one per element                                               | 6.5 MB    | `href-not-allowed`              | 0.18 ms  |

Why they are cheap: the expansion check counts the elements under each id and multiplies
along the references, with the total capped at 100,001, so a bomb is arithmetic on a few
dozen numbers, not a render. A DOCTYPE with declarations is refused from the first 64 KB
of the file, before the parser sees it. libxml2 stops at 256 levels of nesting on its own.
And the 50th distinct violation ends the read, so 6.5 MB of violations is read no further
than its first 50 links.

## When to Care

Almost never. Checking the file costs less than receiving it did, so put the check in the
upload handler and move on. If a page is slow, the time is in a query or an API call, not
here.

The exception is many large files in one run: checking a folder of files already on disk
(the loop in [Common Patterns](common-patterns.md#checking-files-already-on-disk)), or a
migration. Budget 10 ms per megabyte on PHP 8.5 and 14 ms on PHP 8.1, plus 0.02 ms per
small file. A folder of 10,000 icons is about 0.2 s. A folder holding 500 MB of
illustrations is about 5 s. Files made of tens of thousands of tiny elements cost up to
six times that per megabyte.

Two details for that case:

- **`checkFile()` streams; `checkString()` cannot.** The string version holds the whole
  file in memory because the caller already does. For files on disk, pass the path.
- **Nothing is cached.** Every call reads the file again. If the same files are checked on
  every request, check them once and store the result.

## Reproducing the Numbers

Every number on this page comes from one script in the repository (it is not part of the
Composer package), which generates the sized and hostile files, times them, and prints these
tables. Run it from a clone with opcache on and xdebug off, which `run.sh` does:

```bash
benchmarks/run.sh
benchmarks/run.sh --corpus=corpus   # add the real files, after php tools/fetch-corpus.php
```

The numbers above are from a dedicated Linux x64 server (Intel Xeon E-2386G) on PHP 8.5.10
and PHP 8.1.34 with libxml2 2.9.7, opcache on and JIT off. The raw output is in
[benchmarks/results.md](../benchmarks/results.md).

Benchmark choices, stated plainly.

- **Fastest of seven, not the mean.** The fastest run is the cost of the work; slower runs
  add whatever else the machine was doing.
- **The files were in the operating system's file cache.** A cold read adds disk time, the
  same for this check as for anything else that reads the file.
- **The generated files pass the check.** A rejected file costs the same or less, since a
  rejection can end the read early. The hostile table shows the extreme.
- **Memory is the growth in the process's peak resident set size** (`VmHWM` on Linux)
  during the timed calls, in a fresh process per file. "None measurable" means the peak did
  not move by a single 4 KB page.
- **The sanitizer column is for scale, not a contest.** enshrined/svg-sanitize rewrites the
  file and this library rejects it, which are different jobs. Its column shows what parsing
  the same file into a DOM costs. The run used version 1.0.0, installed outside the
  repository, since it is GPL licensed and is not a dependency here.
- **The corpus was timed in one process**, three passes, fastest time per file, which is
  how an upload handler runs: the classes already loaded.

---

[← Method Reference](method-reference.md) | [Documentation Index](README.md) | [Next: AI Reference →](ai-reference.md)
