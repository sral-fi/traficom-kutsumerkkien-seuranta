<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;

$customErrorHandler = function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app, $container): Response {
    if ($logErrors) {
        try {
            $logger = $container->offsetGet('error_log');
            $logger->error($exception->getMessage(), [
                'class' => get_class($exception),
                'file'  => $exception->getFile(),
                'line'  => $exception->getLine(),
            ]);
        } catch (\Throwable $e) {
            // logger unavailable — silently skip
        }
    }
    $statusCode = 500;
    if (is_int($exception->getCode()) &&
        $exception->getCode() >= 400 &&
        $exception->getCode() <= 599
    ) {
        $statusCode = $exception->getCode();
    }

    if ($displayErrorDetails) {
        $className = new \ReflectionClass(get_class($exception));
        $data = [
            'message' => $exception->getMessage(),
            'class'   => $className->getShortName(),
            'status'  => 'error',
            'code'    => $statusCode,
        ];
    } else {
        $clientMessages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
        ];
        $data = [
            'message' => $clientMessages[$statusCode] ?? ($statusCode >= 500 ? 'Internal Server Error' : 'Request failed'),
            'status' => 'error',
            'code'   => $statusCode,
        ];
    }

    $body     = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $response = $app->getResponseFactory()->createResponse();
    $response->getBody()->write($body);

    return $response
        ->withStatus($statusCode)
        ->withHeader('Content-Type', 'application/problem+json');
};
