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

// ROOT CAUSE (verified live 2026-11-06): Symfony's Request::prepareBaseUrl()
// treats the directory of SCRIPT_NAME ("/api") as the app's base URL
// and strips it from the request path, so "/api/v1/accounts" reaches
// Laravel as "v1/accounts" -> 404. Point SCRIPT_NAME/PHP_SELF at the
// root ("/index.php") so no "/api" prefix is stripped. This also
// keeps plain web paths ("/login", "/pay/...") working.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Restore the "/api" prefix Vercel strips from API requests.
// How the path arrives depends on which Vercel routing path handled
// the request: via the vercel.json catch-all it is "/v1/..."; via
// Vercel's native /api function routing it can be
// "/api/index.php/v1/..." with PATH_INFO "/v1/...". Normalise all
// of them to "/api/v1/..." before Laravel boots. Requests that
// already arrive as "/api/v1/..." are left untouched.
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$candidate = null;
foreach ([$uriPath, $pathInfo] as $p) {
    if (str_starts_with($p, '/v1/') || $p === '/v1') {
        $candidate = $p;
        break;
    }
    if (str_contains($p, '/index.php/v1/')) {
        $candidate = substr($p, strpos($p, '/index.php/v1/') + strlen('/index.php'));
        break;
    }
}
if ($candidate !== null) {
    $query = parse_url($requestUri, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = '/api'.$candidate.($query !== null && $query !== '' ? '?'.$query : '');
    $_SERVER['PATH_INFO'] = '/api'.$candidate;
}

require __DIR__.'/../public/index.php';
