<?php

declare(strict_types=1);

use Pimple\Container;
use Pimple\Psr11\Container as Psr11Container;
use Slim\Factory\AppFactory;
use App\Factory\LoggerFactory;

$container = new Container();
$app       = AppFactory::create(null, new Psr11Container($container));

// Resolve log level from LOG_LEVEL env var (default: warning).
// Supported values (case-insensitive):
//   debug   — everything: DB queries, every step
//   info    — successful operations
//   warning — unexpected states  (default)
//   error   — only hard errors / exceptions
$_logLevelMap = [
    'debug'   => \Monolog\Logger::DEBUG,
    'info'    => \Monolog\Logger::INFO,
    'warning' => \Monolog\Logger::WARNING,
    'error'   => \Monolog\Logger::ERROR,
];
$_logLevelRaw   = strtolower($_SERVER['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?: 'warning');
$_resolvedLevel = $_logLevelMap[$_logLevelRaw] ?? \Monolog\Logger::WARNING;

// Error / debug logger
$container['error_log'] = function () use ($_resolvedLevel) {
    $cfg = [
        'name'            => 'app',
        'path'            => __DIR__ . '/../../logs',
        'filename'        => 'app.log',
        'level'           => $_resolvedLevel,
        'file_permission' => 0775,
    ];
    return (new LoggerFactory($cfg))->addFileHandler('error.log')->createInstance('error');
};
