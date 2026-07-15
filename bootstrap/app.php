<?php

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
        // Confía en el proxy de Cloudflare (túnel) para que Laravel
        // detecte que la conexión es HTTPS y genere las URLs correctamente.
        $middleware->trustProxies(at: '*');

        // Alias para exigir acceso a un módulo por ruta (permisos por módulo).
        $middleware->alias([
            'modulo' => \App\Http\Middleware\RequiereModulo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();