<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vercel terminates TLS and forwards the request to PHP over
        // plain HTTP with X-Forwarded-Proto: https. Without trusting
        // the proxy, Laravel generates http:// URLs, so every form
        // posts over HTTP and browsers block it as an insecure form.
        // Trusting the proxy makes URL generation honour the original
        // https scheme. Local dev sends no such header, so local URLs
        // stay http.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'active' => \App\Http\Middleware\EnsureActive::class,
            'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);
        $middleware->redirectUsersTo('/dashboard');
        // Paytm PG POSTs the customer back here from its own domain.
        $middleware->validateCsrfTokens(except: ['pay/*/return']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
