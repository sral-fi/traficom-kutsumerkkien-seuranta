<?php

declare(strict_types=1);

namespace App\Controller;

use App\Helper\DatabaseAware;
use App\Helper\JsonResponse;
use Pimple\Psr11\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class Home
{
    use DatabaseAware;

    private const API_NAME    = 'sral-callsign-tracker-api';
    private const API_VERSION = '0.9.0';

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function getHelp(Request $request, Response $response): Response
    {
        $message = [
            'api'     => self::API_NAME,
            'version' => self::API_VERSION,
            'endpoints' => [
                'GET /api/summary' => 'Latest daily stats and 7-day totals',
                'GET /api/stats'   => 'Historical stats (?days=90&view=clean|raw)',
                'GET /api/changes' => 'Change log (?days=30&kind=added|removed&view=clean|raw)',
                'GET /api/search'  => 'Callsign lookup (?q=OH2A)',
            ],
        ];

        return JsonResponse::withJson($response, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
