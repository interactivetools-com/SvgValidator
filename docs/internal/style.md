# Documentation Style Guide

The shared writing standards for all InteractiveTools libraries (voice,
vocabulary, page structure, code examples, method tables, renderer facts)
live in the team's
[internal docs repo](https://github.com/itools-internal/docs/tree/main/open-source)
under open-source/ (private, team access only). This file holds
SvgValidator-specific additions only.

- **Reader assumption:** a working PHP programmer who handles file uploads
  and has never looked inside an SVG file. Explain what an element does the
  first time a page uses it, in half a sentence.
- **"SVG" is capitalized in prose and takes "an"**: an SVG file, an SVG
  upload. Lowercase only inside code (`image/svg+xml`, `.svg`).
- **Elements in code font with angle brackets, attributes bare:** `<script>`,
  `<use>`, `<foreignObject>`; `href`, `onload`, `style`. The event handler
  family is written `on*`. HTML elements the same way (`<img>`, `<iframe>`).
- **Error codes always in backticks:** `element-not-allowed`. Codes are the
  API: applications switch on them, so a page never paraphrases one.
- **"Rejected" and "accepted"** are the two outcomes of a check, and a rule
  "allows" or "rejects" a thing. "Sanitize", "clean", "strip" and "remove"
  name what the library does not do; use them only when contrasting with
  sanitizers.
- **The yardstick is "Chrome's `<img>` mode"**, written that way every time.
  Where a rule is stricter or looser than Chrome, the page says so in those
  words, and the table in [design-decisions.md](design-decisions.md) is the
  source for which cases are which. "Browsers" alone means Chrome, Firefox and
  Safari agree; when they differ, name the engine.
- **A same-file reference is written `#id`:** "href must be a same-file
  reference (`#id`)". Not "fragment", "anchor" or "local reference" in the
  public pages; `url-not-fragment` keeps its name as a code.
- **Example SVGs are complete and minimal:** the root is always
  `<svg xmlns="http://www.w3.org/2000/svg">`, the file is the smallest one
  that shows the rule, and the outcome follows as an XML comment on its own
  line, code and detail for a rejection:

  ```xml
  <svg xmlns="http://www.w3.org/2000/svg">
    <script>alert(1)</script>
  </svg>
  <!-- rejected: element-not-allowed, detail "script" -->
  ```

- **Troubleshooting headings are the message with the detail filled in**
  (`<foreignObject> is not allowed in uploaded SVGs`), since that is what a
  reader pastes into search. The `%s` template form appears only in
  `method-reference.md` and `ai-reference.md`.
- **Design tools are named:** Illustrator, Figma, Inkscape, Affinity Designer,
  Sketch. "Design tool" is the generic. CMS Builder may be named as a user of
  the library; nothing about its internals.
- **Corpus numbers are dated.** A page that cites the corpus ("3,460 of 3,460
  simple-icons files pass") says the date of the tally next to the number, and
  the number comes from `tools/tally.php`, not from memory.
