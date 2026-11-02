<?php
declare(strict_types=1);

namespace Tests\Auth;

use PDO;

/** An in-memory stand-in for the tables the two-factor code touches. */
final class MfaTestDatabase
{
    public static function create(): PDO
    {
        // PDO::sqliteCreateFunction() is deprecated from PHP 8.5, createFunction()
        // only exists on the driver subclass that PDO::connect() returns from 8.4.
        $pdo = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $now = static fn(): string => date('Y-m-d H:i:s');
        method_exists($pdo, 'createFunction') ? $pdo->createFunction('NOW', $now) : $pdo->sqliteCreateFunction('NOW', $now);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY, tenant_id INT, email TEXT, password_hash TEXT, password_changed_at TEXT,
                mfa_secret_encrypted TEXT, mfa_enabled_at TEXT, mfa_last_step INT, role TEXT, name TEXT,
                last_login_at TEXT, failed_login_count INT NOT NULL DEFAULT 0, last_failed_login_at TEXT, locked_until TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE mfa_recovery_codes (
                id INTEGER PRIMARY KEY, user_id INT, code_hash TEXT, used_at TEXT, created_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY, tenant_id INT, user_id INT, user_email TEXT, action TEXT, target_type TEXT,
                target_id TEXT, summary TEXT, payload_json TEXT, ip_address TEXT
            )'
        );
        return $pdo;
    }
}
