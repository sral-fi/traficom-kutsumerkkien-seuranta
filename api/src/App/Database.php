<?php

declare(strict_types=1);

use Pimple\Container;

/** @var Container $container */
$container['db'] = static function (): \App\Database {
    return \App\Database::getInstance();
};
