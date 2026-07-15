# Feature Spec: User-Suggested Vendors

PHP 8.2 / PDO / MariaDB (pc_ prefix), Vue 3 + Pinia, Memcached, Brevo. Routes in public/index.php. All paths verified against the codebase.

## Verified facts (exploration)

- `userShape()` at backend/helpers.php:306 — does NOT expose `test_account`. Must add.
- `callClaudeExtraction(string $system, array $userContent, string $model, ?int $vendorFileId = null)` (backend/lib/claude.php:242) — vendorFileId already nullable; pc_claude_call_log.vendor_file_id is NULLable. Suggestions can log with NULL.
- `processVendorFile()` (backend/lib/vendor_file_processor.php:26) builds per-filetype `$userContent` then calls Claude then `commitExtractionResult()`. The content-building block (lines ~30–66) is the piece to extract into a shared helper; commit stays vendor-only.
- `commitExtractionResult(array $file, array $result)` needs a pc_vendor_files-shaped row (`id`, `vendor_id`) — reusable at accept time by inserting a real pc_vendor_files row for the suggestion's file.
- Extraction price shape: `{canonical_name, spec_label, numeric_value, unit, price_usd, kit_vial_count, tier_kit_size, vendor_sku, non_standard_kit, warning}`. `pc_specifications.unit` is ENUM('mg','iu','ml','other'). `pricePerUnit()` at backend/helpers.php:39.
- Upload gate pattern: backend/api/vendors/files.php — ext→type map, malware scan (fail = quarantineFile + failed row + 422), SHA-256 dedup. MALWARE_SCAN_ENABLED env flag. RISK: ClamAV signature updates currently blocked by a CDN IP block — scans may run with stale signatures; feature still ships, note it.
- Async: files/process.php marks 'processing'; backend/cron/process_async_queue.php polls under GET_LOCK('pc_process_async_queue').
- Review queue pattern: backend/api/vendors/pending_imports.php + admin/coa_queue.php (status pending/approved/rejected, reviewed_by/at, POST /{id}/action).
- Email: backend/email.php — `sendEmail()`, `emailTemplate($title,$body)`, `_btn()`.
- Rate limit: `rateLimit(key,max,window)` memcached fail-closed (feedback.php uses 10/hr per user). `requireTier()` tiers: free/advanced/pro/expert; admins bypass.
- Dedup helpers: `findVendorByPhone()`, `normalizePhoneForMatch()` in vendor_helpers.php. `findExactProductMatch()` checks canonical_name + aliases (price_import.php:20).
- Frontend: router index.js meta requiresAuth/requiresAdmin; auth store `isAdmin` computed; BottomNav has /submit-coa link; AdminView.vue two tab groups importing tabs from views/admin/tabs/.
- Vite outDir ../public/dist; no frontend/public dir exists. CSV template: generate client-side (Blob download) — zero backend.
- Deploy: `bash deploy.sh` (rsync to price.themightygroupbuy.com, runs migrate.sh remotely). No local PHP — lint over SSH.

## Table DDL — database/migrations/036_vendor_suggestions.sql (mirror CREATE TABLE in schema.sql)

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
  website           VARCHAR(300) NULL,     -- safeHttpUrl() at write time
  phones            VARCHAR(300) NULL,     -- comma-separated; normalized only at accept
  payment_methods   VARCHAR(500) NULL,     -- comma-separated pc_vendor_payment_methods enum values
  notes             TEXT NULL,
  original_filename VARCHAR(300) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL, -- relative to backend/storage/
  file_type         ENUM('pdf','xlsx','csv','image','zip') NOT NULL,
  file_size_bytes   INT UNSIGNED NULL,
  is_template_csv   BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('pending_parse','processing','scored','parse_failed','virus_detected','accepted','rejected')
         NOT NULL DEFAULT 'pending_parse',
  extracted_json    JSON NULL,             -- {contact, warnings, prices} — NEVER touches pc_prices until accept
  score_json        JSON NULL,
  duplicate_of_vendor_id INT UNSIGNED NULL, -- soft dedup flag, admin decides
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

File columns live on the row (one price file per suggestion, v1) — pc_vendor_files requires vendor_id NOT NULL, don't touch it. Storage: backend/storage/vendor_suggestions/{userId}/{token16}.{ext}.

## Status lifecycle

submit → scan fail → `virus_detected` (terminal, quarantined)
submit + template CSV → parse+score inline → `scored`
submit + other file → `pending_parse` → cron claims → `processing` → `scored` | `parse_failed`
`scored` → admin → `accepted` (vendor_id set) | `rejected`
`parse_failed` → admin can still accept (contact-only vendor, no prices) or reject.

## Backend changes

**1. backend/helpers.php** — userShape(): add `'test_account' => (bool)($u['test_account'] ?? false)`.

**2. backend/lib/vendor_file_processor.php** — extract lines ~30–66 into `buildExtractionUserContent(string $fullPath, string $fileType, ?string &$sheetNote): array`; processVendorFile() calls it (behavior identical).

**3. NEW backend/lib/vendor_suggestions.php**
- `parseSuggestionTemplateCsv(string $path): array` — str_getcsv per line; header `product_name,spec,price_usd,kit_vial_count,tier_kit_size,vendor_sku`; spec regex `/^(\d+(?:\.\d+)?)\s*(mg|mcg|iu|ml)$/i`, mcg→mg ÷1000 (matches extraction prompt rule 5); map to extraction shape; skip+warn bad rows; ≥1 valid row required. Returns `{contact:[], warnings:[], prices:[]}`.
- `scoreSuggestionPrices(PDO $pdo, array $prices): array` — see Score below.
- `processSuggestion(array $s, string $model): void` — claim → buildExtractionUserContent → callClaudeExtraction(..., null) → store extracted_json → score → status 'scored' → sendSuggestionScoredEmail. Failure → 'parse_failed' + email.
- `requireSuggestionAccess(): array` — requireAuth + `if (empty($user['test_account']) && empty($user['is_admin'])) jsonResponse(['error'=>'Not found.'],404);` `// ponytail: build-phase gate, delete at launch`.

**4. NEW backend/api/vendor_suggestions/index.php** — GET: own rows (id, display_name, status, score_json, duplicate flag as boolean, created_at — not extracted_json wholesale). POST multipart: requireSuggestionAccess; `rateLimit('vendor_suggest_'.$user['id'], 3, 3600)` + DB count ≤3/7days (Claude cost is real; memcached can restart); validate display_name + ≥1 contact field; safeHttpUrl(website); file gate copied from vendors/files.php (ext map, 5MB cap, ZIP validate, malware scan — fail = quarantine + `virus_detected` row + 422); dedup soft-flag (findVendorByPhone / LOWER(display_name) / website host vs pc_vendors AND pending suggestions → duplicate_of_vendor_id or admin_note 'also suggested by user #N', never reject); template detection = csv whose header matches → parse+score inline → 'scored'; else 'pending_parse'.

**5. NEW backend/api/admin/vendor_suggestions.php** — GET list (?status=), joined user email + duplicate vendor name. POST {id}/accept: must be scored/parse_failed; txn: INSERT pc_vendors (is_active=1, is_verified=0, is_hidden=0) — or update if duplicate_of vendor confirmed by admin flag; saveVendorPhonesAndPaymentMethods; move file into storage/vendor_files/{vendorId}/; INSERT pc_vendor_files row; if extracted_json → `commitExtractionResult($fileRow, $extracted)` (full reuse: exact matches → pc_prices, novel → pc_pending_imports for existing Review Queue, price history, cacheBust comparison_data/calendar_data/admin_vendors/admin_products); update suggestion (accepted, vendor_id, reviewed_by/at); logAdminAction; email. POST {id}/reject: status/reviewed_by/at + optional admin_note; logAdminAction; email.

**6. backend/cron/process_async_queue.php** — after vendor-files loop, inside same GET_LOCK: select `status='pending_parse'` suggestions, mark 'processing', processSuggestion() each, log to pc_maintenance_runs. Reuses existing overlap guard and schedule.

**7. backend/email.php** — `sendSuggestionScoredEmail`, `sendSuggestionAcceptedEmail`, `sendSuggestionRejectedEmail` via emailTemplate(). No "received" email (they see it on screen). No admin-notify email v1 (admins check the tab).

**8. public/index.php routes**
```php
'vendor-suggestions'        => 'vendor_suggestions/index.php',
'admin/vendor-suggestions'  => 'admin/vendor_suggestions.php',
// dynamic:
'admin/vendor-suggestions/(\d+)/(accept|reject)' => ['admin/vendor_suggestions.php', 'id', 'action'],
```

## Score (score_json)

Only tier_kit_size==1 rows. Per row ppu = pricePerUnit(price_usd, kit_vial_count, numeric_value). Match product via findExactProductMatch(), spec via (product_id, spec_label). One query pulls all active tier-1 market ppus for matched pairs (active vendors, mirrors getVendorScorecard subquery); percentiles in PHP (rows are few).
```json
{"total_rows":42,"matched_rows":28,"unmatched_names":["..."],
 "would_be_cheapest_pct":32.1,"below_median_pct":67.9,"avg_percentile":24.5,
 "vendor_score":76}
```
vendor_score = round(100 − avg_percentile) (0 = priciest, 100 = cheapest). `// ponytail: naive composite, reweight when real submissions exist`. matched_rows==0 → vendor_score null, "not enough catalog overlap". Anti-gaming stance v1: score is claimed-price-based, private to submitter + admin; acceptance always manual; admin card shows relationship (vendor_rep = self-submitted) beside score. No detection heuristics v1.

## Frontend

- stores/auth.js: `isTestAccount = computed(() => !!user.value?.test_account)`.
- router/index.js: `/suggest-vendor` → SuggestVendorView, meta `{requiresAuth:true, requiresTestAccount:true}`; guard: not test_account && not admin → /dashboard.
- NEW views/SuggestVendorView.vue: form (relationship radio, contact fields, file input accept=".pdf,.xlsx,.csv,.jpg,.jpeg,.png,.zip") + "Download CSV template" (client-side Blob: header + 2 example rows + comment "instant scoring; other formats take longer") + PII note ("only submit contact info the vendor shares publicly") + My Suggestions list below (status badges, expandable score report, admin_note on rejection). One page, no separate My Suggestions view.
- BottomNav.vue / TopBar.vue: link `v-if="auth.isTestAccount || auth.isAdmin"`.
- NEW views/admin/tabs/VendorSuggestionsTab.vue: table (FeedbackTab style, not single-card — low volume), expand → contact, relationship, dup warning, score, extracted price preview, accept/reject. Register in AdminView.vue catalog group.

## Explicit v1 decisions (product owner's "what am I missing")

| Question | Decision |
|---|---|
| Dedup vs catalog | Soft-flag (duplicate_of_vendor_id), never auto-reject — their file may be fresher |
| Duplicate suggestions | Flag in admin_note; no merge logic |
| Abuse/cost | 3/hr memcached + 3/7-day DB count, 5MB cap; Claude only via cron |
| Tier gating | v1 all authenticated (test-gated anyway); launch recommendation: one-line requireTier('advanced') for non-template files only if abused — skip for now |
| Anti-gaming | Private score + manual accept + relationship label; no heuristics |
| Submitter UX | Single /suggest-vendor page, form + list + score report |
| Notifications | scored / accepted / rejected only |
| PII | Form disclaimer + suggestions never public; nothing else v1 |
| Accept mechanics | Real pc_vendors (is_verified=0) + synthetic pc_vendor_files row + commitExtractionResult() reuse; novel products flow into existing pending-imports Review Queue |
| Template | Client-side Blob; columns product_name, spec, price_usd, kit_vial_count, tier_kit_size, vendor_sku |
| Test gating | Backend requireSuggestionAccess() 404 (both endpoints’ user side) + router guard + nav v-if |
| ClamAV risk | Stale signatures (CDN IP block) — scan still gates, note in admin runbook |

Skip-for-v1: file re-upload/revision on a suggestion, suggestion editing, admin re-score button, merge tooling, admin notification email, i18n.

## Phases

**Phase 1 (shippable)**: migration 036 + schema.sql; userShape test_account; lib/vendor_suggestions.php (template parse + score + access gate); api/vendor_suggestions/index.php; api/admin/vendor_suggestions.php; email senders (accepted/rejected/scored); routes; auth store + router + nav; SuggestVendorView; VendorSuggestionsTab. Full loop for template CSVs: submit → scan → score → admin accept → live prices.

**Phase 2**: buildExtractionUserContent refactor; processSuggestion(); cron loop. Non-CSV files now flow pending_parse → scored.

**Phase 3 (launch)**: delete requireSuggestionAccess gate + nav v-if condition; decide tier gate.

## Verification

```bash
# lint (no local PHP)
for f in backend/lib/vendor_suggestions.php backend/api/vendor_suggestions/index.php \
         backend/api/admin/vendor_suggestions.php backend/helpers.php backend/email.php \
         backend/cron/process_async_queue.php backend/lib/vendor_file_processor.php public/index.php; do
  ssh price.themightygroupbuy.com "php -l ~/price/$f"; done   # adjust remote dir per deploy.sh
bash deploy.sh          # rsync + migrate.sh
ssh price.themightygroupbuy.com "mysql --defaults-extra-file=~/.pc_my.cnf tmgb_price -e 'DESCRIBE pc_vendor_suggestions'"
```
DB spot-checks: suggestion row status transitions; after accept — new pc_vendors row, pc_prices rows with source_file_id of synthetic file, pc_pending_imports rows for novel products; `SELECT status,score_json FROM pc_vendor_suggestions ORDER BY id DESC LIMIT 5`.
Browser: non-test user sees no nav + direct /suggest-vendor redirects + API returns 404; test user submits template CSV → instant score; EICAR file → rejected + quarantined (backend/storage/quarantine/); 4th submit in a week → 429/422; admin tab accept → vendor on Comparison page after cache bust; reject → email + note visible to submitter.

## Critical files

- /home/dusten/projects/peptides_projects/pep_pricing_tool/price_themightygroupbuy/backend/lib/vendor_file_processor.php
- /home/dusten/projects/peptides_projects/pep_pricing_tool/price_themightygroupbuy/backend/api/vendors/files.php
- /home/dusten/projects/peptides_projects/pep_pricing_tool/price_themightygroupbuy/backend/lib/vendor_helpers.php
- /home/dusten/projects/peptides_projects/pep_pricing_tool/price_themightygroupbuy/public/index.php
- /home/dusten/projects/peptides_projects/pep_pricing_tool/price_themightygroupbuy/backend/helpers.php
