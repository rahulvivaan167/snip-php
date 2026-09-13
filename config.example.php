<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and edit it. config.php is git-ignored.
 *
 * If config.php does not exist the app falls back to these values, which
 * are enough to run it locally with no setup at all.
 */

return [
    'app_name' => 'Snip',

    // Leave null to work it out from the request. Set it in production,
    // e.g. 'https://snip.example.com', so links are always built correctly.
    'base_url' => null,

    'db' => [
        // 'sqlite' or 'mysql'
        'driver' => 'sqlite',

        // SQLite: the file is created and migrated on first run.
        'sqlite_path' => __DIR__ . '/storage/links.sqlite',

        // MySQL: run database/schema.mysql.sql yourself, then fill these in.
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'snip',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    // Characters in a generated code. 7 gives ~1.6 quadrillion combinations.
    'code_length' => 7,

    // Links one visitor may create per hour. 0 turns the limit off.
    'rate_limit_per_hour' => 30,

    // Refuse to shorten anything that resolves to a private or loopback
    // address. Turn this off only if you are shortening intranet links.
    'block_private_hosts' => true,

    // Key used to hash visitor IP addresses before they are stored.
    // Change it, keep it secret, and note that changing it later resets
    // unique-visitor counts.
    'hash_key' => 'change-this-to-a-long-random-string',

    // Set a string here to require "Authorization: Bearer <token>" on the
    // write API. Leave empty to let anyone POST to /api/links.
    'api_token' => '',

    // Only true behind a proxy or load balancer you control, otherwise
    // visitors can spoof their own IP address with a header.
    'trust_proxy' => false,

    // Shows exceptions in the browser. Never enable in production.
    'debug' => false,
];
