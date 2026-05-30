#!/usr/bin/env python3
"""
fetcher.py – Hae Traficomin radioamatöörikutsuluettelo ja laske päivittäinen diff.

Ajettavissa suoraan tai cronilla:
    python3 fetcher.py
    python3 fetcher.py --force    # pakota uushaku vaikka tänään jo haettu
"""

import logging
import sys
from datetime import date, datetime, timedelta

import requests
from bs4 import BeautifulSoup

from db import get_conn, init_db

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger(__name__)

BASE_URL   = "https://eservices.traficom.fi/Licensesservices/Forms/AmateurLicenses.aspx?langid=fi"
HEADERS    = {"User-Agent": "Mozilla/5.0 (compatible; SRAL-calls-tracker/1.0)", "Referer": BASE_URL}
GRACE_DAYS = 7   # päivää ennen kuin poistettu merkki luokitellaan aidoksi poistoksi


# ---------------------------------------------------------------------------
# 1. Fetch
# ---------------------------------------------------------------------------

def fetch_callsign_list() -> list[tuple[str, str]]:
    session = requests.Session()

    log.info("GET %s", BASE_URL)
    r = session.get(BASE_URL, headers=HEADERS, timeout=30)
    r.raise_for_status()

    soup = BeautifulSoup(r.text, "html.parser")

    def hidden(name):
        el = soup.find("input", {"id": name})
        return el["value"] if el else ""

    payload = {
        "__VIEWSTATE":          hidden("__VIEWSTATE"),
        "__VIEWSTATEGENERATOR": hidden("__VIEWSTATEGENERATOR"),
        "__VIEWSTATEENCRYPTED": hidden("__VIEWSTATEENCRYPTED"),
        "__EVENTTARGET":        "",
        "__EVENTARGUMENT":      "",
        "MainScriptManager_HiddenField": "",
        "ButtonDownload":       "Lataa tekstitiedostona",
    }

    log.info("POST – downloading text file")
    r2 = session.post(BASE_URL, data=payload, headers=HEADERS, timeout=60)
    r2.raise_for_status()

    content = r2.content.decode("utf-8-sig", errors="replace")
    lines   = content.splitlines()

    if not lines:
        raise ValueError("Empty response from Traficom")

    log.info("Downloaded %d lines (incl. header)", len(lines))

    callsigns = []
    for line in lines[1:]:
        line = line.strip()
        if not line:
            continue
        parts = line.replace(";", "\t").split("\t")
        if len(parts) >= 2:
            callsigns.append((parts[0].strip().upper(), parts[1].strip()))
        elif len(parts) == 1 and parts[0]:
            callsigns.append((parts[0].strip().upper(), "VOIMASSA"))

    log.info("Parsed %d callsigns", len(callsigns))
    return callsigns


# ---------------------------------------------------------------------------
# 2. Store snapshot
# ---------------------------------------------------------------------------

def store_snapshot(callsigns: list[tuple[str, str]], fetched_at: datetime):
    conn = get_conn()
    cur  = conn.cursor()
    rows = [(fetched_at, cs, st) for cs, st in callsigns]
    cur.executemany(
        "INSERT INTO snapshots (fetched_at, callsign, status) VALUES (%s, %s, %s)",
        rows,
    )
    conn.commit()
    cur.close()
    conn.close()
    log.info("Stored %d rows to snapshots", len(rows))


# ---------------------------------------------------------------------------
# 3. Compute raw diff vs. previous snapshot day
# ---------------------------------------------------------------------------

def compute_raw_diff(today_calls: set[str], today: date):
    conn = get_conn()
    cur  = conn.cursor()

    cur.execute(
        """
        SELECT DISTINCT DATE(fetched_at) AS d
        FROM snapshots
        WHERE DATE(fetched_at) < %s
        ORDER BY d DESC LIMIT 1
        """,
        (today,),
    )
    row = cur.fetchone()

    if row is None:
        log.info("No previous snapshot – writing initial stats only")
        _upsert_daily_stats(conn, today, len(today_calls))
        conn.commit()
        cur.close()
        conn.close()
        return

    prev_date = row[0]
    log.info("Comparing against %s", prev_date)

    cur.execute(
        "SELECT DISTINCT callsign FROM snapshots WHERE DATE(fetched_at) = %s",
        (prev_date,),
    )
    prev_calls = {r[0] for r in cur.fetchall()}

    added   = today_calls - prev_calls
    removed = prev_calls  - today_calls
    log.info("Raw diff: +%d added, -%d removed", len(added), len(removed))

    # Idempotenttisuus: poista saman päivän aiemmat rivit
    cur.execute("DELETE FROM daily_changes WHERE change_date = %s", (today,))

    change_rows = (
        [(today, cs, "added",   "pending") for cs in added] +
        [(today, cs, "removed", "pending") for cs in removed]
    )
    if change_rows:
        cur.executemany(
            "INSERT INTO daily_changes (change_date, callsign, change_type, category) "
            "VALUES (%s, %s, %s, %s)",
            change_rows,
        )

    conn.commit()
    cur.close()
    conn.close()


# ---------------------------------------------------------------------------
# 4. Reconcile: luokittele pending-rivit grace periodin perusteella
# ---------------------------------------------------------------------------

def reconcile(today: date):
    """
    Käy läpi kaikki pending-rivit ja luokittele:
      - added  + poistettu viim. GRACE_DAYS → merkitse molemmat 'renewal'
      - added  ilman aiempaa poistoa         → 'new'
      - removed + yli GRACE_DAYS vanha       → 'genuine_remove'
    """
    conn = get_conn()
    cur  = conn.cursor()

    grace_start = today - timedelta(days=GRACE_DAYS)

    # a) Lisätyt jotka löytyvät myös lähiajan poistoista → renewal
    cur.execute(
        """
        UPDATE daily_changes AS added
        JOIN daily_changes AS removed
          ON  removed.callsign    = added.callsign
          AND removed.change_type = 'removed'
          AND removed.change_date >= %s
          AND removed.change_date <  added.change_date
        SET added.category   = 'renewal',
            removed.category = 'renewal'
        WHERE added.change_type = 'added'
          AND added.category    = 'pending'
          AND added.change_date <= %s
        """,
        (grace_start, today),
    )
    renewed = cur.rowcount
    log.info("Reconcile: %d renewal pair(s) marked", renewed // 2 if renewed else 0)

    # b) Jäljellä olevat pending-lisäykset → new
    cur.execute(
        """
        UPDATE daily_changes
        SET category = 'new'
        WHERE change_type = 'added'
          AND category    = 'pending'
          AND change_date <= %s
        """,
        (today,),
    )
    log.info("Reconcile: %d new callsign(s) marked", cur.rowcount)

    # c) Pending-poistot joiden grace period on umpeutunut → genuine_remove
    cur.execute(
        """
        UPDATE daily_changes
        SET category = 'genuine_remove'
        WHERE change_type = 'removed'
          AND category    = 'pending'
          AND change_date < %s
        """,
        (grace_start,),
    )
    log.info("Reconcile: %d genuine removal(s) confirmed", cur.rowcount)

    conn.commit()

    # d) Päivitä daily_stats kaikille päiville joilla on nyt luokiteltuja rivejä
    cur.execute(
        """
        SELECT
            dc.change_date,
            ds.total,
            SUM(dc.change_type = 'added')                              AS added,
            SUM(dc.change_type = 'removed')                            AS removed,
            SUM(dc.category    = 'new')                                AS new_cs,
            SUM(dc.category    = 'renewal')                            AS renewals,
            SUM(dc.category    = 'genuine_remove')                     AS genuine_rm,
            SUM(dc.category    = 'pending' AND dc.change_type='removed') AS pending_rm
        FROM daily_changes dc
        LEFT JOIN daily_stats ds ON ds.stat_date = dc.change_date
        WHERE dc.change_date >= %s
        GROUP BY dc.change_date
        """,
        (grace_start,),
    )
    for row in cur.fetchall():
        cd, total, added, removed, new_cs, ren, gen_rm, pend_rm = row
        if total is None:
            continue
        cur2 = conn.cursor()
        cur2.execute(
            """
            INSERT INTO daily_stats
                (stat_date, total, added, removed,
                 new_callsigns, renewals, genuine_removes, pending_removes)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
                added           = VALUES(added),
                removed         = VALUES(removed),
                new_callsigns   = VALUES(new_callsigns),
                renewals        = VALUES(renewals),
                genuine_removes = VALUES(genuine_removes),
                pending_removes = VALUES(pending_removes)
            """,
            (cd, total, added, removed, new_cs, ren, gen_rm, pend_rm),
        )
        cur2.close()

    conn.commit()
    cur.close()
    conn.close()
    log.info("Reconcile complete.")


# ---------------------------------------------------------------------------
# 5. Helpers
# ---------------------------------------------------------------------------

def _upsert_daily_stats(conn, stat_date: date, total: int,
                        added=0, removed=0, new_cs=0,
                        ren=0, gen_rm=0, pend_rm=0):
    cur = conn.cursor()
    cur.execute(
        """
        INSERT INTO daily_stats
            (stat_date, total, added, removed,
             new_callsigns, renewals, genuine_removes, pending_removes)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
        ON DUPLICATE KEY UPDATE
            total           = VALUES(total),
            added           = VALUES(added),
            removed         = VALUES(removed),
            new_callsigns   = VALUES(new_callsigns),
            renewals        = VALUES(renewals),
            genuine_removes = VALUES(genuine_removes),
            pending_removes = VALUES(pending_removes)
        """,
        (stat_date, total, added, removed, new_cs, ren, gen_rm, pend_rm),
    )
    cur.close()


# ---------------------------------------------------------------------------
# 6. Main
# ---------------------------------------------------------------------------

def run():
    init_db()

    now   = datetime.utcnow()
    today = now.date()

    conn = get_conn()
    cur  = conn.cursor()
    cur.execute(
        "SELECT COUNT(*) FROM snapshots WHERE DATE(fetched_at) = %s", (today,)
    )
    already = cur.fetchone()[0]
    cur.close()
    conn.close()

    if already > 0 and "--force" not in sys.argv:
        log.info("Already fetched today (%s). Use --force to override.", today)
        # Aja silti reconcile – voi olla eilen tullut genuine_remove-luokitteluja
        reconcile(today)
        return

    callsigns = fetch_callsign_list()
    store_snapshot(callsigns, now)
    compute_raw_diff({cs for cs, _ in callsigns}, today)
    reconcile(today)

    log.info("Done.")


if __name__ == "__main__":
    run()
