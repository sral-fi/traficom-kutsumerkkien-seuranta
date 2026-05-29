<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Database;

require __DIR__ . '/../vendor/autoload.php';

// Load .env (silently – no error if file missing when env vars are set externally)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(false, true, true);

// ── CORS middleware ──────────────────────────────────────────────────────────
$app->add(function (Request $request, $handler): Response {
    // Handle preflight
    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
        return addCorsHeaders($response);
    }
    return addCorsHeaders($handler->handle($request));
});

$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

function addCorsHeaders(Response $response): Response
{
    // Strip CR/LF to prevent response-header injection (OWASP A03)
    $origin = str_replace(["\r", "\n"], '', $_ENV['CORS_ORIGIN'] ?? '*');
    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept')
        ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Cache-Control', 'no-store');
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Clamp an integer query parameter. */
function intQ(array $params, string $key, int $default, int $min, int $max): int
{
    $v = isset($params[$key]) ? (int) $params[$key] : $default;
    return max($min, min($max, $v));
}

/** Encode data as JSON and write to response body; throws on encoding failure. */
function jsonOut(Response $response, mixed $data, int $status = 200): Response
{
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $response->getBody()->write($payload);
    return $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json; charset=utf-8');
}

/** Cast all numeric-looking values in a row to int, leave others untouched. */
function castRow(array $row, array $intFields): array
{
    foreach ($intFields as $f) {
        if (array_key_exists($f, $row)) {
            $row[$f] = (int) $row[$f];
        }
    }
    return $row;
}

// ── GET /api/summary ─────────────────────────────────────────────────────────
$app->get('/api/summary', function (Request $request, Response $response): Response {
    $db = Database::getInstance();

    $latest = $db->query(
        'SELECT stat_date, total, added, removed,
                new_callsigns, renewals, genuine_removes, pending_removes
         FROM daily_stats
         ORDER BY stat_date DESC
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    $since7 = (new DateTimeImmutable('-7 days'))->format('Y-m-d');
    $week   = $db->query(
        'SELECT
             SUM(added)           AS added_7d,
             SUM(removed)         AS removed_7d,
             SUM(new_callsigns)   AS new_7d,
             SUM(renewals)        AS renewals_7d,
             SUM(genuine_removes) AS genuine_removes_7d,
             SUM(pending_removes) AS pending_removes_7d
         FROM daily_stats
         WHERE stat_date >= ?',
        [$since7]
    )->fetch(PDO::FETCH_ASSOC);

    $latestFields = ['total', 'added', 'removed', 'new_callsigns', 'renewals', 'genuine_removes', 'pending_removes'];
    $weekFields   = ['added_7d', 'removed_7d', 'new_7d', 'renewals_7d', 'genuine_removes_7d', 'pending_removes_7d'];

    return jsonOut($response, [
        'latest'      => $latest ? castRow($latest, $latestFields) : null,
        'last_7_days' => $week   ? castRow($week,   $weekFields)   : null,
    ]);
});

// ── GET /api/stats ───────────────────────────────────────────────────────────
$app->get('/api/stats', function (Request $request, Response $response): Response {
    $params = $request->getQueryParams();
    $days   = intQ($params, 'days', 90, 7, 730);
    $view   = in_array($params['view'] ?? '', ['clean', 'raw'], true) ? $params['view'] : 'clean';

    $since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
    $db    = Database::getInstance();

    $rows = $db->query(
        'SELECT stat_date, total, added, removed,
                new_callsigns, renewals, genuine_removes, pending_removes
         FROM daily_stats
         WHERE stat_date >= ?
         ORDER BY stat_date',
        [$since]
    )->fetchAll(PDO::FETCH_ASSOC);

    $intFields = ['total', 'added', 'removed', 'new_callsigns', 'renewals', 'genuine_removes', 'pending_removes'];
    $result    = [];

    foreach ($rows as $r) {
        $r = castRow($r, $intFields);
        if ($view === 'clean') {
            $r['display_added']   = $r['new_callsigns'];
            $r['display_removed'] = $r['genuine_removes'];
        } else {
            $r['display_added']   = $r['added'];
            $r['display_removed'] = $r['removed'];
        }
        $result[] = $r;
    }

    return jsonOut($response, $result);
});

// ── GET /api/changes ─────────────────────────────────────────────────────────
$app->get('/api/changes', function (Request $request, Response $response): Response {
    $params = $request->getQueryParams();
    $days   = intQ($params, 'days', 30, 1, 365);
    $kind   = in_array($params['kind'] ?? '', ['added', 'removed'], true) ? $params['kind'] : 'all';
    $view   = in_array($params['view'] ?? '', ['clean', 'raw'], true)     ? $params['view'] : 'clean';

    $since      = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
    $conditions = ['change_date >= ?'];
    $binds      = [$since];

    // All condition values are bound as parameters – no literals interpolated into SQL
    if ($kind === 'added')   { $conditions[] = 'change_type = ?'; $binds[] = 'added'; }
    if ($kind === 'removed') { $conditions[] = 'change_type = ?'; $binds[] = 'removed'; }
    if ($view === 'clean')   { $conditions[] = 'category NOT IN (?, ?)'; $binds[] = 'renewal'; $binds[] = 'pending'; }

    $where = implode(' AND ', $conditions);
    $rows  = Database::getInstance()->query(
        "SELECT change_date, callsign, change_type, category
         FROM daily_changes
         WHERE {$where}
         ORDER BY change_date DESC, change_type, callsign",
        $binds
    )->fetchAll(PDO::FETCH_ASSOC);

    return jsonOut($response, $rows);
});

// ── GET /api/search ──────────────────────────────────────────────────────────
$app->get('/api/search', function (Request $request, Response $response): Response {
    $params   = $request->getQueryParams();
    $raw      = trim($params['q'] ?? '');
    $callsign = strtoupper($raw);

    // Validate length and character set – callsigns are strictly alphanumeric
    if (!preg_match('/^[A-Z0-9]{3,12}$/', $callsign)) {
        return jsonOut($response, ['error' => 'Parameter q must be 3–12 alphanumeric characters'], 422);
    }

    $db = Database::getInstance();

    $snap = $db->query(
        'SELECT callsign, status, DATE(fetched_at) AS snapshot_date
         FROM snapshots
         WHERE callsign = ?
         ORDER BY fetched_at DESC
         LIMIT 1',
        [$callsign]
    )->fetch(PDO::FETCH_ASSOC);

    $changes = $db->query(
        'SELECT change_date, change_type, category
         FROM daily_changes
         WHERE callsign = ?
         ORDER BY change_date DESC',
        [$callsign]
    )->fetchAll(PDO::FETCH_ASSOC);

    $firstRow = $db->query(
        'SELECT MIN(change_date) AS first_seen
         FROM daily_changes
         WHERE callsign = ?',
        [$callsign]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$snap && empty($changes)) {
        return jsonOut($response, ['found' => false, 'callsign' => $callsign]);
    }

    $active       = (bool) $snap;
    $status       = $snap ? $snap['status']        : 'POISTETTU';
    $snapshotDate = $snap ? $snap['snapshot_date'] : null;

    $removedDate = null;
    foreach ($changes as $c) {
        if ($c['change_type'] === 'removed' && in_array($c['category'], ['genuine_remove', 'pending'], true)) {
            $removedDate = $c['change_date'];
            break;
        }
    }

    return jsonOut($response, [
        'found'         => true,
        'callsign'      => $callsign,
        'active'        => $active,
        'status'        => $status,
        'snapshot_date' => $snapshotDate,
        'removed_date'  => $removedDate,
        'first_seen'    => $firstRow['first_seen'] ?? null,
        'changes'       => $changes,
    ]);
});

$app->run();
