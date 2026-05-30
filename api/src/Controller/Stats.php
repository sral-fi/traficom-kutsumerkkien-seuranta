<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\DatabaseAware;
use App\Helper\JsonResponse;
use DateTimeImmutable;
use PDO;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class Stats
{
    use DatabaseAware;

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getSummary(Request $request, Response $response): Response
    {
        $db = $this->getDb();

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

        // SUM aggregate always returns a row even on empty table (all NULLs) — treat that as no data
        $weekHasData = $week && array_filter($week, static fn($v) => $v !== null);

        $data = [
            'latest'      => $latest      ? self::castRow($latest, $latestFields) : null,
            'last_7_days' => $weekHasData ? self::castRow($week,   $weekFields)   : null,
        ];

        return JsonResponse::withJson($response, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function getStats(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $all    = ($params['all'] ?? '') === '1';
        $days   = self::intQ($params, 'days', 90, 7, 3650);
        $view   = in_array($params['view'] ?? '', ['clean', 'raw'], true) ? $params['view'] : 'clean';

        $db = $this->getDb();

        if ($all) {
            $rows = $db->query(
                'SELECT stat_date, total, added, removed,
                        new_callsigns, renewals, genuine_removes, pending_removes
                 FROM daily_stats
                 ORDER BY stat_date',
                []
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
            $rows = $db->query(
                'SELECT stat_date, total, added, removed,
                        new_callsigns, renewals, genuine_removes, pending_removes
                 FROM daily_stats
                 WHERE stat_date >= ?
                 ORDER BY stat_date',
                [$since]
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $intFields = ['total', 'added', 'removed', 'new_callsigns', 'renewals', 'genuine_removes', 'pending_removes'];
        $result    = [];

        foreach ($rows as $r) {
            $r = self::castRow($r, $intFields);
            if ($view === 'clean') {
                $r['display_added']   = $r['new_callsigns'];
                $r['display_removed'] = $r['genuine_removes'];
            } else {
                $r['display_added']   = $r['added'];
                $r['display_removed'] = $r['removed'];
            }
            $result[] = $r;
        }

        return JsonResponse::withJson($response, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Clamp an integer query parameter. */
    private static function intQ(array $params, string $key, int $default, int $min, int $max): int
    {
        $v = isset($params[$key]) ? (int) $params[$key] : $default;
        return max($min, min($max, $v));
    }

    /** Cast specified fields to int in a result row. */
    private static function castRow(array $row, array $intFields): array
    {
        foreach ($intFields as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = (int) $row[$f];
            }
        }
        return $row;
    }
}
