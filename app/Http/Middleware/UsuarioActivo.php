<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UsuarioActivo
{
    /** Rutas a las que SÍ puede entrar un usuario de solo consulta (todo lo demás → 403). */
    private const CONSULTA_OBRAS_PERMITIDAS = [
        'operativo.consulta-obras',
        'profile.edit', 'profile.update', 'profile.destroy',
        'preferencias.menu',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->activo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
            ]);
        }

        // Usuario de SOLO CONSULTA: encerrado en "Consulta de Obras". Se valida aquí (en el flujo auth
        // global) para cubrir TODAS las rutas autenticadas, incluido /dashboard que vive fuera del grupo.
        $user = Auth::user();
        if ($user && $user->esConsultaObras()) {
            $ruta = $request->route()?->getName();
            if (! in_array($ruta, self::CONSULTA_OBRAS_PERMITIDAS, true)) {
                // La raíz y el dashboard no son "accesos prohibidos" sino su inicio: se reencaminan.
                if ($ruta === 'dashboard' || $request->is('/') || $request->is('dashboard')) {
                    return redirect()->route('operativo.consulta-obras');
                }
                abort(403, 'Tu usuario solo tiene acceso a Consulta de Obras.');
            }
        }

        return $next($request);
    }
}