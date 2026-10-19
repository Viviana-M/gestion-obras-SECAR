<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\ObraEstado;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Consulta de proyectos para GERENCIA (solo lectura). Lista de obras con su responsable (de la ficha)
 * y su margen real (MC%); al expandir cada obra muestra su esquema de costos por categoría con PART.%
 * (participación sobre el ingreso), y permite comparar el margen ofertado vs real.
 */
class ConsultaProyectosController extends Controller
{
    private DistribucionService $svc;

    private const CATS = ['MOI', 'EQU-MAT-SUM', 'MOE', 'MOFIJAOPER', 'OTROS COSTO'];

    public function __construct()
    {
        $this->svc = new DistribucionService();
    }

    public function index(Request $request)
    {
        [$mes, $anio] = $this->periodo($request);
        $departamento = $request->get('departamento');
        $tipo         = $request->get('tipo', 'todos');
        $responsable  = $request->get('responsable');
        $q            = trim((string) $request->get('q', ''));

        $d = $this->datos($mes, $anio, $departamento, $tipo, $responsable, $q);

        return view('financiero.consulta-proyectos', [
            'obras'        => $d['obras'],
            'totales'      => $d['totales'],
            'responsables' => $d['responsables'],
            'cats'         => DistribucionService::CATEGORIAS,
            'mes'          => $mes,
            'anio'         => $anio,
            'departamento' => $departamento,
            'tipo'         => $tipo,
            'responsable'  => $responsable,
            'q'            => $q,
        ]);
    }

    public function excel(Request $request)
    {
        [$mes, $anio] = $this->periodo($request);
        $d = $this->datos($mes, $anio, $request->get('departamento'), $request->get('tipo', 'todos'),
            $request->get('responsable'), trim((string) $request->get('q', '')));

        $labels = DistribucionService::CATEGORIAS;
        $head = ['Código', 'Obra', 'Cliente', 'Responsable', 'Tipo', 'Ingreso'];
        foreach (self::CATS as $ck) $head[] = $labels[$ck];
        $head = array_merge($head, ['Total costo', 'Margen contribución', 'MC %', 'Ofertado %']);
        $filas = [$head];

        $fmtPct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
        foreach ($d['obras'] as $o) {
            $fila = [$o['codigo'], $o['nombre'], $o['cliente'], $o['responsable'] ?: '— Sin asignar —',
                ucfirst($o['tipo']), round($o['ingreso'])];
            foreach (self::CATS as $ck) $fila[] = round($o['cat'][$ck] ?? 0);
            $fila[] = round($o['total_costo']);
            $fila[] = round($o['mc']);
            $fila[] = $fmtPct($o['mc_pct']);
            $fila[] = $fmtPct($o['ofertado']);
            $filas[] = $fila;
        }
        $t = $d['totales'];
        $filaT = ['TOTAL', '', '', '', '', round($t['ingreso'])];
        foreach (self::CATS as $ck) $filaT[] = round($t['cat'][$ck] ?? 0);
        $filaT = array_merge($filaT, [round($t['total_costo']), round($t['mc']), $fmtPct($t['mc_pct']), '']);
        $filas[] = $filaT;

        $depTag = $request->get('departamento') ?: 'todos';

        return Excel::download(
            new \App\Exports\ConsultaProyectosExport($filas),
            'Proyectos_gerencia_'.$depTag.'_'.$anio.sprintf('%02d', $mes).'.xlsx'
        );
    }

    /** Período efectivo: el elegido, o el último con información cargada. */
    private function periodo(Request $request): array
    {
        $row = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first(['anio', 'mes']);
        $mes  = (int) $request->get('mes', $row->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $row->anio ?? (int) date('Y'));

        return [$mes, $anio];
    }

    /**
     * Arma la lista de obras con su esquema de costos, responsable y ofertado, aplicando los filtros.
     *
     * @return array{obras: array<int,array<string,mixed>>, totales: array<string,mixed>, responsables: array<int,string>}
     */
    private function datos(int $mes, int $anio, ?string $departamento, string $tipo, ?string $responsable, string $q): array
    {
        // Mismas obras que ve Operaciones en Distribución de costos: proyectos con saldo por distribuir
        // (cuenta 1420 neto ≥ $1) al corte — no todo el portafolio.
        $codigos = $this->svc->codigosConSaldoPorDistribuir($anio, $mes);

        $esquema = $this->svc->esquemaPorObra($anio, $mes);  // P&L (ingreso/costos) por obra
        $vacio   = ['ingreso' => 0.0, 'cat' => array_fill_keys(self::CATS, 0.0), 'total_costo' => 0.0, 'mc' => 0.0, 'mc_pct' => null];

        $fichas  = FichaProyecto::whereIn('codigo_proyecto', $codigos)
            ->get(['codigo_proyecto', 'nombre_obra', 'cliente', 'responsable_obra', 'responsable_comercial', 'margen_ofertado'])
            ->keyBy('codigo_proyecto');
        $nombres = RegistroFinanciero::whereIn('codigo_proyecto', $codigos)
            ->selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n')->groupBy('codigo_proyecto')->pluck('n', 'codigo_proyecto');

        // Lista de responsables disponibles (para el filtro), de las obras en el corte.
        $responsables = $fichas->pluck('responsable_obra')->map(fn ($r) => trim((string) $r))
            ->filter()->unique()->sort()->values()->all();

        // Una obra está CERRADA si tiene cierre contable (proyectos_cerrados) o su estado manual es
        // 'cerrada'. Gerencia solo ve Abiertas y Parciales (se excluyen las Cerradas).
        $cerradas     = ProyectoCerrado::pluck('codigo_proyecto')->flip();
        $estadoManual = ObraEstado::pluck('estado', 'codigo_proyecto');

        $obras = [];
        $tot = ['ingreso' => 0.0, 'cat' => array_fill_keys(self::CATS, 0.0), 'total_costo' => 0.0, 'mc' => 0.0];

        foreach ($codigos as $cod) {
            $pnl = $esquema[$cod] ?? $vacio;

            // Excluir las Cerradas: solo se muestran Abiertas y Parciales.
            if (isset($cerradas[$cod]) || ($estadoManual[$cod] ?? null) === 'cerrada') continue;

            $dep = User::departamentoDeCodigo($cod);
            if ($departamento && $dep !== $departamento) continue;

            $tk = $this->svc->tipoObra($cod);
            if ($tipo !== 'todos' && $tk !== $tipo) continue;

            $ficha = $fichas[$cod] ?? null;
            $resp  = trim((string) ($ficha->responsable_obra ?? ''));
            if ($responsable && $resp !== $responsable) continue;

            $nombre = ($ficha->nombre_obra ?? null) ?: ($nombres[$cod] ?? '');
            if ($q !== '' && stripos($cod.' '.$nombre.' '.($ficha->cliente ?? '').' '.$resp, $q) === false) continue;

            $obras[] = [
                'codigo'       => (string) $cod,
                'nombre'       => (string) $nombre,
                'cliente'      => (string) ($ficha->cliente ?? ''),
                'responsable'  => $resp ?: null,
                'resp_comercial' => trim((string) ($ficha->responsable_comercial ?? '')) ?: null,
                'tipo'         => $tk,
                'departamento' => $dep,
                'ingreso'      => $pnl['ingreso'],
                'cat'          => $pnl['cat'],
                'total_costo'  => $pnl['total_costo'],
                'mc'           => $pnl['mc'],
                'mc_pct'       => $pnl['mc_pct'],
                'ofertado'     => $ficha && $ficha->margen_ofertado !== null ? (float) $ficha->margen_ofertado : null,
            ];

            $tot['ingreso']     += $pnl['ingreso'];
            $tot['total_costo'] += $pnl['total_costo'];
            $tot['mc']          += $pnl['mc'];
            foreach (self::CATS as $ck) $tot['cat'][$ck] += $pnl['cat'][$ck] ?? 0;
        }

        // Peor margen primero (los $null al final): lo que gerencia debe revisar.
        usort($obras, function ($a, $b) {
            $am = $a['mc_pct']; $bm = $b['mc_pct'];
            if ($am === null && $bm === null) return 0;
            if ($am === null) return 1;
            if ($bm === null) return -1;
            return $am <=> $bm;
        });

        $tot['mc_pct'] = abs($tot['ingreso']) > 0.5 ? round($tot['mc'] / $tot['ingreso'] * 100, 1) : null;

        return ['obras' => $obras, 'totales' => $tot, 'responsables' => $responsables];
    }
}
