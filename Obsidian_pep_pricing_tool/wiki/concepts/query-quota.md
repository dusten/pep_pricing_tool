---
title: Query Quota System
type: concept
tags: [quota, metering, free-tier]
created: 2026-06-29
sources: [phase1-framework]
---

# Query Quota System

## How it works

Free-tier users get **3 distinct comparison queries per 72-hour rolling window**.

- Each query is identified by `filter_hash` — sha1 of normalized filter params
- Stored in `pc_query_log` (user_id, filter_hash, created_at)
- `DISTINCT filter_hash` count in the rolling window = queries used
- Same filter repeated doesn't consume quota
- Resets rolling from the oldest entry in the window

## DB

```sql
pc_query_log (
  user_id     INT UNSIGNED,
  filter_hash CHAR(40),   -- sha1
  created_at  DATETIME
)
```

Cron cleans entries older than 73h daily at 03:10.

## Config (pc_app_settings)

| Key | Default |
|-----|---------|
| `free_tier_query_limit` | 3 |
| `free_tier_window_hours` | 72 |

## API

`GET /api/me/quota` — returns:
```json
{
  "tier": "free",
  "unlimited": false,
  "used": 1,
  "limit": 3,
  "remaining": 2,
  "resets_at": "2026-07-01T03:00:00"
}
```

Paid users get `"unlimited": true`, all other fields null.

## Frontend

`QuotaBadge` component in TopBar (free tier only) — shows remaining, color-coded (ok/warn/danger), links to /pricing. `useQuotaStore` in Pinia.

## Related

- [[wiki/concepts/subscription-tiers|Subscription Tiers]]
- [[wiki/sources/phase1-framework|Phase 1 Framework Reference]]
