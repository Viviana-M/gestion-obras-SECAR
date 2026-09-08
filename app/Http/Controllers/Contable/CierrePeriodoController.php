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

        // Años disponibles: desde 2022 (hay datos desde entonces) hasta el año en curso,
        // más cualquier año que ya tenga registros de cierre. Descendente para el filtro.
        $anioActual  = (int) date('Y');
        $conRegistro = CierrePeriodo::query()->distinct()->orderBy('anio')->pluck('anio')->all();
        $minAnio = min(array_merge([2022, $anioActual], $conRegistro));
        $maxAnio = max(array_merge([$anioActual], $conRegistro));
        $anios   = range($maxAnio, $minAnio);

        $anio = (int) $request->get('anio', $anioActual);
        if (! in_array($anio, $anios, true)) {
            $anio = $anioActual;
        }

        // Los 12 meses del año elegido, con su estado.
        $registros = CierrePeriodo::where('anio', $anio)->get()->keyBy('mes');
        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $c = $registros[$m] ?? null;
            $meses[] = [
                'mes'        => $m,
                'anio'       => $anio,
                'abierto'    => (bool) ($c?->abierto),
                'abierto_at' => $c?->abierto_at,
                'cerrado_at' => $c?->cerrado_at,
            ];
        }

        return view('contable.cierre', compact('anios', 'anio', 'meses'));
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
