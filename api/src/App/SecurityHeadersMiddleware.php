<?php

declare(strict_types=1);

namespace App\App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Adds security headers to all responses.
 */
class SecurityHeadersMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        $response = $response->withHeader('X-Frame-Options', 'DENY');
        $response = $response->withHeader('X-XSS-Protection', '1; mode=block');
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
        $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response = $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response = $response->withHeader('Pragma', 'no-cache');
        $response = $response->withHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}
