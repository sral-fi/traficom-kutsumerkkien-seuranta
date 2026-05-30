#!/usr/bin/env php
<?php
/**
 * import-sqlite-history.php
 *
 * Imports historical callsign change data from db.sqlite into the MySQL backend.
 *
 * Source schema (SQLite):
 *   event   – (callsign, neighbour, status, from_date, to_date)
 *               to_date = 'NOW'    → callsign still active in the most recent snapshot
 *               to_date = 'DATE'   → period ended on that date (set to prev_date when callsign
 *                                    disappeared from a snapshot)
 *               from_date = NULL   → genesis entries only (before tracking began)
 *               from_date = 'DATE' → new period started on that date (all non-genesis rows)
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
 * All callsigns active in the snapshot for a given authored date:
 *   to_date = 'NOW'   → still active in the most recent snapshot
 *   to_date = date    → was present in this snapshot but gone from the next one
 * koolitutka-update_database.php sets to_date = prev_date (= the current authored date)
 * for any callsign that disappears, so to_date is an exact match to authored dates.
 */
$stmtActive = $sqlite->prepare(
    "SELECT DISTINCT callsign FROM event
     WHERE to_date = 'NOW' OR to_date = ?"
);

/** Count of callsigns active in the snapshot for a given authored date. */
$stmtTotal = $sqlite->prepare(
    "SELECT COUNT(DISTINCT callsign) FROM event
     WHERE to_date = 'NOW' OR to_date = ?"
);

/**
 * Callsigns whose period ended (to_date) in the window [graceStart, date).
 * A callsign removed within GRACE days before it is re-added → classify the re-add as 'renewal'.
 * ix_current covers to_date queries efficiently.
 */
$stmtRecentRemoves = $sqlite->prepare(
    "SELECT DISTINCT callsign FROM event
     WHERE to_date != 'NOW' AND to_date >= ? AND to_date < ?"
);

/**
 * Callsigns that start a new period (to_date = 'NOW' or to_date > date) with a to_date
 * recorded in (date, graceEnd] by checking future-snapshot activity. Because from_date is
 * always NULL in this dataset we detect future returns by checking whether the callsign
 * appears in any snapshot between date+1 and date+GRACE.
 * We use to_date > date for the future window: a callsign with to_date=D means it was
 * present in snapshot D; if D is in (date, graceEnd] then it returned.
 */
$stmtFutureReturn = $sqlite->prepare(
    "SELECT DISTINCT callsign FROM event
     WHERE (to_date = 'NOW' OR to_date > ?) AND (to_date = 'NOW' OR to_date <= ?)"
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
$totalNew     = 0; // added + classified as 'new' (not a renewal)
$totalRenewal = 0; // removed + classified as 'renewal'
$totalGenuine = 0; // removed + classified as 'genuine_remove'

foreach ($updateDates as $date) {
    if (isset($existingSet[$date])) {
        $log->debug('Skipping – already in MySQL', ['date' => $date]);
        $cntSkipped++;
        continue;
    }

    // Active total on this date
    $stmtTotal->execute([$date]);
    $total = (int) $stmtTotal->fetchColumn();

    if (!isset($prevDateMap[$date])) {
        // First date in the dataset – no previous to compare against
        $log->info('First date – no diff possible, recording total only', ['date' => $date, 'total' => $total]);
        if (!$dryRun) {
            $insStats->execute([$date, $total, 0, 0, 0, 0, 0, 0]);
        }
        $cntProcessed++;
        continue;
    }

    // Fetch prevSet FIRST so the 2-slot LRU cache keeps it warm after todaySet is added.
    $prevSet  = $getActiveSet($prevDateMap[$date]);
    $todaySet = $getActiveSet($date);

    $added   = array_keys(array_diff_key($todaySet, $prevSet));
    $removed = array_keys(array_diff_key($prevSet,  $todaySet));

    // Grace-window bounds
    $graceStart = date('Y-m-d', strtotime($date . ' -' . GRACE . ' days'));
    $graceEnd   = date('Y-m-d', strtotime($date . ' +' . GRACE . ' days'));

    // Callsigns that returned in a snapshot within GRACE days after D.
    // to_date in (D, graceEnd] means the callsign was active in some snapshot in that window.
    $stmtFutureReturn->execute([$date, $graceEnd]);
    $futureReturnsSet = array_flip($stmtFutureReturn->fetchAll(PDO::FETCH_COLUMN));

    // Callsigns whose period ended in [D-GRACE, D) → recently removed before this date.
    $stmtRecentRemoves->execute([$graceStart, $date]);
    $recentRemovesSet = array_flip($stmtRecentRemoves->fetchAll(PDO::FETCH_COLUMN));

    // Classify removes: if the callsign returns within GRACE days → renewal, else genuine
    $removedCats = [];
    $cntGenuine  = 0;
    $cntRenewal  = 0;
    foreach ($removed as $cs) {
        $cat              = isset($futureReturnsSet[$cs]) ? 'renewal' : 'genuine_remove';
        $removedCats[$cs] = $cat;
        if ($cat === 'renewal') $cntRenewal++;
        else $cntGenuine++;
    }

    // Classify adds: if the callsign had a period end within GRACE days before D → renewal,
    // otherwise genuinely new.
    $addedCats = [];
    $cntNew    = 0;
    foreach ($added as $cs) {
        $cat            = isset($recentRemovesSet[$cs]) ? 'renewal' : 'new';
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
        $cntAdded     += count($added);
        $cntRemoved   += count($removed);
        $totalNew     += $cntNew;
        $totalRenewal += $cntRenewal;
        $totalGenuine += $cntGenuine;
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
    $cntAdded     += count($added);
    $cntRemoved   += count($removed);
    $totalNew     += $cntNew;
    $totalRenewal += $cntRenewal;
    $totalGenuine += $cntGenuine;
}

$log->info('Import complete', [
    'dates_processed' => $cntProcessed,
    'dates_skipped'   => $cntSkipped,
    'changes_added'   => $cntAdded,
    'changes_removed' => $cntRemoved,
    'new_callsigns'   => $totalNew,
    'renewals'        => $totalRenewal,
    'genuine_removes' => $totalGenuine,
    'dry_run'         => $dryRun,
]);

if ($dryRun) {
    echo PHP_EOL;
    echo "=== DRY-RUN SUMMARY (nothing was written to MySQL) ===" . PHP_EOL;
    echo sprintf("  Dates that would be imported : %d\n", $cntProcessed);
    echo sprintf("  Dates skipped (already exist): %d\n", $cntSkipped);
    echo sprintf("  'added'   rows               : %d\n", $cntAdded);
    echo sprintf("    of which 'new'             : %d\n", $totalNew);
    echo sprintf("    of which 'renewal'         : %d\n", $cntAdded - $totalNew);
    echo sprintf("  'removed' rows               : %d\n", $cntRemoved);
    echo sprintf("    of which 'genuine_remove'  : %d\n", $totalGenuine);
    echo sprintf("    of which 'renewal'         : %d\n", $totalRenewal);
    echo "======================================================" . PHP_EOL;
}
