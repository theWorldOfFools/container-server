<?php
declare(strict_types=1);

namespace App;

use PDO;

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $host = Config::get('DB_HOST', '/var/run/postgresql');
        $port = Config::get('DB_PORT', '5432');
        $db   = Config::get('DB_DATABASE', 'container_server');
        $user = Config::get('DB_USERNAME', 'container_app');
        $pass = Config::get('DB_PASSWORD', '');

        // Dukung unix socket dir (/var/run/postgresql) maupun host TCP.
        if (str_starts_with($host, '/')) {
            $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        } else {
            $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        }
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo = $pdo;
        return $pdo;
    }
}
