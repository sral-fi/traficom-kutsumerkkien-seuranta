#!/usr/bin/env python3
"""
app.py – FastAPI-webserveri tilastoille.
Käynnistys:  uvicorn app:app --host 0.0.0.0 --port 8099
"""

import os
from datetime import date, timedelta
from decimal import Decimal
from pathlib import Path

from dotenv import load_dotenv
load_dotenv("/opt/traficom-tracker/.env")

from fastapi import FastAPI, Query
from fastapi.responses import HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles

from db import get_conn, init_db

app  = FastAPI(title="Traficom Callsign Tracker")
HTML = Path(__file__).parent / "templates" / "index.html"
app.mount("/static", StaticFiles(directory=str(Path(__file__).parent / "templates")), name="static")


def clean(obj):
    """Muunna Decimal ja date JSON-yhteensopiviksi."""
    if isinstance(obj, dict):
        return {k: clean(v) for k, v in obj.items()}
    if isinstance(obj, Decimal):
        return int(obj)
    if isinstance(obj, date):
        return obj.isoformat()
    return obj


@app.on_event("startup")
def startup():
    init_db()


# ---------------------------------------------------------------------------
# /api/stats
# ---------------------------------------------------------------------------

@app.get("/api/stats")
def api_stats(
    days: int = Query(default=90, ge=7, le=730),
    view: str = Query(default="clean"),   # "clean" | "raw"
):
    since = date.today() - timedelta(days=days)
    conn  = get_conn()
    cur   = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT
            stat_date, total, added, removed,
            new_callsigns, renewals, genuine_removes, pending_removes
        FROM daily_stats
        WHERE stat_date >= %s
        ORDER BY stat_date
        """,
        (since,),
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    result = []
    for r in rows:
        r = clean(r)
        if view == "clean":
            r["display_added"]   = r["new_callsigns"]
            r["display_removed"] = r["genuine_removes"]
        else:
            r["display_added"]   = r["added"]
            r["display_removed"] = r["removed"]
        result.append(r)

    return JSONResponse(result)


# ---------------------------------------------------------------------------
# /api/changes
# ---------------------------------------------------------------------------

@app.get("/api/changes")
def api_changes(
    days: int = Query(default=30, ge=1, le=365),
    kind: str = Query(default="all"),     # all | added | removed
    view: str = Query(default="clean"),   # clean | raw
):
    since = date.today() - timedelta(days=days)
    conn  = get_conn()
    cur   = conn.cursor(dictionary=True)

    conditions = ["change_date >= %s"]
    params     = [since]

    if kind == "added":
        conditions.append("change_type = 'added'")
    elif kind == "removed":
        conditions.append("change_type = 'removed'")

    if view == "clean":
        conditions.append("category NOT IN ('renewal','pending')")

    where = " AND ".join(conditions)
    cur.execute(
        f"""
        SELECT change_date, callsign, change_type, category
        FROM daily_changes
        WHERE {where}
        ORDER BY change_date DESC, change_type, callsign
        """,
        params,
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    return JSONResponse([clean(r) for r in rows])


# ---------------------------------------------------------------------------
# /api/summary
# ---------------------------------------------------------------------------

@app.get("/api/summary")
def api_summary():
    conn = get_conn()
    cur  = conn.cursor(dictionary=True)

    cur.execute("SELECT * FROM daily_stats ORDER BY stat_date DESC LIMIT 1")
    latest = cur.fetchone()

    since_7 = date.today() - timedelta(days=7)
    cur.execute(
        """
        SELECT
            SUM(added)           AS added_7d,
            SUM(removed)         AS removed_7d,
            SUM(new_callsigns)   AS new_7d,
            SUM(renewals)        AS renewals_7d,
            SUM(genuine_removes) AS genuine_removes_7d,
            SUM(pending_removes) AS pending_removes_7d
        FROM daily_stats
        WHERE stat_date >= %s
        """,
        (since_7,),
    )
    week = cur.fetchone()
    cur.close()
    conn.close()

    return JSONResponse({
        "latest":      clean(latest),
        "last_7_days": clean(week),
    })


# ---------------------------------------------------------------------------
# /api/search
# ---------------------------------------------------------------------------

@app.get("/api/search")
def api_search(q: str = Query(min_length=3, max_length=12)):
    callsign = q.strip().upper()
    conn = get_conn()
    cur  = conn.cursor(dictionary=True)

    # Viimeisin snapshot-tieto
    cur.execute(
        """
        SELECT callsign, status, DATE(fetched_at) AS snapshot_date
        FROM snapshots
        WHERE callsign = %s
        ORDER BY fetched_at DESC
        LIMIT 1
        """,
        (callsign,),
    )
    snap = cur.fetchone()

    # Muutoshistoria
    cur.execute(
        """
        SELECT change_date, change_type, category
        FROM daily_changes
        WHERE callsign = %s
        ORDER BY change_date DESC
        """,
        (callsign,),
    )
    changes = cur.fetchall()

    # First seen: vanhin merkintä muutoslokissa (lisäys tai poisto)
    cur.execute(
        """
        SELECT MIN(change_date) AS first_seen
        FROM daily_changes
        WHERE callsign = %s
        """,
        (callsign,),
    )
    fs_row = cur.fetchone()
    first_seen = clean(fs_row["first_seen"]) if fs_row and fs_row["first_seen"] else None

    cur.close()
    conn.close()

    if not snap and not changes:
        return JSONResponse({"found": False, "callsign": callsign})

    # Määritä nykyinen tila
    if snap:
        status        = snap["status"]
        snapshot_date = clean(snap["snapshot_date"])
        active        = True
    else:
        status        = "POISTETTU"
        snapshot_date = None
        active        = False

    # Etsi viimeisin poistopäivä
    removed_date = None
    for c in changes:
        if c["change_type"] == "removed" and c["category"] in ("genuine_remove", "pending"):
            removed_date = clean(c["change_date"])
            break

    return JSONResponse({
        "found":         True,
        "callsign":      callsign,
        "active":        active,
        "status":        status,
        "snapshot_date": snapshot_date,
        "removed_date":  removed_date,
        "first_seen":    first_seen,
        "changes":       [clean(c) for c in changes],
    })


# ---------------------------------------------------------------------------
# Frontend
# ---------------------------------------------------------------------------

@app.get("/", response_class=HTMLResponse)
def index():
    return HTML.read_text(encoding="utf-8")
