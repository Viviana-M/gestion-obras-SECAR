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
        // Sesión/token expirado (error 419 "Page Expired"): pasa cuando la página de
        // ingreso queda abierta (o cacheada) hasta que caduca el token o la sesión y
        // luego se envía. En vez de mostrar la pantalla cruda de Laravel, devolvemos
        // al usuario al login con un aviso claro para que vuelva a intentar.
        // Laravel ya convirtió el TokenMismatchException a un HttpException 419 para
        // cuando corren estos callbacks, así que filtramos por el código 419.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null; // deja que Laravel maneje los demás errores HTTP normalmente
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.',
                ], 419);
            }

            return redirect()->route('login')
                ->withInput($request->except('password', 'password_confirmation', '_token'))
                ->with('status', 'Tu sesión expiró por inactividad. Por favor inicia sesión de nuevo.');
        });
    })->create();