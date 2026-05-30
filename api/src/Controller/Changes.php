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

final class Changes
{
    use DatabaseAware;

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getChanges(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $days   = self::intQ($params, 'days', 30, 1, 365);
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
        $rows  = $this->getDb()->query(
            "SELECT change_date, callsign, change_type, category
             FROM daily_changes
             WHERE {$where}
             ORDER BY change_date DESC, change_type, callsign",
            $binds
        )->fetchAll(PDO::FETCH_ASSOC);

        return JsonResponse::withJson($response, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Clamp an integer query parameter. */
    private static function intQ(array $params, string $key, int $default, int $min, int $max): int
    {
        $v = isset($params[$key]) ? (int) $params[$key] : $default;
        return max($min, min($max, $v));
    }
}
