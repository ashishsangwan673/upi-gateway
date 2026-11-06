<?php

/**
 * Vercel serverless entry point for the UPI Gateway (Laravel 12).
 *
 * Vercel's filesystem is read-only except /tmp, so Laravel's writable
 * paths are pointed at /tmp before the normal front controller runs.
 * This file only forwards the request — it changes no app behaviour.
 *
 * Vercel routing quirk (verified live 2026-11-06): a request to
 * /api/v1/... reaches this function with the leading "/api" segment
 * stripped, i.e. Laravel sees "v1/accounts" and returns 404, while
 * /api/login reaches it as "/login" and works. Non-/api paths are
 * passed through untouched. So, before Laravel boots, restore the
 * "/api" prefix for paths that look like API calls ("/v1/...").
 * If Vercel ever stops stripping, the guard below is a no-op because
 * such requests arrive as "/api/v1/..." and do not start with "/v1/".
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

// Restore the "/api" prefix Vercel strips from API requests.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
if (str_starts_with($uriPath, '/v1/') || $uriPath === '/v1') {
    $rest = parse_url($requestUri, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = '/api'.$uriPath.($rest !== null && $rest !== '' ? '?'.$rest : '');
    $_SERVER['PATH_INFO'] = '/api'.$uriPath;
}

require __DIR__.'/../public/index.php';
