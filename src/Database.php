<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $host    = $_ENV['DB_HOST'] ?? 'localhost';
            $name    = $_ENV['DB_NAME'] ?? '';
            $user    = $_ENV['DB_USER'] ?? '';
            $pass    = $_ENV['DB_PASS'] ?? '';
            $charset = 'utf8mb4';

            self::$instance = new PDO(
                "mysql:host={$host};dbname={$name};charset={$charset}",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }

        return self::$instance;
    }
}
