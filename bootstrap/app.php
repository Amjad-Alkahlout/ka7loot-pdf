<?php

use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust loopback and private Docker container subnets (Host Nginx -> Docker web -> app)
        // Avoids trusting arbitrary external proxy headers from untrusted clients
        $middleware->trustProxies(
            at: explode(',', env('TRUSTED_PROXIES', '127.0.0.1,172.16.0.0/12,10.0.0.0/8,192.168.0.0/16'))
        );

        $middleware->web(append: [
            SetLocaleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
