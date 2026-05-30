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
use App\Factory\LoggerFactory;
use Psr\Log\LoggerInterface;

// ── Config ───────────────────────────────────────────────────────────────────
const BASE_URL   = 'https://eservices.traficom.fi/Licensesservices/Forms/AmateurLicenses.aspx?langid=fi';
const USER_AGENT = 'Mozilla/5.0 (compatible; SRAL-calls-tracker/1.0)';
const GRACE_DAYS = 7;

// ── Logger ───────────────────────────────────────────────────────────────────
$_logLevelMap = [
    'debug'   => \Monolog\Logger::DEBUG,
    'info'    => \Monolog\Logger::INFO,
    'warning' => \Monolog\Logger::WARNING,
    'error'   => \Monolog\Logger::ERROR,
];
$_logLevelRaw   = strtolower($_SERVER['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?: 'info');
$_resolvedLevel = $_logLevelMap[$_logLevelRaw] ?? \Monolog\Logger::INFO;

$logger = (new LoggerFactory([
    'name'            => 'fetcher',
    'path'            => $apiDir . '/logs',
    'level'           => $_resolvedLevel,
    'file_permission' => 0775,
]))
->addFileHandler('app.log')
->addConsoleHandler()
->createInstance('fetcher');

$logger->info('Fetcher starting', ['log_level' => $_logLevelRaw]);

// ── Callsign helpers ─────────────────────────────────────────────────────────

/**
 * Returns the wildcard form of a callsign by replacing the 3rd character
 * (district digit or separator) with '*'.
 *
 * Examples:  OH2LAK → OH*LAK,  OG0A → OG*A,  OH/JE1ABU → OH*JE1ABU
 *
 * A callsign whose 3rd character is already '*' is its own neighbour.
 * Matches the neighbour() logic from koolitutka-update_database.php.
 */
function neighbourCallsign(string $cs): string
{
    return strlen($cs) >= 3 ? substr($cs, 0, 2) . '*' . substr($cs, 3) : $cs;
}

// ── 1. Fetch callsign list ────────────────────────────────────────────────────
/**
 * @return list<array{0: string, 1: string}>  [[callsign, status], ...]
 */
function fetchCallsignList(LoggerInterface $log): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'traficom_cookie_');
    $log->debug('Cookie jar created', ['path' => $cookieFile]);

    try {
        // ── GET: load ASP.NET form to harvest hidden ViewState fields ─────────
        $log->info('GET ' . BASE_URL);
        $t0 = microtime(true);
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
        $log->debug('GET complete', ['http_code' => $httpCode, 'ms' => round((microtime(true) - $t0) * 1000)]);

        if ($html === false || $httpCode >= 400) {
            throw new RuntimeException("GET failed (HTTP $httpCode): $curlErr");
        }
        $log->info('GET response received', ['http_code' => $httpCode]);

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
        $log->debug('ViewState fields parsed', [
            '__VIEWSTATE_len'          => strlen($hidden['__VIEWSTATE']),
            '__VIEWSTATEGENERATOR'     => $hidden['__VIEWSTATEGENERATOR'],
            '__VIEWSTATEENCRYPTED_len' => strlen($hidden['__VIEWSTATEENCRYPTED']),
        ]);

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

        $log->info('POST ' . BASE_URL);
        $t1 = microtime(true);
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
        $log->debug('POST complete', ['http_code' => $httpCode, 'bytes' => strlen((string) $body), 'ms' => round((microtime(true) - $t1) * 1000)]);

        if ($body === false || $httpCode >= 400) {
            throw new RuntimeException("POST failed (HTTP $httpCode): $curlErr");
        }
        $log->info('POST response received', ['http_code' => $httpCode, 'bytes' => strlen((string) $body)]);
    } finally {
        @unlink($cookieFile);
        $log->debug('Cookie jar removed');
    }

    // Strip UTF-8 BOM, normalise line endings
    $body  = ltrim($body, "\xEF\xBB\xBF");
    $lines = explode("\n", str_replace("\r\n", "\n", $body));

    if (empty(array_filter($lines))) {
        throw new RuntimeException('Empty response from Traficom');
    }
    $log->info('Downloaded lines from Traficom', ['line_count' => count($lines)]);

    // Skip header row; parse tab- or semicolon-separated columns.
    // Build a deduplication table first: VOIMASSA wins on conflict.
    // This mirrors the duplicate-handling logic in koolitutka-update_database.php.
    $table      = [];  // callsign → status
    $duplicates = 0;
    $skipped    = 0;
    foreach (array_slice($lines, 1) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts  = preg_split('/[;\t]/', $line);
        $rawCs  = strtoupper(trim($parts[0] ?? ''));
        $status = trim($parts[1] ?? 'VOIMASSA');

        if ($rawCs === '') { $skipped++; continue; }

        if (!isset($table[$rawCs])) {
            $table[$rawCs] = $status;
        } elseif ($status === 'VOIMASSA' && $table[$rawCs] !== 'VOIMASSA') {
            // VOIMASSA is the strongest state and overrides VARAUS/KARENSSI
            $table[$rawCs] = 'VOIMASSA';
            $duplicates++;
        } else {
            $duplicates++;
        }
    }

    // Wildcard duplicate detection.
    // Traficom used to list a callsign in both its full form (e.g. OH6EYA) and
    // wildcard form (OH*EYA) simultaneously.  Rules (same as koolitutka):
    //   – full-form is VOIMASSA  → drop the wildcard entry
    //   – states match           → drop the full form, keep the wildcard
    $wildcardDropped = 0;
    foreach (array_keys($table) as $cs) {
        if (!isset($table[$cs])) continue;   // already removed in a previous iteration
        $wc = neighbourCallsign($cs);
        if ($wc === $cs) continue;           // already a wildcard – nothing to compare
        if (!isset($table[$wc])) continue;   // no wildcard counterpart present

        if ($table[$cs] === 'VOIMASSA') {
            unset($table[$wc]);              // drop wildcard, keep active full form
            $wildcardDropped++;
        } elseif ($table[$cs] === $table[$wc]) {
            unset($table[$cs]);              // same status → keep wildcard, drop full form
            $wildcardDropped++;
        }
        // Differing non-VOIMASSA states: both are kept (edge case, logged below)
    }

    $callsigns = [];
    foreach ($table as $cs => $status) {
        $callsigns[] = [$cs, $status];
    }

    $log->info('Callsigns parsed', [
        'count'            => count($callsigns),
        'duplicates'       => $duplicates,
        'wildcard_dropped' => $wildcardDropped,
        'skipped_lines'    => $skipped,
    ]);
    return $callsigns;
}

// ── 2. Store snapshot ─────────────────────────────────────────────────────────
/**
 * @param list<array{0: string, 1: string}> $callsigns
 */
function storeSnapshot(array $callsigns, string $fetchedAt, LoggerInterface $log): void
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
        $log->error('Failed to store snapshot', ['exception' => $e->getMessage()]);
        throw $e;
    }

    $log->info('Snapshot stored', ['rows' => count($callsigns), 'fetched_at' => $fetchedAt]);
}

// ── 3. Compute raw diff ───────────────────────────────────────────────────────
/**
 * @param list<string> $todayCalls  Plain list of callsign strings for today
 */
function computeRawDiff(array $todayCalls, string $today, LoggerInterface $log): void
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
        $log->info('No previous snapshot – writing initial stats only', ['today' => $today]);
        upsertDailyStats($today, count($todayCalls));
        return;
    }

    $prevDate = $prevRow->d;
    $log->info('Comparing snapshots', ['today' => $today, 'prev' => $prevDate]);

    $prevCalls = array_column(
        $db->query(
            'SELECT DISTINCT callsign FROM snapshots WHERE DATE(fetched_at) = ?',
            [$prevDate]
        )->fetchAll(PDO::FETCH_NUM),
        0
    );
    $log->debug('Snapshot sizes', ['today' => count($todayCalls), 'prev' => count($prevCalls)]);

    $prevSet  = array_flip($prevCalls);
    $todaySet = array_flip($todayCalls);

    $added   = array_keys(array_diff_key($todaySet, $prevSet));
    $removed = array_keys(array_diff_key($prevSet,  $todaySet));

    // Sanity check: a very large removal count on a single day is a strong indicator
    // of a Traficom data anomaly.  Flag it so the operator can investigate.
    $suspiciousThreshold = max(200, (int)(count($todayCalls) * 0.05));
    if (count($removed) > $suspiciousThreshold) {
        $log->warning('Suspiciously large removal count – possible Traficom data anomaly', [
            'today'     => $today,
            'removed'   => count($removed),
            'added'     => count($added),
            'threshold' => $suspiciousThreshold,
            'total'     => count($todayCalls),
        ]);
    }

    $log->info('Raw diff computed', ['added' => count($added), 'removed' => count($removed)]);
    if (count($added) > 0) {
        $log->debug('Added callsigns', ['callsigns' => array_slice($added, 0, 20)]);
    }
    if (count($removed) > 0) {
        $log->debug('Removed callsigns', ['callsigns' => array_slice($removed, 0, 20)]);
    }

    // Idempotency: delete any existing changes for today before re-inserting
    $deleted = $db->query('DELETE FROM daily_changes WHERE change_date = ?', [$today]);
    $log->debug('Cleared existing changes for today', ['change_date' => $today]);

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
        $log->error('Failed to insert daily_changes', ['exception' => $e->getMessage()]);
        throw $e;
    }
    $log->info('Daily changes inserted', ['added' => count($added), 'removed' => count($removed)]);

    // Always record today's snapshot total so reconcile() can update stats
    upsertDailyStats($today, count($todayCalls));
}

// ── 4. Reconcile ─────────────────────────────────────────────────────────────
function reconcile(string $today, LoggerInterface $log): void
{
    $db         = Database::getInstance();
    $graceStart = date('Y-m-d', strtotime($today . ' -' . GRACE_DAYS . ' days'));
    $log->debug('Reconcile window', ['today' => $today, 'grace_start' => $graceStart]);

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
    $log->info('Renewals marked', ['pairs' => (int) ($stmt->rowCount() / 2)]);

    // b) remaining pending added → new
    $stmt = $db->prepare(
        "UPDATE daily_changes
         SET category = 'new'
         WHERE change_type = 'added'
           AND category    = 'pending'
           AND change_date <= ?"
    );
    $stmt->execute([$today]);
    $log->info('New callsigns marked', ['count' => $stmt->rowCount()]);

    // c) pending removes past grace period → genuine_remove
    $stmt = $db->prepare(
        "UPDATE daily_changes
         SET category = 'genuine_remove'
         WHERE change_type = 'removed'
           AND category    = 'pending'
           AND change_date < ?"
    );
    $stmt->execute([$graceStart]);
    $log->info('Genuine removals confirmed', ['count' => $stmt->rowCount()]);

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
            $log->debug('Skipping stats upsert – no snapshot total', ['date' => $cd]);
            continue;   // no snapshot total yet for this date – skip
        }
        $upd->execute([$cd, $total, $added, $removed, $newCs, $ren, $genRm, $pendRm]);
        $log->debug('Stats upserted', ['date' => $cd, 'total' => $total, 'added' => $added, 'removed' => $removed]);
    }

    $log->info('Reconcile complete');
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
function run(LoggerInterface $log): void
{
    $force = in_array('--force', $_SERVER['argv'] ?? [], true);
    $today = date('Y-m-d');
    $log->debug('Run parameters', ['force' => $force, 'today' => $today]);

    $already = (int) Database::getInstance()
        ->query('SELECT COUNT(*) FROM snapshots WHERE DATE(fetched_at) = ?', [$today])
        ->fetchColumn();
    $log->debug('Existing rows for today', ['count' => $already]);

    if ($already > 0 && !$force) {
        $log->info('Already fetched today – skipping fetch, running reconcile only', ['date' => $today]);
        // Run reconcile anyway – grace period may have expired since last run
        reconcile($today, $log);
        return;
    }

    if ($force && $already > 0) {
        $log->warning('--force flag set, re-fetching despite existing data', ['date' => $today, 'existing_rows' => $already]);
    }

    $callsigns = fetchCallsignList($log);

    // ── Anomaly guard ─────────────────────────────────────────────────────────
    // Traficom has published lists with zero KARENSSI/VARAUS entries during holiday
    // periods (known occurrence: 2019-12-13 to 2019-12-21).  If the current download
    // has no non-VOIMASSA entries but the previous snapshot did, the diff would
    // incorrectly mark all KARENSSI/VARAUS callsigns as removed.  In that case we
    // store the snapshot for reference but skip the diff entirely.
    // Mirrors the anomaly_bridge logic in koolitutka-update_database.php.
    $nonVoimassaToday = count(array_filter($callsigns, static fn($c) => $c[1] !== 'VOIMASSA'));
    if ($nonVoimassaToday === 0) {
        $prevNonVoimassa = (int) Database::getInstance()
            ->query(
                "SELECT COUNT(*) FROM snapshots
                 WHERE DATE(fetched_at) = (
                     SELECT MAX(DATE(fetched_at)) FROM snapshots WHERE DATE(fetched_at) < ?
                 ) AND status != 'VOIMASSA'",
                [$today]
            )->fetchColumn();

        if ($prevNonVoimassa > 0) {
            $log->warning(
                'Traficom anomaly detected: download contains zero non-VOIMASSA entries ' .
                'but previous snapshot had some. Storing snapshot, skipping diff.',
                ['today' => $today, 'prev_non_voimassa' => $prevNonVoimassa]
            );
            storeSnapshot($callsigns, date('Y-m-d H:i:s'), $log);
            upsertDailyStats($today, count($callsigns));
            reconcile($today, $log);
            $log->info('Fetcher done (anomaly day – diff skipped)', ['date' => $today]);
            return;
        }
    }

    storeSnapshot($callsigns, date('Y-m-d H:i:s'), $log);
    computeRawDiff(array_column($callsigns, 0), $today, $log);
    reconcile($today, $log);

    $log->info('Fetcher done', ['date' => $today, 'callsigns' => count($callsigns)]);
}

run($logger);
