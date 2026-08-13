<?php
declare(strict_types=1);

/**
 * Copy this file to config/config.php and fill in the values.
 * The web installer (public/install.php) generates this file automatically.
 *
 * Do NOT commit config/config.php — it contains secrets.
 */

return [
    'db' => [
        'host' => 'mysql',
        'port' => 3306,
        'name' => 'signatureportal',
        'user' => 'sigportal',
        'pass' => 'sigportal',
    ],

    // 32 random bytes, base64-encoded, prefixed with "base64:".
    // Used for libsodium crypto_secretbox encryption of secrets at rest.
    // DO NOT change after install — existing encrypted values become unrecoverable.
    'app_key' => 'base64:REPLACE_ME',

    // Public URL of this install (no trailing slash). Used for OAuth redirects
    // and the add-in manifest generator.
    'base_url' => 'http://localhost:8090',

    // When true, unhandled exceptions render with full stack traces in the
    // browser. NEVER set true in production — leaks file paths, source code,
    // and DB internals to anyone who can trigger an error.
    'debug' => false,

    'auth' => [
        'local' => ['enabled' => true],
        'entra' => ['enabled' => false],
    ],

    'session' => [
        'name'     => 'sigportal_sid',
        'lifetime' => 120, // minutes
    ],

    // Transactional mail (password reset links). Sent via PHP mail() — works
    // on shared hosting without SMTP credentials. 'from' defaults to
    // no-reply@<host of base_url> when omitted; some hosts require the domain
    // to match one of your hosted domains for delivery.
    'mail' => [
        'from'      => null,               // e.g. 'no-reply@signatures.example.com'
        'from_name' => 'SignaturePortal',
    ],
];
