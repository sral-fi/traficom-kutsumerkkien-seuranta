<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);

    $origin = $request->getHeaderLine('Origin');

    // Allowed origins configured via env (comma-separated).
    $getEnv          = fn($k) => $_SERVER[$k] ?? getenv($k) ?: null;
    $allowedOriginsRaw = $getEnv('CORS_ALLOWED_ORIGINS') ?? '';
    $allowedOrigins    = array_filter(array_map('trim', explode(',', $allowedOriginsRaw)));

    // Also allow the configured site base URL.
    $siteUrl = rtrim($getEnv('SITE_BASEURL') ?? '', '/');
    if ($siteUrl) {
        $allowedOrigins[] = $siteUrl;
    }

    // No CORS headers for same-origin / non-browser requests.
    if (!$origin) {
        return $response;
    }

    // Only allow exact allowlist matches; never wildcard with credentials.
    if (!in_array($origin, $allowedOrigins, true)) {
        return $response->withHeader('Vary', 'Origin');
    }

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Vary', 'Origin')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
});
