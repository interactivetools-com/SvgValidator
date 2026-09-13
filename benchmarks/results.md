# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-09-13, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
5,479 files `tools/fetch-corpus.php` downloads. The sanitizer columns are enshrined/svg-sanitize
1.0.0 installed in a folder outside the repository.

## PHP 8.5

```text
PHP 8.5.10, libxml 2.9.7, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off

## Generated files

| File   | Check time | Throughput | Peak memory added | svg-sanitize time | svg-sanitize memory |
|--------|------------|------------|-------------------|-------------------|---------------------|
| 1 KB   | 0.087 ms   | 19 MB/s    | 0 bytes           | 0.23 ms           | 624 KB              |
| 100 KB | 1.04 ms    | 95 MB/s    | 0 bytes           | 1.90 ms           | 1.2 MB              |
| 1 MB   | 10.1 ms    | 99 MB/s    | 0 bytes           | 17.7 ms           | 9.2 MB              |
| 10 MB  | 99.7 ms    | 100 MB/s   | 0 bytes           | 209.9 ms          | 94.2 MB             |
| 50 MB  | 486.6 ms   | 103 MB/s   | 2.8 MB            | 1113.4 ms         | 443.5 MB            |

## Hostile files

| File                      | Size      | Rejected as                     | Check time | Peak memory added |
|---------------------------|-----------|---------------------------------|------------|-------------------|
| reference bomb            | 1 KB      | `reference-expansion-too-large` | 0.12 ms    | 0 bytes           |
| reference loop            | 165 bytes | `reference-expansion-too-large` | 0.026 ms   | 0 bytes           |
| billion laughs DOCTYPE    | 583 bytes | `doctype-not-allowed`           | 0.004 ms   | 0 bytes           |
| 100,000 levels of nesting | 684 KB    | `malformed-xml`                 | 0.10 ms    | 0 bytes           |
| 100,000 violations        | 6.5 MB    | `href-not-allowed`              | 0.18 ms    | 0 bytes           |

## Corpus: 5,479 files, 10.3 MB, checked in 0.29 s (18,865 files/s, 36 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                                                   |
|-----------------|-------|-----------------|---------------|----------------------------------------------------------------|
| under 10 KB     | 5,436 | 0.019 ms        | 0.027 ms      | 0.53 ms (useDosTest.svg, 8 KB)                                 |
| 10 KB to 100 KB | 38    | 0.038 ms        | 0.081 ms      | 0.73 ms (bug1092-fuzz-recursive-use-stack-overflow.svg, 22 KB) |
| 100 KB to 1 MB  | 3     | 3.01 ms         | 11.2 ms       | 28.7 ms (useDosCleanTwo.svg, 506 KB)                           |
| over 1 MB       | 2     | 50.0 ms         | 50.0 ms       | 97.3 ms (useDosTestTwo.svg, 1.5 MB)                            |

| Source       | Files | Median per file | Mean per file | Slowest file                                                  |
|--------------|-------|-----------------|---------------|---------------------------------------------------------------|
| borewit      | 39    | 0.028 ms        | 0.031 ms      | 0.10 ms (friendly.svg, 2 KB)                                  |
| chromium     | 48    | 0.034 ms        | 0.048 ms      | 0.14 ms (image-preserveAspectRatio-all.svg, 3 KB)             |
| enshrined    | 35    | 0.042 ms        | 3.67 ms       | 97.3 ms (useDosTestTwo.svg, 1.5 MB)                           |
| gecko        | 63    | 0.025 ms        | 0.028 ms      | 0.083 ms (img-and-image-1-ref.svg, 2 KB)                      |
| librsvg      | 84    | 0.030 ms        | 0.093 ms      | 3.01 ms (bug1100-fuzz-layer-nesting-depth.svg, 100 KB)        |
| mediawiki    | 6     | 0.066 ms        | 0.052 ms      | 0.080 ms (buggynamespace-bad.svg, 1 KB)                       |
| payloads     | 8     | 0.026 ms        | 0.027 ms      | 0.045 ms (SVG_XSS_red_lightning.svg, 895 bytes)               |
| resvg        | 1,679 | 0.039 ms        | 0.040 ms      | 0.14 ms (embedded-16bit-png.svg, 22 KB)                       |
| simple-icons | 3,460 | 0.019 ms        | 0.019 ms      | 0.086 ms (elsevier.svg, 53 KB)                                |
| svgo         | 3     | 0.009 ms        | 0.012 ms      | 0.022 ms (invalid.svg, 11 bytes)                              |
| webkit       | 33    | 0.057 ms        | 0.056 ms      | 0.17 ms (image-with-nested-data-uri-images.svg, 5 KB)         |
| wpt-as-image | 2     | 0.024 ms        | 0.024 ms      | 0.025 ms (sprite-sheet-fractional-size-helper.svg, 288 bytes) |
| wpt-embedded | 19    | 0.040 ms        | 0.28 ms       | 2.62 ms (image-large-bitmap.svg, 1.4 MB)                      |

```

## PHP 8.1

```text
PHP 8.1.34, libxml 2.9.7, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off

## Generated files

| File   | Check time | Throughput | Peak memory added | svg-sanitize time | svg-sanitize memory |
|--------|------------|------------|-------------------|-------------------|---------------------|
| 1 KB   | 0.10 ms    | 16 MB/s    | 680 KB            | 0.21 ms           | 500 KB              |
| 100 KB | 1.44 ms    | 68 MB/s    | 684 KB            | 1.99 ms           | 1.0 MB              |
| 1 MB   | 13.6 ms    | 74 MB/s    | 684 KB            | 18.8 ms           | 10.1 MB             |
| 10 MB  | 135.3 ms   | 74 MB/s    | 684 KB            | 209.2 ms          | 93.5 MB             |
| 50 MB  | 679.6 ms   | 74 MB/s    | 3.2 MB            | 1056.4 ms         | 446.5 MB            |

## Hostile files

| File                      | Size      | Rejected as                     | Check time | Peak memory added |
|---------------------------|-----------|---------------------------------|------------|-------------------|
| reference bomb            | 1 KB      | `reference-expansion-too-large` | 0.13 ms    | 684 KB            |
| reference loop            | 165 bytes | `reference-expansion-too-large` | 0.026 ms   | 684 KB            |
| billion laughs DOCTYPE    | 583 bytes | `doctype-not-allowed`           | 0.003 ms   | 684 KB            |
| 100,000 levels of nesting | 684 KB    | `malformed-xml`                 | 0.10 ms    | 684 KB            |
| 100,000 violations        | 6.5 MB    | `href-not-allowed`              | 0.19 ms    | 684 KB            |

## Corpus: 5,479 files, 10.3 MB, checked in 0.30 s (18,181 files/s, 34 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                                                   |
|-----------------|-------|-----------------|---------------|----------------------------------------------------------------|
| under 10 KB     | 5,436 | 0.019 ms        | 0.027 ms      | 0.56 ms (useDosTest.svg, 8 KB)                                 |
| 10 KB to 100 KB | 38    | 0.038 ms        | 0.084 ms      | 0.78 ms (bug1092-fuzz-recursive-use-stack-overflow.svg, 22 KB) |
| 100 KB to 1 MB  | 3     | 3.47 ms         | 11.4 ms       | 29.0 ms (useDosCleanTwo.svg, 506 KB)                           |
| over 1 MB       | 2     | 53.7 ms         | 53.7 ms       | 104.7 ms (useDosTestTwo.svg, 1.5 MB)                           |

| Source       | Files | Median per file | Mean per file | Slowest file                                                  |
|--------------|-------|-----------------|---------------|---------------------------------------------------------------|
| borewit      | 39    | 0.028 ms        | 0.031 ms      | 0.11 ms (friendly.svg, 2 KB)                                  |
| chromium     | 48    | 0.034 ms        | 0.049 ms      | 0.16 ms (image-preserveAspectRatio-all.svg, 3 KB)             |
| enshrined    | 35    | 0.042 ms        | 3.89 ms       | 104.7 ms (useDosTestTwo.svg, 1.5 MB)                          |
| gecko        | 63    | 0.025 ms        | 0.028 ms      | 0.086 ms (img-and-image-1-ref.svg, 2 KB)                      |
| librsvg      | 84    | 0.030 ms        | 0.10 ms       | 3.47 ms (bug1100-fuzz-layer-nesting-depth.svg, 100 KB)        |
| mediawiki    | 6     | 0.066 ms        | 0.052 ms      | 0.082 ms (buggynamespace-bad.svg, 1 KB)                       |
| payloads     | 8     | 0.025 ms        | 0.027 ms      | 0.046 ms (SVG_XSS_red_lightning.svg, 895 bytes)               |
| resvg        | 1,679 | 0.040 ms        | 0.042 ms      | 0.14 ms (embedded-16bit-png.svg, 22 KB)                       |
| simple-icons | 3,460 | 0.018 ms        | 0.019 ms      | 0.089 ms (elsevier.svg, 53 KB)                                |
| svgo         | 3     | 0.010 ms        | 0.012 ms      | 0.019 ms (invalid.svg, 11 bytes)                              |
| webkit       | 33    | 0.058 ms        | 0.058 ms      | 0.17 ms (image-with-nested-data-uri-images.svg, 5 KB)         |
| wpt-as-image | 2     | 0.024 ms        | 0.024 ms      | 0.026 ms (sprite-sheet-fractional-size-helper.svg, 288 bytes) |
| wpt-embedded | 19    | 0.040 ms        | 0.28 ms       | 2.62 ms (image-large-bitmap.svg, 1.4 MB)                      |

```
