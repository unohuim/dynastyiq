# Lineup image OCR

The lineup importer uses **Tesseract.js**, running under the existing Node runtime.
Production npm dependencies include the WebAssembly OCR engine, English trained
data (`@tesseract.js-data/eng`), and `image-size` for pre-decode pixel checks.
They are declared in `package.json` and resolved in `package-lock.json`.

Normal `npm ci` installs these dependencies. There is no Python environment,
system Tesseract executable, paid vision API, Forge-specific setup, or separate
model preparation command. Keep production npm dependencies available to queue
workers, rather than deploying only Vite's compiled browser assets.

## Import behavior

- Official NHL rosters and verified complete caption lineups retain priority.
- For date-eligible X posts with incomplete caption coverage, photo attachments
  are downloaded and passed to `scripts/lineup-ocr.mjs` by `NhlLineupImageOcr`.
- Word coordinates reconstruct top-to-bottom rows and left-to-right names. This
  is a layout heuristic, not proof of correct lines or pairings.
- Photo text passes the existing `NhlLineupTextParser` and
  `NhlLineupPlayerResolver`; all existing approval rules still apply.
- Multiple photos on the same post may contribute a roster, but remain one
  publisher's evidence. Videos and video previews are skipped.
- Original captions stay in `post_text`. Accepted observations retain
  `lineup_text` and `ocr[]` in `raw_evidence`, including URLs, word boxes,
  confidence, extracted rows, status, and errors. Local per-post Markdown includes
  the same evidence. Runtime failures also emit a structured warning.
- Failed or low-confidence OCR does not erase valid caption evidence or invent
  players. The existing import continues to other posts/sources.

## Runtime and safety

Super admins can also upload a JPEG/PNG or paste a clipboard image into the
manual lineup modal on `/games`. Text is optional when an image is present.
The existing manual endpoint accepts multipart `team_abbrev`, `text`, and `image`
fields; JSON text submissions remain supported. The upload is processed with
the same OCR runner, then the same full-lineup verification, without requesting
X. Original upload bytes are not kept; accepted raw evidence retains SHA-256,
MIME type, size, extracted rows and text, and the submitting user. Errors remain
in the modal and do not create an observation. Uploads are limited to 10 MiB;
PHP/web-server request limits also apply.

`config/lineup_ocr.php` owns defaults. OCR is enabled by default. The Node binary
uses `LINEUP_OCR_NODE`, falls back to an existing `FANTRAX_NODE_PATH`, then `node`
on the queue worker's PATH. `LINEUP_OCR_ENABLED=false` disables extraction.

Downloads permit only HTTPS `pbs.twimg.com/media/` URLs, with no redirects or
embedded credentials. JPEG and PNG are supported. Limits are four images per
post, 10 MiB per download, and 20 million pixels per image. Temporary files are
removed on success or failure.

Downloads time out after 10 seconds, the Node subprocess after 25 seconds, and
each discovery attempt has a 120-second OCR deadline. Cached extractions remain
usable beyond that deadline. Successful/empty/uncertain results are cached for
seven days by URL, engine revision and threshold; failures for one minute.

Word confidence is normalized to 0–1. If any recognized word is below the default
0.7 threshold, all parsing text from that image is withheld while raw OCR rows
remain available for review. This avoids dropping uncertain names and shifting
other players into their slots. Busy graphics may require manual entry; handwriting
is not guaranteed to work. Real lineup-image accuracy still requires evaluation.

English data is read from its installed npm package with the engine's disk cache
disabled. Imports do not fetch models from a CDN or write model files into the
release directory. Laravel's extraction-result cache remains separate.

## Testing

PHP coverage mocks HTTP and OCR processes to exercise caching, failure handling,
verification and observation persistence. JavaScript coverage checks row ordering
and confidence handling without loading the OCR engine or making network calls.
Neither substitutes for testing actual lineup images.

## Provider references

- [Tesseract.js](https://github.com/naptha/tesseract.js)
- [Local engine and language paths](https://github.com/naptha/tesseract.js/blob/master/docs/local-installation.md)
- [English language npm package](https://www.npmjs.com/package/@tesseract.js-data/eng)
