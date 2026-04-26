<?php
declare(strict_types=1);

namespace App\Db;

use App\Config\Config;
use PDO;

final class Database
{
    public static function fromConfig(Config $config): PDO
    {
        return self::connect(
            (string) $config->get('db.host', '127.0.0.1'),
            (int)    $config->get('db.port', 3306),
            (string) $config->get('db.name', ''),
            (string) $config->get('db.user', ''),
            (string) $config->get('db.pass', ''),
        );
    }

    public static function connect(
        string $host,
        int $port,
        string $name,
        string $user,
        string $pass,
    ): PDO {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    }
}
