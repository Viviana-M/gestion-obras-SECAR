<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\CierrePeriodo;
use Illuminate\Http\Request;

/**
 * Contabilidad abre/cierra el período de edición de la Distribución. Con el cierre
 * ABIERTO, Operaciones puede editar ese mes; cerrado (o sin registro) = solo lectura.
 */
class CierrePeriodoController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        // Últimos 24 meses (para abrir/cerrar), con su estado actual.
        $registros = CierrePeriodo::orderByDesc('anio')->orderByDesc('mes')->get()
            ->keyBy(fn ($c) => $c->anio.'-'.$c->mes);

        $hoyMes = (int) date('n');
        $hoyAnio = (int) date('Y');
        $periodos = [];
        for ($i = 0; $i < 18; $i++) {
            $m = $hoyMes - $i;
            $a = $hoyAnio;
            while ($m <= 0) { $m += 12; $a--; }
            $c = $registros[$a.'-'.$m] ?? null;
            $periodos[] = [
                'mes'     => $m,
                'anio'    => $a,
                'abierto' => (bool) ($c?->abierto),
                'abierto_at' => $c?->abierto_at,
                'cerrado_at' => $c?->cerrado_at,
            ];
        }

        return view('contable.cierre', ['periodos' => $periodos]);
    }

    public function toggle(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $datos = $request->validate([
            'mes'    => 'required|integer|between:1,12',
            'anio'   => 'required|integer|min:2020',
            'accion' => 'required|in:abrir,cerrar',
        ]);

        $abrir = $datos['accion'] === 'abrir';
        $cierre = CierrePeriodo::firstOrNew(['mes' => (int) $datos['mes'], 'anio' => (int) $datos['anio']]);
        $cierre->abierto = $abrir;
        if ($abrir) {
            $cierre->abierto_por = $request->user()->id;
            $cierre->abierto_at  = now();
        } else {
            $cierre->cerrado_por = $request->user()->id;
            $cierre->cerrado_at  = now();
        }
        $cierre->save();

        $nombresMes = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
        $etiqueta = ($nombresMes[(int) $datos['mes']] ?? $datos['mes']).' '.$datos['anio'];

        return back()->with('success', $abrir
            ? "Cierre de {$etiqueta} ABIERTO: Operaciones ya puede editar la distribución de ese mes."
            : "Cierre de {$etiqueta} CERRADO: la distribución de ese mes queda en solo lectura.");
    }
}
