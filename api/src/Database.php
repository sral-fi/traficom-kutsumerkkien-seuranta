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
        $name = $_ENV['DB_NAME']     ?? 'traficom_tracker';
        $user = $_ENV['DB_USER']     ?? 'traficom';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                503,
                $e
            );
        }
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
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Database query failed: ' . $e->getMessage(),
                503,
                $e
            );
        }
    }

    /** Prepare a statement without executing it (useful for repeated execution in loops). */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function beginTransaction(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void            { $this->pdo->commit(); }
    public function rollback(): void          { $this->pdo->rollBack(); }
}
