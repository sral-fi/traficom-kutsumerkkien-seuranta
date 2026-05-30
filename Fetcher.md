# Fetcher

`api/bin/fetcher.php` is the daily cron script that downloads the Traficom amateur radio licence list, stores a full snapshot, computes the daily diff, classifies each change, and aggregates stats.

Run it as:

```bash
php api/bin/fetcher.php            # normal daily run
php api/bin/fetcher.php --force    # re-fetch even if today's snapshot already exists
```

Set `LOG_LEVEL=debug` to see verbose output.

---

## Flow overview

```
1. fetchCallsignList()   Download + normalise the Traficom callsign list
       │
       ▼
   [Anomaly guard]       Skip diff if Traficom published a broken list
       │
       ▼
2. storeSnapshot()       Persist the full list in the `snapshots` table
       │
       ▼
3. computeRawDiff()      Diff today's snapshot against yesterday's;
                         insert pending rows into `daily_changes`
       │
       ▼
4. reconcile()           Classify pending changes using the 7-day grace window;
                         update `daily_stats`
```

If today's snapshot already exists and `--force` is not set, steps 1–3 are skipped and only `reconcile()` runs (grace-period expiry may have progressed since the last run).

---

## 1. `fetchCallsignList()`

### HTTP session

Traficom serves the list through an ASP.NET form.  Two requests are required:

1. **GET** the page to harvest the hidden `__VIEWSTATE`, `__VIEWSTATEGENERATOR`, and `__VIEWSTATEENCRYPTED` fields.
2. **POST** the form with those fields plus `ButtonDownload=Lataa tekstitiedostona` to receive the plain-text TSV file.

A cookie jar (temp file) is shared between the two requests so the ASP.NET session is preserved.

### Parsing

The response is a tab- or semicolon-separated file.  The first row is a header and is skipped.  Each data row is `CALLSIGN\tSTATUS`.  If the status column is absent the callsign is assumed to be `VOIMASSA`.

Callsigns are uppercased.  Valid statuses are `VOIMASSA`, `VARAUS`, and `KARENSSI`.

### Duplicate callsign handling

Traficom occasionally lists the same callsign more than once in a single file (usually with different statuses).  The rule applied here — and in the original `koolitutka-update_database.php` updater — is:

> **VOIMASSA is the strongest state.** If a callsign appears with `VOIMASSA` and also with `VARAUS` or `KARENSSI`, `VOIMASSA` wins.  Other duplicates simply keep the first occurrence.

### Wildcard duplicate detection

Before 2019-12-13 Traficom listed a withdrawn callsign in both its full form (e.g. `OH6EYA`) and its wildcard form (`OH*EYA`).  The wildcard form is derived by replacing the 3rd character (district digit or `/`) with `*`:

```
OH2LAK   →  OH*LAK
OG0A     →  OG*A
OH/JE1ABU → OH*JE1ABU
```

Deduplication rules (same as `koolitutka-update_database.php`):

| Full-form status | Wildcard status | Action |
|---|---|---|
| `VOIMASSA` | anything | Drop the wildcard entry |
| `VARAUS` / `KARENSSI` | same | Drop the full-form entry, keep wildcard |
| differ (non-VOIMASSA) | differ | Keep both (edge case; logged at DEBUG) |

---

## 2. Anomaly guard

**Problem:** During holiday periods Traficom has published lists that contain _zero_ `KARENSSI` or `VARAUS` entries (confirmed occurrence: 2019-12-13 to 2019-12-21).  If the diff were computed normally this would mark every KARENSSI/VARAUS callsign as removed, producing hundreds of false `genuine_remove` records.

**Detection:** After fetching, count the non-`VOIMASSA` entries in today's download.  If the count is zero, query the previous snapshot for its non-`VOIMASSA` count.  If the previous snapshot had non-zero non-`VOIMASSA` entries, the current download is considered anomalous.

**Action on anomaly:**
- Store the snapshot as-is (preserves data for later inspection).
- Upsert the total count into `daily_stats`.
- Run `reconcile()` (grace-period expiry still proceeds for other dates).
- **Skip `computeRawDiff()`** — no `daily_changes` rows are written for this date.
- Log a `WARNING`.

This mirrors the `anomaly_bridge` logic in `koolitutka-update_database.php` which kept KARENSSI/VARAUS events alive (`to_date='NOW'`) when the list was missing them.

---

## 3. `storeSnapshot()`

Inserts one row into `snapshots` per callsign, all sharing the same `fetched_at` timestamp (current wall-clock time as `Y-m-d H:i:s`).

The `snapshots` table is the source of truth for diff computation.  It is not exposed through the API but is required for `computeRawDiff()` to work.

---

## 4. `computeRawDiff()`

Finds the most recent snapshot date strictly before today, loads that snapshot as a set, and diffs it against today's set:

```
added   = today_set − prev_set
removed = prev_set  − today_set
```

Each resulting callsign is inserted into `daily_changes` with `category = 'pending'`.  Any existing `daily_changes` rows for today are deleted first (idempotency — `--force` re-runs are safe).

### Suspicious volume warning

If the number of removals exceeds `max(200, 5% of today's total)`, a `WARNING` is logged.  This is a safety net for anomalies not caught by the anomaly guard (e.g. partial data from Traficom).  The diff is still stored — an operator can inspect and, if needed, delete the bad rows and re-run with `--force`.

---

## 5. `reconcile()`

Classifies `pending` rows using a **7-day grace window** (`GRACE_DAYS = 7`).  This runs every day regardless of whether new data was fetched, so grace-period expiry is always up to date.

### Classification rules

| Change type | Condition | Category |
|---|---|---|
| `added` | a `removed` row for the same callsign exists within the prior 7 days | `renewal` — the matching `removed` row is also set to `renewal` |
| `added` | no recent removal | `new` |
| `removed` | still `pending` after 7 days | `genuine_remove` |

### Stats update

After classifying, `daily_stats` is refreshed for every date within the grace window by aggregating `daily_changes`.  The columns updated are `added`, `removed`, `new_callsigns`, `renewals`, `genuine_removes`, `pending_removes`.  The `total` column (snapshot size) is never overwritten here — it is set by `computeRawDiff()` / `upsertDailyStats()`.

---

## Configuration

| Source | Key | Default | Description |
|---|---|---|---|
| `api/.env` | `DB_HOST` | `127.0.0.1` | MySQL host |
| `api/.env` | `DB_NAME` | `traficom_tracker` | Database name |
| `api/.env` | `DB_USER` | `traficom` | Database user |
| `api/.env` | `DB_PASS` | — | Database password |
| Env / `api/.env` | `LOG_LEVEL` | `info` | `debug` / `info` / `warning` / `error` |
| CLI | `--force` | — | Re-fetch even if today's snapshot exists |

---

## Logging

Log entries go to both `api/logs/app.log` and stdout (stderr for Monolog console handler).  Each entry is a JSON-like Monolog line with channel `fetcher`.

Notable log events:

| Level | Event |
|---|---|
| `INFO` | Fetch started, HTTP responses, snapshot stored, diff computed, reconcile stats |
| `DEBUG` | ViewState field lengths, snapshot sizes, first 20 callsigns in each diff, cache hits |
| `WARNING` | Anomaly detected (zero non-VOIMASSA), suspicious large diff |
| `ERROR` | HTTP failure, DB insert failure (script exits) |

---

## Cron example

```cron
# Every day at 06:00 (Traficom publishes overnight)
0 6 * * * php /path/to/api/bin/fetcher.php >> /path/to/api/logs/cron.log 2>&1
```
