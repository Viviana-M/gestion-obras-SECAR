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
 * Reporte "Cruce cuenta 14 vs SECAR (neteo por UN)".
 *
 * En la cuenta 14 ("Costos por aplicar") muchas obras tienen costos reversados contra el propio
 * tercero de SECAR, que inflan el saldo. Este reporte separa, por obra, el saldo contra SECAR del
 * saldo contra terceros reales, y los netea para ver el pendiente real.
 *
 * SECAR se identifica por RAZÓN SOCIAL que contenga "SECAR" (no por NIT: el NIT aparece con
 * variantes, p. ej. 890319324 y 90319324), para capturarlas todas.
 */
class CruceSecarController extends Controller
{
    /** Identificación de SECAR: por nombre (captura todas las variantes de NIT). */
    private const CASE_SECAR = "razon_social LIKE '%SECAR%'";

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $filas = $this->datos();

        return view('contable.cruce-secar', [
            'filas'          => $filas,
            'totalSecar'     => array_sum(array_column($filas, 'saldo_secar')),
            'totalTerceros'  => array_sum(array_column($filas, 'saldo_terceros')),
            'totalNeto'      => array_sum(array_column($filas, 'saldo_neto')),
        ]);
    }

    public function excel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $filas = $this->datos();

        $rows = [['Código', 'Obra', 'Estado ficha', 'Saldo SECAR', 'Saldo terceros', 'Saldo neto', 'Marca']];
        foreach ($filas as $f) {
            $rows[] = [
                $f['codigo'], $f['nombre'], $f['estado'],
                round($f['saldo_secar'], 2), round($f['saldo_terceros'], 2), round($f['saldo_neto'], 2), $f['marca'],
            ];
        }
        $rows[] = [
            '', '', 'TOTAL',
            round(array_sum(array_column($filas, 'saldo_secar')), 2),
            round(array_sum(array_column($filas, 'saldo_terceros')), 2),
            round(array_sum(array_column($filas, 'saldo_neto')), 2), '',
        ];

        return Excel::download(new CruceSecarExport($rows), 'Cruce_cuenta_14_vs_SECAR_'.date('Ymd').'.xlsx');
    }

    /**
     * Un solo GROUP BY por obra con CASE WHEN sobre razon_social LIKE '%SECAR%' para separar el
     * saldo contra SECAR del saldo contra terceros reales y netearlos. Se muestran las obras con
     * saldo SECAR ≠ 0 O saldo neto ≠ 0, de mayor a menor por |saldo_neto|.
     *
     * @return array<int, array{codigo:string,nombre:string,estado:string,saldo_secar:float,saldo_terceros:float,saldo_neto:float,marca:string}>
     */
    private function datos(): array
    {
        $secar = self::CASE_SECAR;

        $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw("codigo_proyecto,
                MAX(nombre_proyecto) as nombre_proyecto,
                SUM(CASE WHEN {$secar} THEN estado_er ELSE 0 END) as saldo_secar,
                SUM(CASE WHEN {$secar} THEN 0 ELSE estado_er END) as saldo_terceros")
            ->groupBy('codigo_proyecto')
            // saldo_secar ≠ 0  O  saldo_neto (= secar + terceros = SUM(estado_er)) ≠ 0
            ->havingRaw("ABS(SUM(CASE WHEN {$secar} THEN estado_er ELSE 0 END)) > 0.5 OR ABS(SUM(estado_er)) > 0.5")
            ->orderByRaw("ABS(SUM(estado_er)) DESC")
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
            $terceros = (float) $r->saldo_terceros;
            $neto     = $secarSal + $terceros;

            $estado = '—';
            if ($ficha && $tieneActiva) {
                $estado = $ficha->activa ? 'Activa' : 'Inactiva';
            }

            $filas[] = [
                'codigo'         => (string) $r->codigo_proyecto,
                'nombre'         => (string) (($ficha->nombre_obra ?? null) ?: $r->nombre_proyecto),
                'estado'         => $estado,
                'saldo_secar'    => $secarSal,
                'saldo_terceros' => $terceros,
                'saldo_neto'     => $neto,
                // Se netea a ~$0 si el pendiente real es insignificante (|neto| <= $1.000).
                'marca'          => abs($neto) <= 1000 ? 'Se netea a ~$0' : 'Pendiente real',
            ];
        }

        return $filas;
    }
}
