<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\AutorizacionDistribucion;
use App\Models\RegistroFinanciero;
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
            'monto'           => 'required|numeric|min:0',
            'motivo'          => 'required|string|max:1000',
        ], [], ['motivo' => 'motivo', 'monto' => 'monto a distribuir']);

        $cod   = trim($datos['codigo_proyecto']);
        $mes   = (int) $datos['mes'];
        $anio  = (int) $datos['anio'];
        $monto = (float) $datos['monto'];

        $aut = AutorizacionDistribucion::firstOrNew([
            'codigo_proyecto' => $cod,
            'mes'             => $mes,
            'anio'            => $anio,
        ]);

        if ($aut->exists && $aut->estado === AutorizacionDistribucion::APROBADA) {
            return back()->with('info', "El proyecto {$cod} ya está autorizado para este período.");
        }

        // Snapshot del impacto financiero con el monto que el operador pretende distribuir.
        $ingresoMes  = $this->sumaMes('Ingreso', $cod, $mes, $anio);
        $costoAplMes = abs($this->sumaMes('Costos aplicados', $cod, $mes, $anio));
        $ingresoAcum = $this->sumaAcum('Ingreso', $cod, $mes, $anio);
        $costoAplAcum = abs($this->sumaAcum('Costos aplicados', $cod, $mes, $anio));

        $margenMesPesos   = $ingresoMes  - ($costoAplMes  + $monto);
        $margenTotalPesos = $ingresoAcum - ($costoAplAcum + $monto);
        // Sin ingreso en el mes => el % no tiene denominador válido (se marca "—" en la UI).
        $margenMesPct   = abs($ingresoMes)  > 0.5 ? round($margenMesPesos   / $ingresoMes  * 100, 2) : null;
        $margenTotalPct = abs($ingresoAcum) > 0.5 ? round($margenTotalPesos / $ingresoAcum * 100, 2) : null;

        // Nueva solicitud (o reintento tras un rechazo): vuelve a 'pendiente'.
        $aut->fill([
            'estado'              => AutorizacionDistribucion::PENDIENTE,
            'motivo'              => $datos['motivo'],
            'monto_a_distribuir'  => $monto,
            'margen_mes_pesos'    => $margenMesPesos,
            'margen_mes_pct'      => $margenMesPct,
            'margen_total_pesos'  => $margenTotalPesos,
            'margen_total_pct'    => $margenTotalPct,
            'solicitado_por'      => $request->user()->id,
            'solicitado_at'       => now(),
            'resuelto_por'        => null,
            'resuelto_at'         => null,
            'comentario_gerencia' => null,
        ])->save();

        return back()->with('success',
            "Solicitud de autorización enviada para {$cod}. Gerencia debe aprobarla.");
    }

    /** Suma de estado_er de una cuenta mayor para un proyecto en un mes/año. */
    private function sumaMes(string $cuentaMayor, string $cod, int $mes, int $anio): float
    {
        return (float) RegistroFinanciero::where('cuenta_mayor', $cuentaMayor)
            ->where('codigo_proyecto', $cod)
            ->where('anio', $anio)->where('mes', $mes)
            ->sum('estado_er');
    }

    /** Suma acumulada (toda la historia hasta el mes/año) de una cuenta mayor para un proyecto. */
    private function sumaAcum(string $cuentaMayor, string $cod, int $mes, int $anio): float
    {
        return (float) RegistroFinanciero::where('cuenta_mayor', $cuentaMayor)
            ->where('codigo_proyecto', $cod)
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(function ($q2) use ($anio, $mes) {
                      $q2->where('anio', $anio)->where('mes', '<=', $mes);
                  });
            })
            ->sum('estado_er');
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

        // Cliente de cada proyecto (para mostrarlo junto a la solicitud).
        $codigos = $pendientes->pluck('codigo_proyecto')
            ->merge($resueltas->pluck('codigo_proyecto'))->unique();
        $clientes = \App\Models\FichaProyecto::whereIn('codigo_proyecto', $codigos)
            ->pluck('cliente', 'codigo_proyecto');

        return view('operativo.autorizaciones', compact('pendientes', 'resueltas', 'clientes'));
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
