<?php

namespace App\Http\Controllers\Operativo;

use App\Exports\ManoObraResumenExport;
use App\Http\Controllers\Controller;
use App\Models\CierreConciliacion;
use App\Models\FichaProyecto;
use App\Models\ManoObraAsignacion;
use App\Models\UnBolsa;
use App\Services\DistribucionManoObraService;
use App\Services\DistribucionService;
use App\Support\AplicaPlanoCuenta14;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * DISTRIBUCIÓN DE MANO DE OBRA — acciones del grid de bolsas de la pantalla de Distribución.
 *
 * La MO directa se abre por PERSONA (maestro Mano de obra directa) dentro del grid de cada bolsa
 * (departamento), con su costo completo del período (salario + seguridad social, mismo cruce por
 * cédula del plano de MO de apoyo). Estas acciones (guardar, precargar, plano, aplicar, resumen)
 * operan a nivel DEPARTAMENTO, recorriendo sus UN. Guardan en mano_obra_asignacion (persona/cédula,
 * cuenta_14, tercero del ERP, obra_destino, monto, …) y aplican la partida doble 14→61
 * (origen='distribucion_plano', idempotente por bolsa+período). No cambian la clasificación ni el
 * estado_er ni la lógica del plano que ya funciona.
 */
class DistribucionManoObraController extends Controller
{
    use GeneraPlanoSiesa;
    use AplicaPlanoCuenta14;

    private const TIPO_DOC  = 'CCC';
    private const NIT_SECAR = '890319324';

    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(private DistribucionManoObraService $svc, private DistribucionService $dist) {}

    /** UN (códigos de bolsa) activas de un departamento. */
    private function unsDeDepto(?string $departamento): array
    {
        return UnBolsa::where('activo', true)
            ->when($departamento, fn ($q) => $q->where('departamento', $departamento))
            ->pluck('codigo')->all();
    }

    /** Guarda las asignaciones del departamento (reemplaza las del período). Valida ≤ costo completo por PERSONA. */
    public function guardar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'departamento' => 'required|string',
            'mes'  => 'required|integer|between:1,12',
            'anio' => 'required|integer|min:2000',
            'asignaciones'                 => 'array',
            'asignaciones.*.cedula'        => 'required|string',
            'asignaciones.*.nombre'        => 'nullable|string',
            'asignaciones.*.obra'          => 'required|string',
            'asignaciones.*.monto'         => 'required|numeric|min:0',
            'asignaciones.*.observacion'   => 'nullable|string|max:255',
        ]);
        $depto = $datos['departamento']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];
        $codigos = $this->unsDeDepto($depto);

        // Costo completo por persona (solo el maestro Mano de obra directa), con sus buckets.
        $personas = $this->dist->manoObraDirectaPorPersona($codigos, $anio, $mes);

        // Agrupa lo enviado por cédula → [obra => {monto, obs}] y valida el total contra el costo de la persona.
        $mapa = [];
        foreach ($datos['asignaciones'] ?? [] as $a) {
            $monto = round((float) $a['monto'], 2);
            if ($monto <= 0.005) continue;
            $ced = (string) $a['cedula']; $obra = (string) $a['obra'];
            if (! isset($mapa[$ced][$obra])) $mapa[$ced][$obra] = ['monto' => 0.0, 'obs' => $a['observacion'] ?? null];
            $mapa[$ced][$obra]['monto'] += $monto;
        }
        foreach ($mapa as $ced => $obras) {
            $costo = (float) ($personas[$ced]['total'] ?? 0);
            $suma  = round(array_sum(array_column($obras, 'monto')), 2);
            if ($suma - $costo > 0.5) {
                $nom = $personas[$ced]['nombre'] ?? $ced;
                return back()->with('error',
                    "A {$nom} le asignaste ".number_format($suma, 0, ',', '.').
                    " que supera su costo de mano de obra de ".number_format($costo, 0, ',', '.').". Ajusta antes de guardar.");
            }
        }

        DB::transaction(function () use ($codigos, $mes, $anio, $personas, $mapa, $request) {
            ManoObraAsignacion::whereIn('bolsa_un', $codigos)->where('mes', $mes)->where('anio', $anio)->delete();
            $filas = $this->construirFilas($personas, $mapa, $mes, $anio, 'manual', $request->user()?->id);
            if (! empty($filas)) ManoObraAsignacion::insert($filas);
        });

        return back()->with('success', 'Asignaciones de mano de obra guardadas.');
    }

    /** Precarga el mapa persona → obra(s) del mes anterior, repartiendo el COSTO ACTUAL de cada persona en las mismas proporciones. */
    public function precargar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'departamento' => 'required|string', 'mes' => 'required|integer|between:1,12', 'anio' => 'required|integer|min:2000',
        ]);
        $depto = $datos['departamento']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];
        $codigos = $this->unsDeDepto($depto);
        [$mesAnt, $anioAnt] = $mes <= 1 ? [12, $anio - 1] : [$mes - 1, $anio];

        $prev = ManoObraAsignacion::whereIn('bolsa_un', $codigos)->where('mes', $mesAnt)->where('anio', $anioAnt)->get();
        if ($prev->isEmpty()) {
            return back()->with('error', 'No hay distribución del mes anterior para esta área.');
        }
        // Mapa previo por PERSONA → [obra => monto] (proporciones del mes anterior).
        $prevPorObra = [];
        foreach ($prev as $a) {
            $ced = (string) ($a->persona ?: $a->tercero);
            $prevPorObra[$ced][$a->obra_destino] = ($prevPorObra[$ced][$a->obra_destino] ?? 0) + (float) $a->monto;
        }

        // Costo actual por persona → reparte el total en las proporciones del mes anterior.
        $personas = $this->dist->manoObraDirectaPorPersona($codigos, $anio, $mes);
        $mapa = [];
        foreach ($personas as $ced => $p) {
            $obras = $prevPorObra[$ced] ?? null;
            if (! $obras) continue;
            $totalPrev = array_sum($obras);
            if ($totalPrev <= 0.005) continue;
            $costo = (float) $p['total'];
            $acum = 0.0; $ultima = array_key_last($obras);
            foreach ($obras as $obra => $montoPrev) {
                $monto = $obra === $ultima ? round($costo - $acum, 2) : round($costo * ($montoPrev / $totalPrev), 2);
                $acum += $monto;
                if ($monto <= 0.005) continue;
                $mapa[$ced][(string) $obra] = ['monto' => $monto, 'obs' => 'Precargado del mes anterior'];
            }
        }

        DB::transaction(function () use ($codigos, $mes, $anio, $personas, $mapa, $request) {
            ManoObraAsignacion::whereIn('bolsa_un', $codigos)->where('mes', $mes)->where('anio', $anio)->delete();
            $filas = $this->construirFilas($personas, $mapa, $mes, $anio, 'precargado', $request->user()?->id);
            if (! empty($filas)) ManoObraAsignacion::insert($filas);
        });

        return back()->with('success', 'Distribución precargada del mes anterior. Revisa y ajusta antes de aplicar.');
    }

    /**
     * Explota cada asignación (persona → obra → monto) en filas de mano_obra_asignacion, repartiendo
     * el monto proporcionalmente entre los buckets del costo de la persona (salario y seguridad
     * social), para preservar la cuenta 14 y el tercero del ERP de cada componente en el plano.
     *
     * @param  array  $personas  [cédula => {cedula,doc,nombre,total,buckets}]
     * @param  array  $mapa      [cédula => [obra => {monto, obs}]]
     * @return array<int, array<string,mixed>>
     */
    private function construirFilas(array $personas, array $mapa, int $mes, int $anio, string $origen, ?int $userId): array
    {
        $ahora = now();
        $filas = [];
        foreach ($mapa as $ced => $obras) {
            $p = $personas[(string) $ced] ?? null;
            if (! $p || $p['total'] <= 0.005 || empty($p['buckets'])) continue;
            $total = (float) $p['total'];

            foreach ($obras as $obra => $info) {
                $montoObra = round((float) $info['monto'], 2);
                if ($montoObra <= 0.005) continue;
                $obs = $info['obs'] ?? null;

                $acum = 0.0; $ult = count($p['buckets']) - 1;
                foreach ($p['buckets'] as $idx => $b) {
                    $monto = $idx === $ult ? round($montoObra - $acum, 2) : round($montoObra * ((float) $b['monto'] / $total), 2);
                    $acum += $monto;
                    if ($monto <= 0.005) continue;
                    $filas[] = [
                        'bolsa_un' => (string) $b['un'], 'cuenta_14' => (string) $b['cuenta'],
                        'persona' => (string) $p['cedula'], 'tercero' => (string) $b['tercero'],
                        'tercero_doc' => (string) $p['doc'], 'tercero_nombre' => (string) $p['nombre'],
                        'obra_destino' => (string) $obra, 'monto' => $monto,
                        'mes' => $mes, 'anio' => $anio, 'observacion' => $obs,
                        'origen' => $origen, 'user_id' => $userId,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
        }
        return $filas;
    }

    public function resumen(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        [$depto, $mes, $anio] = [$request->get('departamento'), (int) $request->get('mes'), (int) $request->get('anio')];

        $resumen = $this->resumenDepto($depto, $mes, $anio);
        $total   = array_sum(array_column($resumen, 'total'));

        return view('operativo.distribucion-mano-obra-resumen', [
            'departamento' => $depto, 'mes' => $mes, 'anio' => $anio,
            'resumen' => $resumen, 'total' => $total,
            'nombresObra' => FichaProyecto::pluck('nombre_obra', 'codigo_proyecto'),
            'meses' => self::MESES,
        ]);
    }

    public function resumenExcel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        [$depto, $mes, $anio] = [$request->get('departamento'), (int) $request->get('mes'), (int) $request->get('anio')];
        $nombresObra = FichaProyecto::pluck('nombre_obra', 'codigo_proyecto');

        $rows = [['Obra', 'Nombre obra', 'Persona', 'Cédula', 'Monto']];
        $total = 0.0;
        foreach ($this->resumenDepto($depto, $mes, $anio) as $o) {
            foreach ($o['detalle'] as $d) {
                $rows[] = [$o['obra'], (string) ($nombresObra[$o['obra']] ?? ''), (string) ($d['nombre'] ?: $d['tercero']), $d['tercero'], round($d['monto'], 2)];
                $total += $d['monto'];
            }
            $rows[] = [$o['obra'].' — TOTAL', '', '', '', round($o['total'], 2)];
        }
        $rows[] = ['TOTAL GENERAL', '', '', '', round($total, 2)];

        return Excel::download(new ManoObraResumenExport($rows), 'Costo_MO_por_obra_'.($depto ?: 'area').'_'.sprintf('%d_%02d', $anio, $mes).'.xlsx');
    }

    /** Descarga el plano SIESA 14→61 de lo asignado en el departamento (todas sus UN). */
    public function plano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        [$depto, $mes, $anio] = [$request->get('departamento'), (int) $request->get('mes'), (int) $request->get('anio')];
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $mov = [];
        foreach ($this->unsDeDepto($depto) as $un) {
            foreach ($this->svc->lineasPlano($un, $mes, $anio) as $l) {
                $mov[] = $this->filaPlano($numeroDoc, $l['cuenta'], $l['tercero'], $l['unidad'], $l['centro'], $l['debito'], $l['credito'], self::TIPO_DOC);
            }
        }
        if (empty($mov)) {
            return back()->with('error', 'No hay asignaciones de mano de obra para generar el plano en este período.');
        }

        $fecha = $this->ultimoDiaDelMesSiesa($anio, $mes);
        $obs   = 'DISTRIBUCION MANO DE OBRA '.mb_strtoupper((string) $depto).' '.sprintf('%02d/%d', $mes, $anio);
        $archivo = $this->generarPlanoSiesa($mov, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_MO_'.mb_strtoupper((string) $depto).'_'.sprintf('%d_%02d', $anio, $mes).'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /** Aplica en el sistema la partida doble 14→61 de todas las UN del departamento (idempotente por UN+período). */
    public function aplicar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'departamento' => 'required|string', 'mes' => 'required|integer|between:1,12', 'anio' => 'required|integer|min:2000',
            'documento' => 'nullable|integer|min:1',
        ]);
        $depto = $datos['departamento']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];

        if (CierreConciliacion::estaCerrado($mes, $anio)) {
            return back()->with('error', "El período {$mes}/{$anio} está cerrado. Reábrelo para aplicar planos ahí.");
        }

        $totalLineas = 0;
        foreach ($this->unsDeDepto($depto) as $un) {
            $lineas = $this->svc->lineasPlano($un, $mes, $anio);
            if (empty($lineas)) continue;
            if (! $this->planoCuadra($lineas)) {
                return back()->with('error', "El plano de la UN {$un} NO cuadra (débitos ≠ créditos). No se aplicó nada.");
            }
            $plano = $this->aplicarPlanoEnSistema([
                'tipo' => 'mo_distribucion', 'distribucion_id' => null, 'bolsa_un' => $un,
                'corte_mes' => null, 'corte_anio' => null, 'mes' => $mes, 'anio' => $anio,
                'numero_documento' => ! empty($datos['documento']) ? (int) $datos['documento'] : null,
                'referencia' => 'Distribución MO '.$un.' — '.(self::MESES[$mes] ?? '').' '.$anio,
                'origen' => 'distribucion_plano', 'user_id' => $request->user()->id,
            ], $this->lineasContablesDePlano($lineas));
            $totalLineas += $plano->n_lineas;
        }

        if ($totalLineas === 0) {
            return back()->with('error', 'No hay asignaciones de mano de obra para aplicar en este período.');
        }

        return back()->with('success',
            "Mano de obra aplicada en el sistema: {$totalLineas} movimientos (partida doble 14/6) en {$mes}/{$anio}. "
            . "El saldo bajó en la bolsa y el costo quedó en cada obra.");
    }

    /** Resumen por obra del departamento (agrega todas sus UN). */
    private function resumenDepto(?string $depto, int $mes, int $anio): array
    {
        $porObra = [];
        foreach ($this->unsDeDepto($depto) as $un) {
            foreach ($this->svc->resumenPorObra($un, $mes, $anio) as $o) {
                if (! isset($porObra[$o['obra']])) {
                    $porObra[$o['obra']] = ['obra' => $o['obra'], 'total' => 0.0, 'detalle' => []];
                }
                $porObra[$o['obra']]['total'] += $o['total'];
                foreach ($o['detalle'] as $d) $porObra[$o['obra']]['detalle'][] = $d;
            }
        }
        foreach ($porObra as &$o) $o['total'] = round($o['total'], 2);
        unset($o);
        uasort($porObra, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values($porObra);
    }
}
