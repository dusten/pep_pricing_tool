---
title: ZIP Upload Spec (Multi-Image/PDF Price Lists)
type: analysis
tags: [vendor, upload, spec, zip, claude-pipeline]
created: 2026-07-02
sources: [phase1-framework]
---

# ZIP Upload Spec — Vendors Sending a Bunch of Images/PDFs as One Price List

**Status: built and deployed 2026-07-02.** New files: `backend/lib/zip_reader.php` (`validateZipEntries()`/`zipToContentBlocks()`), `scanZipEntriesForMalware()` in `malware_scan.php`, migration `013_vendor_file_zip_type.sql`. `callClaudeExtraction()` refactored to a single `$contentBlocks` param as planned below (only caller, `vendor_file_processor.php`, updated same commit). Verified end-to-end via a real multipart upload through the live API (2-image test zip, both images correctly sent as one multi-block Claude call — response referenced "the provided images" plural, confirming they were read together, not as separate calls) plus all four validation cases (happy path, over-cap, stray file type, nested zip) directly against `zipToContentBlocks()`.

Spec for handling `.zip` uploads where the price list is split across multiple image or PDF files (e.g. per-page phone screenshots), decided before any build work. Extends [[wiki/analyses/2026-07-01-vendor-upload-spec|the original vendor-upload spec]] rather than replacing it.

## Core decision: one logical document, not N uploads

A zip full of page images/PDFs is **one vendor submission split across files**, not N independent documents. Treat it that way end to end:

- **One** `pc_vendor_files` row for the whole zip (not one per inner file)
- **One** Claude extraction call with multiple content blocks (the Messages API supports several image/document blocks in a single message) — not N separate calls
- **One** set of resulting `pc_pending_imports` rows, same as any other file

Rejected alternative: unzip into N separate `pc_vendor_files` rows, each run through the existing single-file pipeline unchanged. Simpler to build (zero changes to `claude.php`'s content-block logic), but wrong on two counts — it loses cross-page context (a header on page 1 often qualifies prices on page 2+), and it's N× the API calls (and N× worse for the prompt-caching work already shipped, which specifically rewards consecutive calls sharing the same system prompt — bundling into one call is strictly better here, not just simpler).

## Upload handling

`backend/api/vendors/files.php`'s `$typeMap` gets `'zip' => 'zip'`. `pc_vendor_files.file_type` ENUM gets `'zip'` added (new migration, same `MODIFY COLUMN` pattern as `009_vendor_file_image_type.sql`).

Existing `LimitRequestBody 33554432` (32MB, Apache) already bounds the *compressed* upload size. That does **not** bound decompressed size — a small zip can still decompress to something enormous (zip bomb). Needs its own guard, see Limits below.

## Malware scanning — decided

**Always extract-then-scan each entry individually**, regardless of whether the current `clamd` config happens to support scanning inside zip archives natively. One predictable code path, no dependency on clamd's archive-format support one way or the other. `MALWARE_SCAN_ENABLED` is currently `false` in prod regardless (Cloudflare signature-download block, unresolved) — this only matters once that's re-enabled, but the code path is correct either way.

## Extraction: unzip → content blocks

New function, e.g. `zipToContentBlocks()` alongside `xlsx_reader.php`'s pattern (same `ZipArchive` extension, no new dependency):

1. Open via `ZipArchive`, iterate entries via `statIndex()` **first** (gives declared size without decompressing) — reject before ever calling `getFromIndex()` if entry count or cumulative declared size exceeds the caps below.
2. Filter to `jpg`/`jpeg`/`png`/`pdf` extensions only. **Reject the whole upload** if any entry isn't one of those (a stray `.txt`/`.docx`) — clear error telling the admin what to remove, rather than silently proceeding with a partial batch.
3. **Reject outright** if any entry is itself a `.zip` — no recursive extraction. Zip-bomb-via-nesting is a real vector and there's no legitimate reason a vendor's price-list zip should contain another zip.
4. Sort accepted entries by filename (natural sort, so `page2.jpg` sorts before `page10.jpg`) — vendors who split a price list into per-page files almost always name them in page order.
5. Base64-encode each, build the matching content block (`image` block for jpg/png, `document` block for pdf) in that order, all inside the same user message's content array alongside the existing extraction instruction text.

## `callClaudeExtraction()` — worth refactoring, not just extending again

Current signature already has four params for what's fundamentally one thing ("here's the content, mutually exclusive except image needs an extra media_type"): `$pdfBase64, $plainText, $model, $image`. Adding zip as a fifth bolted-on param compounds a shape that's already awkward. Cleaner: collapse to one `array $contentBlocks` param that the three existing call sites (`processVendorFile()`'s pdf/xlsx/image branches) and the new zip branch all build directly, and `callClaudeExtraction()` just appends the instruction text block and passes it through. Small refactor, but this is the natural point to do it rather than bolt on a sixth param the next time a new source type shows up.

## Async threshold — decided: none needed

`vendorFileQualifiesForAsync()` stays untouched — zips never route to async. With the entry cap below (5 images/PDFs), even worst-case is trivial against this session's already-raised ceilings (`max_tokens` 48000, curl timeout 400s, vhost `Timeout` 900s). No new branch in `vendorFileQualifiesForAsync()`, one less thing to build.

## Frontend

- `VendorsTab.vue`'s upload `accept` attribute: add `.zip`
- Files tab / file repository: no new UI needed beyond showing `file_type: zip` in the existing badge — the zip is one row like any other file, admins can download and inspect it directly if they want to see individual pages

## Limits (zip-bomb / cost protection) — decided

- **Max entries per zip: 3.** Real-world driver, not an arbitrary number — the actual case this is for is WhatsApp auto-zipping a handful of shared images into one download. Tight on purpose, not padded with headroom — this feature is for that specific case, not a stand-in for general multi-page-document upload. Cheap to raise later if a real vendor zip legitimately needs more.
- **Max cumulative decompressed size: 12MB** — sized proportionally to a 3-entry cap of phone-camera images/PDF pages, well above what 3 legitimate pages need, still far short of zip-bomb territory.
- **Nested zips**: rejected, not a tunable — no legitimate use case, pure risk.

If a vendor's zip exceeds either cap, reject with a clear error telling the admin to split it into multiple uploads rather than silently truncating to the first 3 entries (truncating risks quietly dropping real pricing data without anyone noticing).

## Decisions log

All four open questions from the first draft resolved 2026-07-02:

1. **Malware scanning**: always extract-then-scan each entry, not a native-zip-scan-first approach.
2. **Caps**: 3 entries / 12MB, not the originally-proposed 15–20 / 50MB (first revision landed on 5/20MB, corrected down to 3/12MB) — driven by the real WhatsApp-auto-zip use case, not a generic multi-page-document use case.
3. **Async**: never for zips — the small cap makes it unnecessary given already-raised sync timeouts.
4. **Non-image/PDF entries**: reject the whole upload, not skip-with-warning.

## Related

- [[wiki/analyses/2026-07-01-vendor-upload-spec|Original vendor upload spec]] — this extends it, doesn't replace it
- `backend/lib/xlsx_reader.php` — `ZipArchive` precedent, same extension, no new dependency
- `backend/lib/claude.php` — `callClaudeExtraction()`, the refactor point
- `backend/lib/vendor_file_processor.php` — `vendorFileQualifiesForAsync()`, the async-threshold extension point
