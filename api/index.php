<?php

/**
 * Vercel serverless entry point for the UPI Gateway (Laravel 12).
 *
 * Vercel's filesystem is read-only except /tmp, so Laravel's writable
 * paths are pointed at /tmp before the normal front controller runs.
 * This file only forwards the request — it changes no app behaviour.
 */

foreach ([
    '/tmp/storage/framework/views',
    '/tmp/storage/framework/cache',
    '/tmp/storage/framework/sessions',
    '/tmp/storage/logs',
    '/tmp/bootstrap/cache',
] as $dir) {
    if (! is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Drop stale package-discovery caches so Laravel rediscovers only the
// packages actually present in the serverless bundle.
foreach (['packages.php', 'services.php'] as $cacheFile) {
    $path = __DIR__.'/../bootstrap/cache/'.$cacheFile;
    if (file_exists($path)) {
        @unlink($path);
    }
}

require __DIR__.'/../public/index.php';
