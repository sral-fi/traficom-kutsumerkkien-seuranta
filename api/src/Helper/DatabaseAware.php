<?php

declare(strict_types=1);

namespace App\Helper;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

trait DatabaseAware
{
    /**
     * Get the database connection.
     */
    protected function getDb(): \App\Database
    {
        return $this->container->get('db');
    }

    /**
     * Get the error logger. Controlled by LOG_LEVEL in .env:
     *   debug   — verbose output
     *   info    — successful operations
     *   warning — unexpected states  (default)
     *   error   — exceptions / hard errors
     */
    protected function logger(): LoggerInterface
    {
        try {
            return $this->container->get('error_log');
        } catch (\Throwable $e) {
            return new NullLogger();
        }
    }
}
