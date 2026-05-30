# Database Structure

MySQL / MariaDB database `traficom_tracker`.

Initialise with:
```bash
mysql -u root -p < calls_sral_fi.sql
```

---

## Tables

### `snapshots`

A full copy of the Traficom callsign list, stored once per day by `fetcher.php`.
Each row represents one callsign that was present in the downloaded list on that day.

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT PK | Row identifier |
| `fetched_at` | DATETIME | Timestamp of the download run |
| `callsign` | VARCHAR(20) | Uppercase callsign, e.g. `OH2LAK` |
| `status` | VARCHAR(20) | Status string from Traficom, e.g. `VOIMASSA`, `KARENSSI`, `VARAUS` |

**Indexes:** `idx_date` on `fetched_at`, `idx_call` on `callsign`.

**Notes:**
- One `fetched_at` timestamp per daily run; all rows for a run share the same value.
- The fetcher skips a day if a row for `DATE(fetched_at) = today` already exists (override with `--force`).
- This table grows by ~10 000 rows per day. It is the source of truth for diff computation but is not exposed through the API.

---

### `daily_changes`

One row per callsign that appeared or disappeared on a given date.
Populated by `fetcher.php` by diffing consecutive snapshot days.

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT PK | Row identifier |
| `change_date` | DATE | Date the change was detected |
| `callsign` | VARCHAR(20) | Uppercase callsign |
| `change_type` | ENUM(`added`, `removed`) | Direction of the change |
| `category` | ENUM(`new`, `renewal`, `genuine_remove`, `pending`) | Classification (see below) |

**Indexes:** `idx_date` on `change_date`, `idx_call` on `callsign`, `idx_category` on `category`.

#### Category values

| Value | Applies to | Meaning |
|---|---|---|
| `pending` | both | Freshly inserted; not yet classified (within grace period) |
| `new` | `added` | Callsign has not appeared in the system within the grace window — a brand-new licence |
| `renewal` | both | The same callsign was removed and re-added within ≤ 7 days — a licence renewal; both the remove and the re-add rows are marked `renewal` |
| `genuine_remove` | `removed` | Callsign left and did not return within the 7-day grace period — licence genuinely expired or revoked |

#### Grace period reconciliation

`fetcher.php` runs a reconcile pass every day:

1. Any `added` row whose callsign also has a `removed` row within the prior 7 days → both rows become `renewal`.
2. Remaining `added` rows older than today → `new`.
3. `removed` rows older than 7 days that are still `pending` → `genuine_remove`.

The `clean` view exposed by the API filters out `renewal` and `pending` rows so that only `new` additions and `genuine_remove` removals are shown. The `raw` view returns all rows including renewals and pending.

---

### `daily_stats`

Pre-aggregated per-day totals. Updated by `fetcher.php` after each reconcile pass.

| Column | Type | Description |
|---|---|---|
| `stat_date` | DATE PK | The date these stats describe |
| `total` | INT | Total number of active callsigns in that day's snapshot |
| `added` | INT | Raw added count (including renewals) |
| `removed` | INT | Raw removed count (including renewals) |
| `new_callsigns` | INT | `added` rows classified as `new` |
| `renewals` | INT | `added` rows classified as `renewal` (one per renewal pair) |
| `genuine_removes` | INT | `removed` rows classified as `genuine_remove` |
| `pending_removes` | INT | `removed` rows still classified as `pending` on this date |

**Note:** `new_callsigns` and `genuine_removes` are the "clean" figures shown in the frontend by default. `added` / `removed` are the raw counts used in the `raw` view.

---

## Data flow

```
Traficom website
      │  (daily cron, fetcher.php)
      ▼
snapshots          ← full callsign list per day
      │  (diff consecutive days)
      ▼
daily_changes      ← one row per added/removed callsign
      │  (reconcile pass, grace period logic)
      ▼
daily_changes      ← categories finalised (new / renewal / genuine_remove)
      │  (aggregate)
      ▼
daily_stats        ← totals per day, served by API
```

---

## Typical query patterns

**Latest snapshot date:**
```sql
SELECT MAX(DATE(fetched_at)) FROM snapshots;
```

**All changes on a date (clean view):**
```sql
SELECT callsign, change_type, category
FROM daily_changes
WHERE change_date = '2026-05-30'
  AND category NOT IN ('renewal', 'pending')
ORDER BY change_type, callsign;
```

**7-day rolling totals:**
```sql
SELECT SUM(new_callsigns), SUM(genuine_removes)
FROM daily_stats
WHERE stat_date >= CURDATE() - INTERVAL 7 DAY;
```

**Full history for a callsign:**
```sql
SELECT change_date, change_type, category
FROM daily_changes
WHERE callsign = 'OH2LAK'
ORDER BY change_date DESC;
```
