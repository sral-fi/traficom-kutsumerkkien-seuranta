<?php

declare(strict_types=1);

namespace App\Factory;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    /** @var string */
    private $path;

    /** @var int */
    private $level;

    /** @var array */
    private $handler = [];

    public function __construct(array $settings)
    {
        $this->path  = (string) $settings['path'];
        $this->level = (int)    $settings['level'];
    }

    public function createInstance(string $name): LoggerInterface
    {
        $logger = new Logger($name);

        foreach ($this->handler as $handler) {
            $logger->pushHandler($handler);
        }

        $this->handler = [];

        return $logger;
    }

    public function addFileHandler(string $filename, ?int $level = null): self
    {
        $filename = sprintf('%s/%s', $this->path, $filename);

        $rotatingFileHandler = new RotatingFileHandler(
            $filename,
            0,
            $level ?? $this->level,
            true,
            0777
        );

        // The last "true" tells Monolog to remove empty []'s from output
        $rotatingFileHandler->setFormatter(
            new LineFormatter(null, null, false, true)
        );

        $this->handler[] = $rotatingFileHandler;

        return $this;
    }

    public function addConsoleHandler(?int $level = null): self
    {
        $streamHandler = new StreamHandler('php://stdout', $level ?? $this->level);
        $streamHandler->setFormatter(new LineFormatter(null, null, false, true));

        $this->handler[] = $streamHandler;

        return $this;
    }
}
