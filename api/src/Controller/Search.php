<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\DatabaseAware;
use App\Helper\JsonResponse;
use PDO;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class Search
{
    use DatabaseAware;

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getSearch(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $raw      = trim($params['q'] ?? '');
        $callsign = strtoupper($raw);

        // Validate length and character set – callsigns are strictly alphanumeric
        if (!preg_match('/^[A-Z0-9]{3,12}$/', $callsign)) {
            return JsonResponse::withJson(
                $response,
                json_encode(['error' => 'Parameter q must be 3–12 alphanumeric characters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                422
            );
        }

        $db = $this->getDb();

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
            return JsonResponse::withJson(
                $response,
                json_encode(['found' => false, 'callsign' => $callsign], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
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

        $data = [
            'found'         => true,
            'callsign'      => $callsign,
            'active'        => $active,
            'status'        => $status,
            'snapshot_date' => $snapshotDate,
            'removed_date'  => $removedDate,
            'first_seen'    => $firstRow['first_seen'] ?? null,
            'changes'       => $changes,
        ];

        return JsonResponse::withJson($response, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
