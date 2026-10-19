<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

/**
 * Reporte SOLO LECTURA de posibles movimientos duplicados en la cuenta 14 (y demás de
 * registro_financieros). Agrupa por codigo_proyecto + cuenta_contable + tercero + documento +
 * valor_debito + periodo y lista los grupos con más de un registro. No borra nada: dos facturas
 * distintas del mismo valor son legítimas y solo el documento las distingue, así que la decisión
 * es humana.
 */
class DuplicadosController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;

        $grupos = RegistroFinanciero::query()
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->selectRaw('codigo_proyecto, cuenta_contable, tercero_dcto, documento, valor_debito, periodo,
                MAX(nombre_proyecto) as nombre_proyecto,
                MAX(razon_social) as razon_social,
                COUNT(*) as repeticiones,
                GROUP_CONCAT(id) as ids')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto', 'documento', 'valor_debito', 'periodo')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(1000)
            ->get();

        $anios = RegistroFinanciero::selectRaw('DISTINCT anio')->orderByDesc('anio')->pluck('anio');

        return view('contable.duplicados', [
            'grupos'      => $grupos,
            'anio'        => $anio,
            'anios'       => $anios,
            'totalGrupos' => $grupos->count(),
            'totalFilas'  => (int) $grupos->sum('repeticiones'),
        ]);
    }
}
