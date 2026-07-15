---
title: User-Suggested Vendors Spec (test-account gated)
type: analysis
tags: [spec, vendors, uploads, claude-extraction, review-queue]
created: 2026-07-15
sources: []
---

# User-Suggested Vendors — Full Spec (backlog #69)

Drafted 2026-07-15 for backlog #69, per the user's request to review the spec before any implementation begins — **not built yet**. Build is deferred to a future session (with Sonnet 5) and will be gated to `pc_users.test_account = 1` users throughout development, per the plan below. See [[wiki/entities/phase-roadmap]] for the backlog entry.

## Context

Users (both vendor reps and regular buyers) will be able to suggest a new vendor: contact
details + a pricing file. The file is virus-scanned, parsed (strict CSV template instantly,
anything else via the existing Claude extraction pipeline), and the submitter gets a private
score report showing where the vendor's prices fall against the live market. Separately, an
admin can accept the suggestion, which creates a real catalog vendor and commits its prices
through the existing import machinery. During the build the feature is visible only to
`pc_users.test_account = 1` users (flag already exists in the DB; it is NOT currently exposed
in `/api/me` — that's part of this work).

Product decisions already confirmed with the owner:
- **Submitters: both** vendor reps and customers — a `relationship` field on the form distinguishes them.
- **Outcome: score + admin accept** — submitter always gets private feedback; catalog inclusion is a separate manual admin decision.
- **Parsing: template first, pipeline fallback** — downloadable strict CSV parses inline for an instant score; other files (PDF/XLSX/image/ZIP) go through the async Claude extraction queue.

Almost everything needed already exists and gets reused: upload gate + ClamAV scan + quarantine
(`backend/api/vendors/files.php`, `backend/lib/malware_scan.php`), Claude extraction
(`backend/lib/vendor_file_processor.php`), review-queue pattern (`admin/coa_queue.php`,
`vendors/pending_imports.php`), vendor helpers (`saveVendorPhonesAndPaymentMethods`,
`findVendorByPhone`), `pricePerUnit()`/`findExactProductMatch()`, Brevo `emailTemplate()`,
`rateLimit()`, `logAdminAction()`, cache groups.

## Answers to "what am I missing?"

| Consideration | v1 decision |
|---|---|
| Vendor already in catalog (dedup) | Soft-flag via `duplicate_of_vendor_id` (match by phone / lowercased display_name / website host, against catalog AND pending suggestions) — never auto-reject; their file may be fresher. Admin decides. |
| Two users suggest the same vendor | Note in `admin_note` ("also suggested by user #N"); no merge tooling v1. |
| Abuse / Claude cost | 3/hr memcached rate limit + durable 3-per-7-days DB count (memcached restarts wipe counters), 5MB file cap, Claude only ever invoked from the cron worker. |
| Tier gating | None while test-gated. At launch, one-line `requireTier('advanced')` for non-template files only if abused — skip for now. |
| Score anti-gaming (vendor self-submits fake low prices) | Score is private (submitter + admin only), acceptance is always manual, admin card shows `relationship` (vendor_rep = self-submitted) next to the score. No detection heuristics v1. |
| Submitter UX | Single `/suggest-vendor` page: form + CSV-template download + "My Suggestions" list with expandable score report and rejection note. No separate view. |
| Notifications | Brevo email on scored / accepted / rejected only. No "received" email (they see it on-screen), no admin-notify email (admins check the tab). |
| PII (submitting a third party's contact info) | Form disclaimer ("only submit contact info the vendor shares publicly"); suggestions never publicly visible. |
| Failure states | Explicit statuses: `virus_detected` (quarantined, terminal), `parse_failed` (admin can still accept as contact-only vendor, or reject). |
| ClamAV signature staleness | Known risk (CDN IP block prevents updates) — scan still gates uploads; noted, not a blocker. |
| Test gating enforcement | Server-side too, not just nav hiding: `requireSuggestionAccess()` returns 404 for non-test/non-admin. Router guard + `v-if` on the nav link are cosmetic layers on top. |

Skip-for-v1: editing/re-uploading a suggestion, admin re-score button, merge tooling, i18n.

## Database — `database/migrations/036_vendor_suggestions.sql` (+ mirror in `schema.sql`)

```sql
CREATE TABLE IF NOT EXISTS pc_vendor_suggestions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  relationship      ENUM('vendor_rep','customer','other') NOT NULL,
  display_name      VARCHAR(100) NOT NULL,
  contact_name      VARCHAR(100) NULL,
  email             VARCHAR(200) NULL,
  whatsapp          VARCHAR(50)  NULL,
  discord           VARCHAR(100) NULL,
  telegram          VARCHAR(100) NULL,
  website           VARCHAR(300) NULL,      -- safeHttpUrl() at write time
  phones            VARCHAR(300) NULL,      -- comma-separated; normalized only at accept
  payment_methods   VARCHAR(500) NULL,      -- comma-separated enum values
  notes             TEXT NULL,
  original_filename VARCHAR(300) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,  -- relative to backend/storage/
  file_type         ENUM('pdf','xlsx','csv','image','zip') NOT NULL,
  file_size_bytes   INT UNSIGNED NULL,
  is_template_csv   BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('pending_parse','processing','scored','parse_failed','virus_detected','accepted','rejected')
         NOT NULL DEFAULT 'pending_parse',
  extracted_json    JSON NULL,              -- {contact, warnings, prices} — never touches pc_prices until accept
  score_json        JSON NULL,
  duplicate_of_vendor_id INT UNSIGNED NULL,
  admin_note        TEXT NULL,
  vendor_id         INT UNSIGNED NULL,      -- set on accept
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)   REFERENCES pc_users(id)   ON DELETE CASCADE,
  FOREIGN KEY (vendor_id) REFERENCES pc_vendors(id) ON DELETE SET NULL,
  INDEX (status, created_at),
  INDEX (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

File columns live on the suggestion row (one price file per suggestion) — `pc_vendor_files.vendor_id`
is NOT NULL and stays that way; suggestions store at `backend/storage/vendor_suggestions/{userId}/{token16}.{ext}`.

## Status lifecycle

- submit → scan fail → `virus_detected` (quarantined via existing `quarantineFile()`, terminal)
- submit + template CSV → parse + score inline → `scored`
- submit + other file → `pending_parse` → cron claims → `processing` → `scored` | `parse_failed`
- `scored` / `parse_failed` → admin → `accepted` (vendor_id set) | `rejected`

## Backend changes

1. **`backend/helpers.php`** — `userShape()` (line ~306): add `'test_account' => (bool)($u['test_account'] ?? false)`.
2. **`backend/lib/vendor_file_processor.php`** — extract the per-filetype content-building block
   (lines ~30–66 of `processVendorFile()`) into `buildExtractionUserContent(string $fullPath, string $fileType, ?string &$sheetNote): array`,
   shared by vendors and suggestions. Behavior identical for the existing path.
3. **NEW `backend/lib/vendor_suggestions.php`**
   - `parseSuggestionTemplateCsv(string $path): array` — `str_getcsv`; header
     `product_name,spec,price_usd,kit_vial_count,tier_kit_size,vendor_sku`; spec regex
     `/^(\d+(?:\.\d+)?)\s*(mg|mcg|iu|ml)$/i` with mcg→mg ÷1000 (matches extraction prompt rule 5);
     maps rows onto the extraction price shape; skips + warns on bad rows; ≥1 valid row required.
     Returns `{contact:[], warnings:[], prices:[]}`.
   - `scoreSuggestionPrices(PDO $pdo, array $prices): array` — see Score below.
   - `processSuggestion(array $s, string $model): void` — claim → `buildExtractionUserContent()` →
     `callClaudeExtraction(..., null)` (vendorFileId nullable, verified) → store `extracted_json` →
     score → `scored` → scored email. On failure → `parse_failed` + email.
   - `requireSuggestionAccess(): array` — `requireAuth()` + 404 unless `test_account` or `is_admin`.
     `// ponytail: build-phase gate, delete at launch`
4. **NEW `backend/api/vendor_suggestions/index.php`**
   - GET: caller's own rows (id, display_name, status, score_json, dup flag as bool, admin_note, created_at).
   - POST multipart: `requireSuggestionAccess()`; `rateLimit('vendor_suggest_'.$user['id'], 3, 3600)` +
     DB count ≤3 per 7 days; validate display_name + ≥1 contact field; `safeHttpUrl()` on website;
     file gate copied from `vendors/files.php` (ext→type map, 5MB cap, ZIP validation, malware scan —
     fail = quarantine + `virus_detected` row + 422); soft dedup flag; template CSV (header match) →
     parse + score inline → `scored`; anything else → `pending_parse`.
5. **NEW `backend/api/admin/vendor_suggestions.php`**
   - GET list (`?status=`), joined user email + duplicate vendor name.
   - POST `{id}/accept` (must be scored/parse_failed): transaction — INSERT `pc_vendors`
     (is_active=1, is_verified=0, is_hidden=0), `saveVendorPhonesAndPaymentMethods()`, move file to
     `storage/vendor_files/{vendorId}/`, INSERT a real `pc_vendor_files` row, then reuse
     `commitExtractionResult($fileRow, $extracted)` wholesale — exact matches → `pc_prices`, novel
     products → `pc_pending_imports` (existing Review Queue), price history + cache busts included.
     Update suggestion (accepted, vendor_id, reviewed_by/at); `logAdminAction()`; accepted email.
   - POST `{id}/reject`: status + reviewed_by/at + optional admin_note; `logAdminAction()`; rejected email.
6. **`backend/cron/process_async_queue.php`** — second loop inside the existing
   `GET_LOCK('pc_process_async_queue')`: claim `pending_parse` suggestions → `processSuggestion()`.
   No new cron job.
7. **`backend/email.php`** — `sendSuggestionScoredEmail`, `sendSuggestionAcceptedEmail`,
   `sendSuggestionRejectedEmail` on `emailTemplate()`.
8. **`public/index.php`** routes:
   ```php
   'vendor-suggestions'       => 'vendor_suggestions/index.php',
   'admin/vendor-suggestions' => 'admin/vendor_suggestions.php',
   // dynamic:
   'admin/vendor-suggestions/(\d+)/(accept|reject)' => ['admin/vendor_suggestions.php', 'id', 'action'],
   ```

## Score (`score_json`)

Tier-1 (`tier_kit_size == 1`) rows only. Per row: ppu via `pricePerUnit()`; product matched via
`findExactProductMatch()` (canonical_name + aliases), spec via (product_id, spec_label). One query
pulls all active-vendor tier-1 market ppus for the matched pairs (mirrors the `getVendorScorecard()`
min-ppu subquery in `backend/lib/vendor_helpers.php`); percentiles computed in PHP.

```json
{"total_rows":42,"matched_rows":28,"unmatched_names":["..."],
 "would_be_cheapest_pct":32.1,"below_median_pct":67.9,"avg_percentile":24.5,
 "vendor_score":76}
```

`vendor_score = round(100 − avg_percentile)` (0 = priciest, 100 = cheapest); null with a
"not enough catalog overlap" note when `matched_rows == 0`.
`// ponytail: naive composite, reweight when real submissions exist`

## Frontend

- `stores/auth.js`: `isTestAccount = computed(() => !!user.value?.test_account)`.
- `router/index.js`: `/suggest-vendor` → SuggestVendorView, meta `{requiresAuth, requiresTestAccount}`;
  guard redirects non-test/non-admin to /dashboard.
- **NEW `views/SuggestVendorView.vue`**: relationship radio + contact fields + file input
  (`accept=".pdf,.xlsx,.csv,.jpg,.jpeg,.png,.zip"`), "Download CSV template" (client-side Blob —
  no `frontend/public/` dir exists, Vite outDir is `../public/dist`, so no static-asset plumbing),
  PII disclaimer, and a My Suggestions list below (status badges, expandable score report,
  rejection note).
- Nav link (BottomNav/TopBar) behind `v-if="auth.isTestAccount || auth.isAdmin"`.
- **NEW `views/admin/tabs/VendorSuggestionsTab.vue`**: table style (like FeedbackTab, not
  single-card — volume is low), expand → contacts, relationship, dup warning, score, extracted
  price preview, accept/reject buttons. Register in `AdminView.vue` catalog tab group.

## Phases

- **Phase 1 (shippable)**: migration 036 + schema.sql, userShape, lib/vendor_suggestions.php
  (template parse + score + gate), both API files, emails, routes, auth store + router + nav,
  SuggestVendorView, VendorSuggestionsTab. Complete loop for template CSVs: submit → scan →
  instant score → admin accept → live prices.
- **Phase 2**: `buildExtractionUserContent()` refactor, `processSuggestion()`, cron loop —
  non-CSV files now flow `pending_parse` → `scored`.
- **Phase 3 (launch)**: delete `requireSuggestionAccess()` gate + nav condition; decide tier gate.

## Verification

No local PHP — everything over SSH (`ssh -i /home/dusten/projects/peptides_projects/pepcal_key.pem ec2-user@price.themightygroupbuy.com`, remote root `/home/ec2-user/price_themightygroupbuy/`):

1. `php -l` each new/changed backend file on the server after deploy.
2. `bash deploy.sh` from `price_themightygroupbuy/` (runs migrate.sh remotely + smoke check).
3. `mysql --defaults-extra-file=~/.pc_my.cnf tmgb_price -e 'DESCRIBE pc_vendor_suggestions'` and
   status/score spot-queries (`SELECT status, score_json FROM pc_vendor_suggestions ORDER BY id DESC LIMIT 5`).
4. In-browser: non-test user sees no nav link, `/suggest-vendor` redirects, API 404s; test user
   submits template CSV → instant score report; EICAR test file → `virus_detected` + file in
   `backend/storage/quarantine/`; 4th submission in a week → limit error; admin accept → vendor
   appears on Comparison, novel products land in the existing Review Queue; reject → email +
   note visible to submitter.
5. Wiki: log entry + backlog item per project convention.

## Critical files

- `backend/lib/vendor_file_processor.php`, `backend/api/vendors/files.php`,
  `backend/lib/vendor_helpers.php`, `backend/lib/malware_scan.php`
- `backend/helpers.php`, `backend/email.php`, `public/index.php`, `backend/cron/process_async_queue.php`
- `frontend/src/stores/auth.js`, `frontend/src/router/index.js`, `frontend/src/views/admin/AdminView.vue`
