<?php
declare(strict_types=1);

/**
 * CLI migration runner.
 * Usage: docker compose exec app php bin/migrate.php
 */

use App\Config\Config;
use App\Db\Database;
use App\Db\MigrationRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$rootDir    = dirname(__DIR__);
$configPath = $rootDir . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "config/config.php not found — run the web installer first.\n");
    exit(1);
}

try {
    $config = Config::loadFromFile($configPath);
    $pdo    = Database::fromConfig($config);
    $ran    = (new MigrationRunner($pdo, $rootDir . '/migrations'))->run();

    if ($ran === []) {
        echo "Database is up to date.\n";
    } else {
        echo "Applied:\n";
        foreach ($ran as $name) {
            echo "  - {$name}\n";
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
