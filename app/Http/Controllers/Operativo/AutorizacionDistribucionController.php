<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\AutorizacionDistribucion;
use Illuminate\Http\Request;

class AutorizacionDistribucionController extends Controller
{
    /** Gerencia: admin, gerente o cualquier director. */
    private function soloGerencia(Request $request): void
    {
        abort_unless($request->user()?->esGerencia(), 403,
            'Solo gerencia puede resolver autorizaciones de distribución.');
    }

    /**
     * Crea (o reabre) una solicitud de autorización para un proyecto sin ingreso.
     * La pide el operador (permiso de editar en Operación).
     */
    public function solicitar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $datos = $request->validate([
            'codigo_proyecto' => 'required|string|max:255',
            'mes'             => 'required|integer|between:1,12',
            'anio'            => 'required|integer|min:2020',
            'motivo'          => 'required|string|max:1000',
        ], [], ['motivo' => 'motivo']);

        $cod  = trim($datos['codigo_proyecto']);
        $mes  = (int) $datos['mes'];
        $anio = (int) $datos['anio'];

        $aut = AutorizacionDistribucion::firstOrNew([
            'codigo_proyecto' => $cod,
            'mes'             => $mes,
            'anio'            => $anio,
        ]);

        if ($aut->exists && $aut->estado === AutorizacionDistribucion::APROBADA) {
            return back()->with('info', "El proyecto {$cod} ya está autorizado para este período.");
        }

        // Nueva solicitud (o reintento tras un rechazo): vuelve a 'pendiente'.
        $aut->fill([
            'estado'              => AutorizacionDistribucion::PENDIENTE,
            'motivo'              => $datos['motivo'],
            'solicitado_por'      => $request->user()->id,
            'solicitado_at'       => now(),
            'resuelto_por'        => null,
            'resuelto_at'         => null,
            'comentario_gerencia' => null,
        ])->save();

        return back()->with('success',
            "Solicitud de autorización enviada para {$cod}. Gerencia debe aprobarla.");
    }

    /** Pantalla de gerencia: solicitudes pendientes y últimas resueltas. */
    public function index(Request $request)
    {
        $this->soloGerencia($request);

        $pendientes = AutorizacionDistribucion::with('solicitante')
            ->pendientes()
            ->orderBy('solicitado_at')
            ->get();

        $resueltas = AutorizacionDistribucion::with(['solicitante', 'resolvedor'])
            ->whereIn('estado', [AutorizacionDistribucion::APROBADA, AutorizacionDistribucion::RECHAZADA])
            ->orderByDesc('resuelto_at')
            ->limit(50)
            ->get();

        return view('operativo.autorizaciones', compact('pendientes', 'resueltas'));
    }

    public function aprobar(Request $request, AutorizacionDistribucion $autorizacion)
    {
        $this->soloGerencia($request);

        $datos = $request->validate([
            'comentario_gerencia' => 'nullable|string|max:1000',
        ]);

        $autorizacion->update([
            'estado'              => AutorizacionDistribucion::APROBADA,
            'resuelto_por'        => $request->user()->id,
            'resuelto_at'         => now(),
            'comentario_gerencia' => $datos['comentario_gerencia'] ?? null,
        ]);

        return back()->with('success',
            "Autorización aprobada para {$autorizacion->codigo_proyecto}.");
    }

    public function rechazar(Request $request, AutorizacionDistribucion $autorizacion)
    {
        $this->soloGerencia($request);

        $datos = $request->validate([
            'comentario_gerencia' => 'nullable|string|max:1000',
        ]);

        $autorizacion->update([
            'estado'              => AutorizacionDistribucion::RECHAZADA,
            'resuelto_por'        => $request->user()->id,
            'resuelto_at'         => now(),
            'comentario_gerencia' => $datos['comentario_gerencia'] ?? null,
        ]);

        return back()->with('success',
            "Autorización rechazada para {$autorizacion->codigo_proyecto}. El proyecto sigue bloqueado.");
    }
}
