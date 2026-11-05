<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el usuario autenticado tenga acceso a un modulo antes de entrar
 * a las rutas del grupo. El modelo de permisos (User::puedeVerModulo /
 * puedeEditarModulo) existia pero solo se aplicaba en unos pocos controladores;
 * este middleware lo hace cumplir a nivel de ruta.
 *
 * Uso en rutas:
 *   Route::middleware('modulo:contabilidad')->group(...);        // requiere ver
 *   Route::middleware('modulo:contabilidad,editar')->group(...); // requiere editar
 */
class RequiereModulo
{
    public function handle(Request $request, Closure $next, string $modulo, string $nivel = 'ver'): Response
    {
        $usuario = $request->user();

        $permitido = $usuario && ($nivel === 'editar'
            ? $usuario->puedeEditarModulo($modulo)
            : $usuario->puedeVerModulo($modulo));

        abort_unless($permitido, 403, 'No tienes permiso para acceder a este módulo.');

        return $next($request);
    }
}
