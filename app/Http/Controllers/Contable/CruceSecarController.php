<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Exports\CruceSecarExport;
use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reporte "Cruce cuenta 14 vs tercero SECAR".
 *
 * En la cuenta 14 ("Costos por aplicar") muchas obras tienen movimientos reversados contra
 * el propio tercero de SECAR, que distorsionan el saldo real por aplicar. Este reporte separa,
 * por obra, cuánto del saldo es contra SECAR y cuánto es real (contra terceros reales).
 */
class CruceSecarController extends Controller
{
    /**
     * Identificación del tercero SECAR (la propia empresa). Se acepta el NIT con y sin el
     * dígito inicial, y como respaldo el nombre, para capturar todas las líneas de SECAR.
     */
    private const CASE_SECAR = "(tercero_dcto IN ('890319324','90319324') OR razon_social LIKE 'SECAR%')";

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $filas = $this->datos();

        return view('contable.cruce-secar', [
            'filas'        => $filas,
            'totalSecar'   => array_sum(array_column($filas, 'saldo_secar')),
            'totalGeneral' => array_sum(array_column($filas, 'saldo_total')),
            'totalReal'    => array_sum(array_column($filas, 'saldo_real')),
        ]);
    }

    public function excel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $filas = $this->datos();

        $rows = [['Código', 'Obra', 'Estado', 'Saldo SECAR', 'Saldo total', 'Saldo real (terceros)']];
        foreach ($filas as $f) {
            $rows[] = [
                $f['codigo'], $f['nombre'], $f['estado'],
                round($f['saldo_secar'], 2), round($f['saldo_total'], 2), round($f['saldo_real'], 2),
            ];
        }
        $rows[] = [
            '', '', 'TOTAL',
            round(array_sum(array_column($filas, 'saldo_secar')), 2),
            round(array_sum(array_column($filas, 'saldo_total')), 2),
            round(array_sum(array_column($filas, 'saldo_real')), 2),
        ];

        return Excel::download(new CruceSecarExport($rows), 'Cruce_cuenta_14_vs_SECAR_'.date('Ymd').'.xlsx');
    }

    /**
     * Un solo GROUP BY por obra con CASE WHEN para separar el saldo del tercero SECAR del
     * saldo total. Solo obras con saldo SECAR relevante (|saldo_secar| > 0.5), de mayor a menor.
     *
     * @return array<int, array{codigo:string,nombre:string,estado:string,saldo_secar:float,saldo_total:float,saldo_real:float}>
     */
    private function datos(): array
    {
        $secar = self::CASE_SECAR;

        $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw("codigo_proyecto,
                MAX(nombre_proyecto) as nombre_proyecto,
                SUM(CASE WHEN {$secar} THEN estado_er ELSE 0 END) as saldo_secar,
                SUM(estado_er) as saldo_total")
            ->groupBy('codigo_proyecto')
            ->havingRaw("ABS(SUM(CASE WHEN {$secar} THEN estado_er ELSE 0 END)) > 0.5")
            ->orderByRaw("ABS(SUM(CASE WHEN {$secar} THEN estado_er ELSE 0 END)) DESC")
            ->get();

        // Nombre y estado (activa/inactiva) desde el maestro de proyectos, si existen.
        $codigos     = $rows->pluck('codigo_proyecto')->all();
        $tieneActiva = Schema::hasColumn('ficha_proyectos', 'activa');
        $cols        = ['codigo_proyecto', 'nombre_obra'];
        if ($tieneActiva) {
            $cols[] = 'activa';
        }
        $fichas = FichaProyecto::whereIn('codigo_proyecto', $codigos)
            ->get($cols)
            ->keyBy('codigo_proyecto');

        $filas = [];
        foreach ($rows as $r) {
            $ficha    = $fichas[$r->codigo_proyecto] ?? null;
            $secarSal = (float) $r->saldo_secar;
            $total    = (float) $r->saldo_total;

            $estado = '—';
            if ($ficha && $tieneActiva) {
                $estado = $ficha->activa ? 'Activa' : 'Inactiva';
            }

            $filas[] = [
                'codigo'      => (string) $r->codigo_proyecto,
                'nombre'      => (string) (($ficha->nombre_obra ?? null) ?: $r->nombre_proyecto),
                'estado'      => $estado,
                'saldo_secar' => $secarSal,
                'saldo_total' => $total,
                'saldo_real'  => $total - $secarSal,
            ];
        }

        return $filas;
    }
}
