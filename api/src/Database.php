<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

/**
 * Minimal PDO wrapper – singleton per PHP-FPM worker lifetime.
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $host = $_ENV['DB_HOST']     ?? 'localhost';
        $port = $_ENV['DB_PORT']     ?? '3306';
        $name = $_ENV['DB_NAME']     ?? 'calls_tracker';
        $user = $_ENV['DB_USER']     ?? 'calls_tracker';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Prepare, execute and return the statement (use fetchAll / fetch on the result). */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
