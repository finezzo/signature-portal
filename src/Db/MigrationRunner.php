<?php
declare(strict_types=1);

namespace App\Db;

use PDO;
use RuntimeException;

/**
 * Idempotent migration runner.
 *
 * Reads /migrations/*.sql in lexical order, applies new ones, and records
 * each run in a `migrations` table. Statements within a file are split on
 * `;` at end-of-line — fine for plain DDL/DML; do not put procedure bodies
 * into migrations (use multiple files instead).
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {}

    /** @return list<string> Names of migrations applied during this run. */
    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedNames();
        $files   = $this->migrationFiles();

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            $this->apply($file, $name);
            $ran[] = $name;
        }
        return $ran;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM migrations');
        return array_map(static fn(array $r): string => (string) $r['name'], $stmt->fetchAll());
    }

    /** @return list<string> Absolute paths, lexically sorted. */
    private function migrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            throw new RuntimeException("Migrations directory not found: {$this->migrationsDir}");
        }
        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function apply(string $file, string $name): void
    {
        // Note: MySQL implicitly commits on every DDL statement, so wrapping a
        // migration in a transaction does not make it atomic. Migrations must
        // be written so that re-running them after a partial failure is safe
        // (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS, etc.).
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read migration: {$file}");
        }

        try {
            foreach ($this->splitStatements($sql) as $stmt) {
                $this->pdo->exec($stmt);
            }
            $ins = $this->pdo->prepare('INSERT INTO migrations (name) VALUES (:name)');
            $ins->execute([':name' => $name]);
        } catch (\Throwable $e) {
            throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> */
    private function splitStatements(string $sql): array
    {
        // Strip /* ... */ block comments and -- line comments, then split on `;` at line end.
        $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }
}
