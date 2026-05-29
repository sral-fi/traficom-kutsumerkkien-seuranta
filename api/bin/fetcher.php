#!/usr/bin/env php
<?php
/**
 * fetcher.php – Hae Traficomin radioamatöörikutsuluettelo ja laske päivittäinen diff.
 *
 * Käyttö:
 *   php api/bin/fetcher.php
 *   php api/bin/fetcher.php --force    # pakota uushaku vaikka tänään jo haettu
 */
declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
$apiDir = dirname(__DIR__);
require $apiDir . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable($apiDir))->safeLoad();

use App\Database;

// ── Config ───────────────────────────────────────────────────────────────────
const BASE_URL   = 'https://eservices.traficom.fi/Licensesservices/Forms/AmateurLicenses.aspx?langid=fi';
const USER_AGENT = 'Mozilla/5.0 (compatible; SRAL-calls-tracker/1.0)';
const GRACE_DAYS = 7;

// ── Logging ──────────────────────────────────────────────────────────────────
function logInfo(string $msg): void
{
    echo date('Y-m-d H:i:s') . ' INFO  ' . $msg . PHP_EOL;
}

// ── 1. Fetch callsign list ────────────────────────────────────────────────────
/**
 * @return list<array{0: string, 1: string}>  [[callsign, status], ...]
 */
function fetchCallsignList(): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'traficom_cookie_');

    try {
        // ── GET: load ASP.NET form to harvest hidden ViewState fields ─────────
        logInfo('GET ' . BASE_URL);
        $ch = curl_init(BASE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_REFERER        => BASE_URL,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        $html     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            throw new RuntimeException("GET failed (HTTP $httpCode): $curlErr");
        }
        logInfo("GET → $httpCode");

        // Parse hidden ASP.NET form fields
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $hidden = [];
        foreach (['__VIEWSTATE', '__VIEWSTATEGENERATOR', '__VIEWSTATEENCRYPTED'] as $name) {
            $el          = $dom->getElementById($name);
            $hidden[$name] = $el ? $el->getAttribute('value') : '';
        }

        // ── POST: submit form to download the plain-text callsign file ────────
        $postFields = http_build_query([
            '__VIEWSTATE'                   => $hidden['__VIEWSTATE'],
            '__VIEWSTATEGENERATOR'          => $hidden['__VIEWSTATEGENERATOR'],
            '__VIEWSTATEENCRYPTED'          => $hidden['__VIEWSTATEENCRYPTED'],
            '__EVENTTARGET'                 => '',
            '__EVENTARGUMENT'               => '',
            'MainScriptManager_HiddenField' => '',
            'ButtonDownload'                => 'Lataa tekstitiedostona',
        ]);

        $ch = curl_init(BASE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_REFERER        => BASE_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            throw new RuntimeException("POST failed (HTTP $httpCode): $curlErr");
        }
        logInfo("POST → $httpCode");
    } finally {
        @unlink($cookieFile);
    }

    // Strip UTF-8 BOM, normalise line endings
    $body  = ltrim($body, "\xEF\xBB\xBF");
    $lines = explode("\n", str_replace("\r\n", "\n", $body));

    if (empty(array_filter($lines))) {
        throw new RuntimeException('Empty response from Traficom');
    }
    logInfo('Downloaded ' . count($lines) . ' lines (incl. header)');

    // Skip header row; parse tab- or semicolon-separated columns
    $callsigns = [];
    foreach (array_slice($lines, 1) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/[;\t]/', $line);
        if (count($parts) >= 2) {
            $callsigns[] = [strtoupper(trim($parts[0])), trim($parts[1])];
        } elseif (count($parts) === 1 && $parts[0] !== '') {
            $callsigns[] = [strtoupper(trim($parts[0])), 'VOIMASSA'];
        }
    }

    logInfo('Parsed ' . count($callsigns) . ' callsigns');
    return $callsigns;
}

// ── 2. Store snapshot ─────────────────────────────────────────────────────────
/**
 * @param list<array{0: string, 1: string}> $callsigns
 */
function storeSnapshot(array $callsigns, string $fetchedAt): void
{
    $db   = Database::getInstance();
    $stmt = $db->prepare(
        'INSERT INTO snapshots (fetched_at, callsign, status) VALUES (?, ?, ?)'
    );

    $db->beginTransaction();
    try {
        foreach ($callsigns as [$cs, $status]) {
            $stmt->execute([$fetchedAt, $cs, $status]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }

    logInfo('Stored ' . count($callsigns) . ' rows to snapshots');
}

// ── 3. Compute raw diff ───────────────────────────────────────────────────────
/**
 * @param list<string> $todayCalls  Plain list of callsign strings for today
 */
function computeRawDiff(array $todayCalls, string $today): void
{
    $db = Database::getInstance();

    $prevRow = $db->query(
        'SELECT DISTINCT DATE(fetched_at) AS d
         FROM snapshots
         WHERE DATE(fetched_at) < ?
         ORDER BY d DESC LIMIT 1',
        [$today]
    )->fetch();

    if ($prevRow === false) {
        logInfo('No previous snapshot – writing initial stats only');
        upsertDailyStats($today, count($todayCalls));
        return;
    }

    $prevDate = $prevRow->d;
    logInfo('Comparing against ' . $prevDate);

    $prevCalls = array_column(
        $db->query(
            'SELECT DISTINCT callsign FROM snapshots WHERE DATE(fetched_at) = ?',
            [$prevDate]
        )->fetchAll(PDO::FETCH_NUM),
        0
    );

    $prevSet  = array_flip($prevCalls);
    $todaySet = array_flip($todayCalls);

    $added   = array_keys(array_diff_key($todaySet, $prevSet));
    $removed = array_keys(array_diff_key($prevSet,  $todaySet));

    logInfo('Raw diff: +' . count($added) . ' added, -' . count($removed) . ' removed');

    // Idempotency: delete any existing changes for today before re-inserting
    $db->query('DELETE FROM daily_changes WHERE change_date = ?', [$today]);

    $stmt = $db->prepare(
        'INSERT INTO daily_changes (change_date, callsign, change_type, category)
         VALUES (?, ?, ?, ?)'
    );

    $db->beginTransaction();
    try {
        foreach ($added   as $cs) { $stmt->execute([$today, $cs, 'added',   'pending']); }
        foreach ($removed as $cs) { $stmt->execute([$today, $cs, 'removed', 'pending']); }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        throw $e;
    }

    // Always record today's snapshot total so reconcile() can update stats
    upsertDailyStats($today, count($todayCalls));
}

// ── 4. Reconcile ─────────────────────────────────────────────────────────────
function reconcile(string $today): void
{
    $db         = Database::getInstance();
    $graceStart = date('Y-m-d', strtotime($today . ' -' . GRACE_DAYS . ' days'));

    // a) added rows whose callsign appears in a recent removed row → renewal
    $stmt = $db->prepare(
        "UPDATE daily_changes AS added
         JOIN daily_changes AS removed
           ON  removed.callsign    = added.callsign
           AND removed.change_type = 'removed'
           AND removed.change_date >= ?
           AND removed.change_date <  added.change_date
         SET added.category   = 'renewal',
             removed.category = 'renewal'
         WHERE added.change_type = 'added'
           AND added.category    = 'pending'
           AND added.change_date <= ?"
    );
    $stmt->execute([$graceStart, $today]);
    logInfo('Reconcile: ' . (int) ($stmt->rowCount() / 2) . ' renewal pair(s) marked');

    // b) remaining pending added → new
    $stmt = $db->prepare(
        "UPDATE daily_changes
         SET category = 'new'
         WHERE change_type = 'added'
           AND category    = 'pending'
           AND change_date <= ?"
    );
    $stmt->execute([$today]);
    logInfo('Reconcile: ' . $stmt->rowCount() . ' new callsign(s) marked');

    // c) pending removes past grace period → genuine_remove
    $stmt = $db->prepare(
        "UPDATE daily_changes
         SET category = 'genuine_remove'
         WHERE change_type = 'removed'
           AND category    = 'pending'
           AND change_date < ?"
    );
    $stmt->execute([$graceStart]);
    logInfo('Reconcile: ' . $stmt->rowCount() . ' genuine removal(s) confirmed');

    // d) Refresh daily_stats for all dates with classified changes
    $rows = $db->query(
        "SELECT
             dc.change_date,
             ds.total,
             SUM(dc.change_type = 'added')                                AS added,
             SUM(dc.change_type = 'removed')                              AS removed,
             SUM(dc.category    = 'new')                                  AS new_cs,
             SUM(dc.category    = 'renewal')                              AS renewals,
             SUM(dc.category    = 'genuine_remove')                       AS genuine_rm,
             SUM(dc.category    = 'pending' AND dc.change_type = 'removed') AS pending_rm
         FROM daily_changes dc
         LEFT JOIN daily_stats ds ON ds.stat_date = dc.change_date
         WHERE dc.change_date >= ?
         GROUP BY dc.change_date",
        [$graceStart]
    );

    $upd = $db->prepare(
        'INSERT INTO daily_stats
             (stat_date, total, added, removed,
              new_callsigns, renewals, genuine_removes, pending_removes)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
             added           = VALUES(added),
             removed         = VALUES(removed),
             new_callsigns   = VALUES(new_callsigns),
             renewals        = VALUES(renewals),
             genuine_removes = VALUES(genuine_removes),
             pending_removes = VALUES(pending_removes)'
    );

    while ($row = $rows->fetch(PDO::FETCH_NUM)) {
        [$cd, $total, $added, $removed, $newCs, $ren, $genRm, $pendRm] = $row;
        if ($total === null) {
            continue;   // no snapshot total yet for this date – skip
        }
        $upd->execute([$cd, $total, $added, $removed, $newCs, $ren, $genRm, $pendRm]);
    }

    logInfo('Reconcile complete.');
}

// ── Helper: upsert daily_stats total ─────────────────────────────────────────
function upsertDailyStats(
    string $statDate,
    int    $total,
    int    $added   = 0,
    int    $removed = 0,
    int    $newCs   = 0,
    int    $ren     = 0,
    int    $genRm   = 0,
    int    $pendRm  = 0
): void {
    Database::getInstance()->query(
        'INSERT INTO daily_stats
             (stat_date, total, added, removed,
              new_callsigns, renewals, genuine_removes, pending_removes)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
             total           = VALUES(total),
             added           = VALUES(added),
             removed         = VALUES(removed),
             new_callsigns   = VALUES(new_callsigns),
             renewals        = VALUES(renewals),
             genuine_removes = VALUES(genuine_removes),
             pending_removes = VALUES(pending_removes)',
        [$statDate, $total, $added, $removed, $newCs, $ren, $genRm, $pendRm]
    );
}

// ── Main ─────────────────────────────────────────────────────────────────────
function run(): void
{
    $force = in_array('--force', $_SERVER['argv'] ?? [], true);
    $today = date('Y-m-d');

    $already = (int) Database::getInstance()
        ->query('SELECT COUNT(*) FROM snapshots WHERE DATE(fetched_at) = ?', [$today])
        ->fetchColumn();

    if ($already > 0 && !$force) {
        logInfo("Already fetched today ($today). Use --force to override.");
        // Run reconcile anyway – grace period may have expired since last run
        reconcile($today);
        return;
    }

    $callsigns = fetchCallsignList();
    storeSnapshot($callsigns, date('Y-m-d H:i:s'));
    computeRawDiff(array_column($callsigns, 0), $today);
    reconcile($today);

    logInfo('Done.');
}

run();
