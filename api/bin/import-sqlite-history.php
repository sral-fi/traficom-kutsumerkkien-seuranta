#!/usr/bin/env php
<?php
/**
 * import-sqlite-history.php
 *
 * Imports historical callsign change data from db.sqlite into the MySQL backend.
 *
 * Source schema (SQLite):
 *   event   – (callsign, neighbour, status, from_date, to_date)
 *               to_date = 'NOW'    → callsign still active
 *               to_date = 'DATE'   → callsign left on that date
 *               from_date = NULL   → start date unknown (before tracking began)
 *   updates – (hash, authored)     → dates when a snapshot was taken
 *
 * Target tables (MySQL):
 *   daily_changes – one row per add/remove event
 *   daily_stats   – aggregate totals per day
 *   snapshots     – NOT imported (too large; fetcher.php handles future snapshots)
 *
 * Usage:
 *   php api/bin/import-sqlite-history.php
 *   php api/bin/import-sqlite-history.php --sqlite=/path/to/db.sqlite
 *   php api/bin/import-sqlite-history.php --since=2020-01-01
 *   php api/bin/import-sqlite-history.php --dry-run
 *   php api/bin/import-sqlite-history.php --force    # re-import dates already in MySQL
 *
 * Requirements:
 *   - pdo_sqlite PHP extension  (apt install php-sqlite3 / php8.x-sqlite3)
 *   - api/.env with DB_* credentials
 */

declare(strict_types=1);

$apiDir = dirname(__DIR__);
require $apiDir . '/vendor/autoload.php';

(Dotenv\Dotenv::createImmutable($apiDir))->safeLoad();

use App\Database;
use App\Factory\LoggerFactory;

// ── CLI args ─────────────────────────────────────────────────────────────────
$opts = getopt('', ['sqlite:', 'since:', 'dry-run', 'force', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Usage: php api/bin/import-sqlite-history.php [OPTIONS]

  --sqlite=PATH       Path to db.sqlite  (default: <repo-root>/db.sqlite)
  --since=YYYY-MM-DD  Only import dates on or after this date (default: 2016-01-01)
  --dry-run           Parse and log without writing to MySQL
  --force             Re-import dates that already have rows in MySQL
  --help              Show this help

HELP;
    exit(0);
}

$sqlitePath = $opts['sqlite'] ?? realpath($apiDir . '/../../db.sqlite') ?: ($apiDir . '/../db.sqlite');
$since      = $opts['since']  ?? '2016-01-01';
$dryRun     = isset($opts['dry-run']);
$force      = isset($opts['force']);

const GRACE = 7; // days – same as GRACE_DAYS in fetcher.php

// ── Logger ───────────────────────────────────────────────────────────────────
$_logLevelMap = [
    'debug'   => \Monolog\Logger::DEBUG,
    'info'    => \Monolog\Logger::INFO,
    'warning' => \Monolog\Logger::WARNING,
    'error'   => \Monolog\Logger::ERROR,
];
$_logLevelRaw   = strtolower($_SERVER['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?: 'info');
$_resolvedLevel = $_logLevelMap[$_logLevelRaw] ?? \Monolog\Logger::INFO;

$log = (new LoggerFactory([
    'name'            => 'sqlite-import',
    'path'            => $apiDir . '/logs',
    'level'           => $_resolvedLevel,
    'file_permission' => 0775,
]))->addFileHandler('app.log')->addConsoleHandler($dryRun ? \Monolog\Logger::DEBUG : null)->createInstance('sqlite-import');

$log->info('SQLite history import starting', [
    'sqlite'   => $sqlitePath,
    'since'    => $since,
    'dry_run'  => $dryRun,
    'force'    => $force,
    'log_level'=> $_logLevelRaw,
]);

// ── Open SQLite ───────────────────────────────────────────────────────────────
if (!extension_loaded('pdo_sqlite')) {
    $log->error('pdo_sqlite extension is not loaded. Install it with: apt install php-sqlite3');
    exit(1);
}

if (!file_exists($sqlitePath)) {
    $log->error('SQLite file not found', ['path' => $sqlitePath]);
    exit(1);
}

try {
    $sqlite = new PDO('sqlite:' . $sqlitePath, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $log->error('Cannot open SQLite: ' . $e->getMessage());
    exit(1);
}

// Verify expected tables exist
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('event', $tables, true) || !in_array('updates', $tables, true)) {
    $log->error('Expected tables not found in SQLite', ['found' => $tables]);
    exit(1);
}

// ── Get update dates in range ─────────────────────────────────────────────────
$stmtDates = $sqlite->prepare('SELECT DISTINCT authored FROM updates WHERE authored >= ? ORDER BY authored');
$stmtDates->execute([$since]);
$updateDates = $stmtDates->fetchAll(PDO::FETCH_COLUMN);

$log->info('Update dates found in SQLite', ['count' => count($updateDates), 'first' => $updateDates[0] ?? '-', 'last' => end($updateDates) ?: '-']);

if (empty($updateDates)) {
    $log->warning('No update dates found for the given range', ['since' => $since]);
    exit(0);
}

// ── MySQL connection ──────────────────────────────────────────────────────────
$db = Database::getInstance();

// Find dates already in MySQL so we can skip (unless --force)
$existingSet = [];
if (!$force) {
    $existing = $db->query(
        'SELECT stat_date FROM daily_stats WHERE stat_date >= ? ORDER BY stat_date',
        [$since]
    )->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip($existing);
    $log->info('Dates already in MySQL (will skip)', ['count' => count($existing)]);
}

// ── Prepared statements for MySQL inserts ────────────────────────────────────
$insChange = $db->prepare(
    'INSERT IGNORE INTO daily_changes (change_date, callsign, change_type, category)
     VALUES (?, ?, ?, ?)'
);
$insStats  = $db->prepare(
    'INSERT INTO daily_stats
         (stat_date, total, added, removed, new_callsigns, renewals, genuine_removes, pending_removes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         total           = VALUES(total),
         added           = VALUES(added),
         removed         = VALUES(removed),
         new_callsigns   = VALUES(new_callsigns),
         renewals        = VALUES(renewals),
         genuine_removes = VALUES(genuine_removes),
         pending_removes = VALUES(pending_removes)'
);

// ── SQLite query helpers ──────────────────────────────────────────────────────

/**
 * All callsigns active on a given date:
 *   to_date = 'NOW'  → still active
 *   to_date >= date  → was active on that date (left later)
 * from_date is always NULL in this dataset, so we ignore it.
 */
$stmtActive = $sqlite->prepare(
    "SELECT DISTINCT callsign FROM event
     WHERE to_date = 'NOW' OR to_date >= ?"
);

/** Count of active callsigns on a date. */
$stmtTotal = $sqlite->prepare(
    "SELECT COUNT(DISTINCT callsign) FROM event
     WHERE to_date = 'NOW' OR to_date >= ?"
);

// Build a map of date → previous date from the updateDates list so we can diff
$prevDateMap = [];
for ($i = 1; $i < count($updateDates); $i++) {
    $prevDateMap[$updateDates[$i]] = $updateDates[$i - 1];
}

// Cache active sets to avoid re-querying the same date twice in consecutive iterations
$activeCache = [];

$getActiveSet = function (string $date) use ($sqlite, $stmtActive, &$activeCache): array {
    if (!isset($activeCache[$date])) {
        $stmtActive->execute([$date]);
        $activeCache[$date] = array_flip($stmtActive->fetchAll(PDO::FETCH_COLUMN));
        // Keep cache small – only the two most recent dates are needed
        if (count($activeCache) > 2) {
            reset($activeCache);
            unset($activeCache[key($activeCache)]);
        }
    }
    return $activeCache[$date];
};

// ── Main loop ─────────────────────────────────────────────────────────────────
$cntProcessed = 0;
$cntSkipped   = 0;
$cntAdded     = 0;
$cntRemoved   = 0;

foreach ($updateDates as $date) {
    if (isset($existingSet[$date])) {
        $log->debug('Skipping – already in MySQL', ['date' => $date]);
        $cntSkipped++;
        continue;
    }

    // Active total on this date
    $stmtTotal->execute([$date]);
    $total = (int) $stmtTotal->fetchColumn();

    // Derive added/removed by comparing active sets with the previous update date
    $todaySet = $getActiveSet($date);

    if (!isset($prevDateMap[$date])) {
        // First date in the dataset – no previous to compare against
        $log->info('First date – no diff possible, recording total only', ['date' => $date, 'total' => $total]);
        if (!$dryRun) {
            $insStats->execute([$date, $total, 0, 0, 0, 0, 0, 0]);
        }
        $cntProcessed++;
        continue;
    }

    $prevSet = $getActiveSet($prevDateMap[$date]);

    $added   = array_keys(array_diff_key($todaySet, $prevSet));
    $removed = array_keys(array_diff_key($prevSet,  $todaySet));

    // Grace-window sets for classification (look ±7 days in the SQLite event table)
    $graceStart = date('Y-m-d', strtotime($date . ' -' . GRACE . ' days'));
    $graceEnd   = date('Y-m-d', strtotime($date . ' +' . GRACE . ' days'));

    // Future adds within grace: callsigns that appear in a later active set
    // Proxy: callsigns whose to_date falls within (date, graceEnd] AND they re-appear
    // Simpler and accurate: check if a removed callsign is active again by graceEnd
    $graceEndSet = $getActiveSet($graceEnd);
    $futureAddsSet = $graceEndSet; // active on graceEnd = came back within window

    // Prior removes within grace: callsigns not active graceStart days ago but active now (added recently)
    $graceStartSet = $getActiveSet($graceStart);

    // Classify removes: if the callsign is back by graceEnd → renewal, else genuine
    $removedCats = [];
    $cntGenuine  = 0;
    $cntRenewal  = 0;
    foreach ($removed as $cs) {
        $cat              = isset($futureAddsSet[$cs]) ? 'renewal' : 'genuine_remove';
        $removedCats[$cs] = $cat;
        if ($cat === 'renewal') $cntRenewal++;
        else $cntGenuine++;
    }

    // Classify adds: if the callsign was absent graceStart days ago it's new, else renewal
    $addedCats = [];
    $cntNew    = 0;
    foreach ($added as $cs) {
        // Was it absent on graceStart (i.e. recently removed and now back)?
        $wasAbsent = !isset($graceStartSet[$cs]);
        $cat       = $wasAbsent ? 'new' : 'renewal';
        $addedCats[$cs] = $cat;
        if ($cat === 'new') $cntNew++;
    }

    $log->info('Date processed', [
        'date'            => $date,
        'total'           => $total,
        'added'           => count($added),
        'removed'         => count($removed),
        'new'             => $cntNew,
        'renewals'        => $cntRenewal,
        'genuine_removes' => $cntGenuine,
    ]);

    if ($dryRun) {
        $cntProcessed++;
        $cntAdded   += count($added);
        $cntRemoved += count($removed);
        continue;
    }

    $db->beginTransaction();
    try {
        foreach ($added as $cs) {
            $insChange->execute([$date, $cs, 'added', $addedCats[$cs]]);
        }
        foreach ($removed as $cs) {
            $insChange->execute([$date, $cs, 'removed', $removedCats[$cs]]);
        }
        $insStats->execute([
            $date,
            $total,
            count($added),
            count($removed),
            $cntNew,
            $cntRenewal,
            $cntGenuine,
            0, // pending_removes – historical data is fully classified
        ]);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollback();
        $log->error('Failed to insert date – aborting', ['date' => $date, 'error' => $e->getMessage()]);
        exit(1);
    }

    $cntProcessed++;
    $cntAdded   += count($added);
    $cntRemoved += count($removed);
}

$log->info('Import complete', [
    'dates_processed' => $cntProcessed,
    'dates_skipped'   => $cntSkipped,
    'changes_added'   => $cntAdded,
    'changes_removed' => $cntRemoved,
    'dry_run'         => $dryRun,
]);

if ($dryRun) {
    echo PHP_EOL;
    echo "=== DRY-RUN SUMMARY (nothing was written to MySQL) ===" . PHP_EOL;
    echo sprintf("  Dates that would be imported : %d\n", $cntProcessed);
    echo sprintf("  Dates skipped (already exist): %d\n", $cntSkipped);
    echo sprintf("  'added'   rows               : %d\n", $cntAdded);
    echo sprintf("  'removed' rows               : %d\n", $cntRemoved);
    echo "======================================================" . PHP_EOL;
}
