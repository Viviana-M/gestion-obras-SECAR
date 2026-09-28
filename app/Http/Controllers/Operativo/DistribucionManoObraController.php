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
 * La MO se abre por tercero real dentro del grid de cada bolsa (departamento). Estas acciones
 * (guardar, precargar, plano, aplicar, resumen) operan a nivel DEPARTAMENTO, recorriendo sus UN.
 * Guardan en mano_obra_asignacion (bolsa_un, cuenta_14, tercero, obra_destino, monto, …) y aplican
 * la partida doble 14→61 (origen='distribucion_plano', idempotente por bolsa+período). No cambian la
 * clasificación ni el estado_er ni la lógica del plano que ya funciona.
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

    /** Guarda las asignaciones del departamento (reemplaza las del período). Valida ≤ saldo por (UN, cuenta, tercero). */
    public function guardar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'departamento' => 'required|string',
            'mes'  => 'required|integer|between:1,12',
            'anio' => 'required|integer|min:2000',
            'asignaciones'                 => 'array',
            'asignaciones.*.un'            => 'required|string',
            'asignaciones.*.cuenta_14'     => 'required|string',
            'asignaciones.*.tercero'       => 'required|string',
            'asignaciones.*.tercero_doc'   => 'nullable|string',
            'asignaciones.*.tercero_nombre'=> 'nullable|string',
            'asignaciones.*.obra'          => 'required|string',
            'asignaciones.*.monto'         => 'required|numeric|min:0',
            'asignaciones.*.observacion'   => 'nullable|string|max:255',
        ]);
        $depto = $datos['departamento']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];
        $codigos = $this->unsDeDepto($depto);

        // Saldo real por (UN|cuenta|tercero) — misma base que el grid (resta MO de apoyo).
        $saldoMap = [];
        foreach ($this->dist->manoObraPorTercero($codigos, $anio, $mes) as $unCta => $ters) {
            foreach ($ters as $t) $saldoMap[$unCta.'|'.$t['tercero']] = (float) $t['saldo'];
        }

        // Agrupa lo enviado por (UN|cuenta|tercero) y valida contra el saldo.
        $porClave = [];
        foreach ($datos['asignaciones'] ?? [] as $a) {
            $monto = round((float) $a['monto'], 2);
            if ($monto <= 0.005) continue;
            $clave = $a['un'].'|'.$a['cuenta_14'].'|'.$a['tercero'];
            $porClave[$clave][] = $a + ['monto' => $monto];
        }
        foreach ($porClave as $clave => $filas) {
            $saldo = (float) ($saldoMap[$clave] ?? 0);
            $suma  = round(array_sum(array_column($filas, 'monto')), 2);
            if ($suma - $saldo > 0.5) {
                [$un, $cta, $ter] = explode('|', $clave);
                return back()->with('error',
                    "El tercero {$ter} (UN {$un}, cuenta {$cta}) tiene asignado ".number_format($suma, 0, ',', '.').
                    " que supera su saldo de ".number_format($saldo, 0, ',', '.').". Ajusta antes de guardar.");
            }
        }

        DB::transaction(function () use ($codigos, $mes, $anio, $porClave, $request) {
            ManoObraAsignacion::whereIn('bolsa_un', $codigos)->where('mes', $mes)->where('anio', $anio)->delete();
            $filas = [];
            $ahora = now();
            foreach ($porClave as $clave => $rows) {
                foreach ($rows as $a) {
                    $filas[] = [
                        'bolsa_un' => (string) $a['un'], 'cuenta_14' => (string) $a['cuenta_14'],
                        'persona' => (string) $a['tercero'], 'tercero' => (string) $a['tercero'],
                        'tercero_doc' => $a['tercero_doc'] ?? (string) $a['tercero'],
                        'tercero_nombre' => $a['tercero_nombre'] ?? null,
                        'obra_destino' => (string) $a['obra'], 'monto' => (float) $a['monto'],
                        'mes' => $mes, 'anio' => $anio, 'observacion' => $a['observacion'] ?? null,
                        'origen' => 'manual', 'user_id' => $request->user()?->id,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
            if (! empty($filas)) ManoObraAsignacion::insert($filas);
        });

        return back()->with('success', 'Asignaciones de mano de obra guardadas.');
    }

    /** Precarga el mapa (UN, cuenta, tercero) → obra(s) del mes anterior, repartiendo el saldo ACTUAL en las mismas proporciones. */
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
        // Mapa previo por (UN|cuenta|tercero) → [obra => monto].
        $mapaPrev = [];
        foreach ($prev as $a) {
            $clave = $a->bolsa_un.'|'.$a->cuenta_14.'|'.$a->tercero;
            $mapaPrev[$clave][$a->obra_destino] = ($mapaPrev[$clave][$a->obra_destino] ?? 0) + (float) $a->monto;
        }
        // Saldo actual por (UN|cuenta|tercero).
        $saldoMap = []; $infoMap = [];
        foreach ($this->dist->manoObraPorTercero($codigos, $anio, $mes) as $unCta => $ters) {
            [$un, $cta] = explode('|', $unCta);
            foreach ($ters as $t) {
                $clave = $unCta.'|'.$t['tercero'];
                $saldoMap[$clave] = (float) $t['saldo'];
                $infoMap[$clave] = ['un' => $un, 'cuenta_14' => $cta, 'tercero' => $t['tercero'], 'doc' => $t['doc'], 'nombre' => $t['nombre']];
            }
        }

        DB::transaction(function () use ($codigos, $mes, $anio, $mapaPrev, $saldoMap, $infoMap, $request) {
            ManoObraAsignacion::whereIn('bolsa_un', $codigos)->where('mes', $mes)->where('anio', $anio)->delete();
            $filas = []; $ahora = now();
            foreach ($mapaPrev as $clave => $obras) {
                $saldo = (float) ($saldoMap[$clave] ?? 0);
                $info  = $infoMap[$clave] ?? null;
                if ($saldo <= 0.005 || ! $info) continue;
                $totalPrev = array_sum($obras);
                if ($totalPrev <= 0.005) continue;
                $acum = 0.0; $ultima = array_key_last($obras);
                foreach ($obras as $obra => $montoPrev) {
                    $monto = $obra === $ultima ? round($saldo - $acum, 2) : round($saldo * ($montoPrev / $totalPrev), 2);
                    $acum += $monto;
                    if ($monto <= 0.005) continue;
                    $filas[] = [
                        'bolsa_un' => $info['un'], 'cuenta_14' => $info['cuenta_14'],
                        'persona' => $info['tercero'], 'tercero' => $info['tercero'],
                        'tercero_doc' => $info['doc'] ?: $info['tercero'], 'tercero_nombre' => $info['nombre'] ?: null,
                        'obra_destino' => (string) $obra, 'monto' => $monto, 'mes' => $mes, 'anio' => $anio,
                        'observacion' => 'Precargado del mes anterior', 'origen' => 'precargado', 'user_id' => $request->user()?->id,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
            if (! empty($filas)) ManoObraAsignacion::insert($filas);
        });

        return back()->with('success', 'Distribución precargada del mes anterior. Revisa y ajusta antes de aplicar.');
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

        $rows = [['Obra', 'Nombre', 'Tercero', 'Cuenta 14', 'Monto']];
        $total = 0.0;
        foreach ($this->resumenDepto($depto, $mes, $anio) as $o) {
            foreach ($o['detalle'] as $d) {
                $rows[] = [$o['obra'], (string) ($nombresObra[$o['obra']] ?? ''), $d['tercero'], $d['cuenta'], round($d['monto'], 2)];
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
