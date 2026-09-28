<?php

namespace App\Http\Controllers\Operativo;

use App\Exports\ManoObraResumenExport;
use App\Http\Controllers\Controller;
use App\Models\CierreConciliacion;
use App\Models\FichaProyecto;
use App\Models\ManoObraAsignacion;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Services\DistribucionManoObraService;
use App\Support\AplicaPlanoCuenta14;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * DISTRIBUCIÓN DE MANO DE OBRA (Operaciones)
 *
 * Módulo separado de "otros costos" (no laboral). Maneja la MO por persona (tercero) de una bolsa y
 * su asignación manual a cada obra destino, genera el plano 14→61 preservando tercero y obra, y lo
 * aplica en el sistema (partida doble, origen='distribucion_plano') para que el saldo baje en la
 * bolsa y quede el costo en cada obra sin recargar BIABLE.
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

    public function __construct(private DistribucionManoObraService $svc) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');

        $bolsas = UnBolsa::where('activo', true)->orderBy('codigo')->get();
        [$bolsa, $mes, $anio] = $this->contexto($request, $bolsas);

        $saldos   = $bolsa ? $this->svc->saldosPorTercero($bolsa, $mes, $anio) : [];
        $asignado = $bolsa ? $this->svc->asignadoPorTercero($bolsa, $mes, $anio) : [];

        // Asignaciones guardadas del período (para prellenar la pantalla): tercero → filas.
        $guardadas = $bolsa
            ? ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)
                ->orderBy('tercero')->get()->groupBy('tercero')
            : collect();

        // Obras destino: proyectos activos (no bolsas).
        $codigosBolsa = UnBolsa::codigos();
        $obras = FichaProyecto::where('activa', true)
            ->whereNotIn('codigo_proyecto', $codigosBolsa)
            ->orderBy('codigo_proyecto')
            ->get(['codigo_proyecto', 'nombre_obra']);

        $periodos = RegistroFinanciero::selectRaw('anio, mes')->distinct()
            ->orderByDesc('anio')->orderByDesc('mes')->get();

        // ¿Hay asignación del mes anterior para ofrecer "Precargar"?
        [$mesAnt, $anioAnt] = $this->periodoAnterior($mes, $anio);
        $hayMesAnterior = $bolsa && ManoObraAsignacion::where('bolsa_un', $bolsa)
            ->where('mes', $mesAnt)->where('anio', $anioAnt)->exists();

        $cerrado = CierreConciliacion::estaCerrado($mes, $anio);

        return view('operativo.distribucion-mano-obra', [
            'bolsas' => $bolsas, 'bolsa' => $bolsa, 'mes' => $mes, 'anio' => $anio,
            'saldos' => $saldos, 'asignado' => $asignado, 'guardadas' => $guardadas,
            'obras' => $obras, 'periodos' => $periodos, 'meses' => self::MESES,
            'hayMesAnterior' => $hayMesAnterior, 'mesAnt' => $mesAnt, 'anioAnt' => $anioAnt,
            'cerrado' => $cerrado,
        ]);
    }

    /** Guarda las asignaciones (reemplaza las del período). Valida que por tercero no exceda el saldo. */
    public function guardar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'bolsa' => 'required|string',
            'mes'   => 'required|integer|between:1,12',
            'anio'  => 'required|integer|min:2000',
            'asignaciones'               => 'array',
            'asignaciones.*.tercero'     => 'required|string',
            'asignaciones.*.obra'        => 'required|string',
            'asignaciones.*.monto'       => 'required|numeric|min:0',
            'asignaciones.*.observacion' => 'nullable|string|max:255',
        ]);

        $bolsa = $datos['bolsa']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];

        $saldos = collect($this->svc->saldosPorTercero($bolsa, $mes, $anio))->keyBy('tercero');

        // Agrupa lo enviado por tercero y valida contra el saldo.
        $porTercero = [];
        foreach ($datos['asignaciones'] ?? [] as $a) {
            $monto = round((float) $a['monto'], 2);
            if ($monto <= 0.005) continue;
            $porTercero[$a['tercero']][] = ['obra' => trim($a['obra']), 'monto' => $monto, 'obs' => $a['observacion'] ?? null];
        }
        foreach ($porTercero as $ter => $filas) {
            $saldo = (float) ($saldos[$ter]['saldo'] ?? 0);
            $suma  = round(array_sum(array_column($filas, 'monto')), 2);
            if ($suma - $saldo > 0.5) {
                return back()->with('error',
                    "El tercero {$ter} tiene asignado ".number_format($suma, 0, ',', '.').
                    " que supera su saldo de ".number_format($saldo, 0, ',', '.').". Ajusta antes de guardar.");
            }
        }

        DB::transaction(function () use ($bolsa, $mes, $anio, $porTercero, $saldos, $request) {
            ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)->delete();
            $this->insertarAsignaciones($bolsa, $mes, $anio, $porTercero, $saldos, 'manual', $request->user()?->id);
        });

        return back()->with('success', 'Asignaciones de mano de obra guardadas.');
    }

    /** Precarga el mapa persona → obra(s) del mes anterior, repartiendo el saldo ACTUAL en las mismas proporciones. */
    public function precargar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'bolsa' => 'required|string',
            'mes'   => 'required|integer|between:1,12',
            'anio'  => 'required|integer|min:2000',
        ]);
        $bolsa = $datos['bolsa']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];
        [$mesAnt, $anioAnt] = $this->periodoAnterior($mes, $anio);

        // Mapa del mes anterior: tercero → [obra => monto] y total por tercero.
        $prev = ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mesAnt)->where('anio', $anioAnt)->get();
        if ($prev->isEmpty()) {
            return back()->with('error', 'No hay distribución del mes anterior para esta bolsa.');
        }
        $mapaPrev = [];
        foreach ($prev as $a) {
            $mapaPrev[$a->tercero][$a->obra_destino] = ($mapaPrev[$a->tercero][$a->obra_destino] ?? 0) + (float) $a->monto;
        }

        $saldos = collect($this->svc->saldosPorTercero($bolsa, $mes, $anio))->keyBy('tercero');

        // Reparte el saldo actual de cada persona en las proporciones del mes anterior.
        $porTercero = [];
        foreach ($mapaPrev as $ter => $obras) {
            $saldoAct = (float) ($saldos[$ter]['saldo'] ?? 0);
            if ($saldoAct <= 0.005) continue;
            $totalPrev = array_sum($obras);
            if ($totalPrev <= 0.005) continue;
            $acumulado = 0.0; $ultima = array_key_last($obras);
            foreach ($obras as $obra => $montoPrev) {
                $monto = $obra === $ultima
                    ? round($saldoAct - $acumulado, 2)                       // la última absorbe el redondeo
                    : round($saldoAct * ($montoPrev / $totalPrev), 2);
                $acumulado += $monto;
                if ($monto > 0.005) $porTercero[$ter][] = ['obra' => (string) $obra, 'monto' => $monto, 'obs' => 'Precargado del mes anterior'];
            }
        }

        DB::transaction(function () use ($bolsa, $mes, $anio, $porTercero, $saldos, $request) {
            ManoObraAsignacion::where('bolsa_un', $bolsa)->where('mes', $mes)->where('anio', $anio)->delete();
            $this->insertarAsignaciones($bolsa, $mes, $anio, $porTercero, $saldos, 'precargado', $request->user()?->id);
        });

        return back()->with('success', 'Distribución precargada del mes anterior. Revisa y ajusta los montos antes de generar el plano.');
    }

    public function resumen(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        $bolsas = UnBolsa::where('activo', true)->orderBy('codigo')->get();
        [$bolsa, $mes, $anio] = $this->contexto($request, $bolsas);

        $resumen = $bolsa ? $this->svc->resumenPorObra($bolsa, $mes, $anio) : [];
        $total   = array_sum(array_column($resumen, 'total'));
        $nombresObra = FichaProyecto::pluck('nombre_obra', 'codigo_proyecto');

        return view('operativo.distribucion-mano-obra-resumen', [
            'bolsas' => $bolsas, 'bolsa' => $bolsa, 'mes' => $mes, 'anio' => $anio,
            'resumen' => $resumen, 'total' => $total, 'nombresObra' => $nombresObra,
            'periodos' => RegistroFinanciero::selectRaw('anio, mes')->distinct()->orderByDesc('anio')->orderByDesc('mes')->get(),
            'meses' => self::MESES,
        ]);
    }

    public function resumenExcel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        $bolsas = UnBolsa::where('activo', true)->orderBy('codigo')->get();
        [$bolsa, $mes, $anio] = $this->contexto($request, $bolsas);

        $nombresObra = FichaProyecto::pluck('nombre_obra', 'codigo_proyecto');
        $rows = [['Obra', 'Nombre', 'Tercero', 'Cuenta 14', 'Monto']];
        $total = 0.0;
        foreach ($this->svc->resumenPorObra($bolsa ?? '', $mes, $anio) as $o) {
            foreach ($o['detalle'] as $d) {
                $rows[] = [$o['obra'], (string) ($nombresObra[$o['obra']] ?? ''), $d['tercero'], $d['cuenta'], round($d['monto'], 2)];
                $total += $d['monto'];
            }
            $rows[] = [$o['obra'].' — TOTAL', '', '', '', round($o['total'], 2)];
        }
        $rows[] = ['TOTAL GENERAL', '', '', '', round($total, 2)];

        return Excel::download(new ManoObraResumenExport($rows), 'Costo_MO_por_obra_'.sprintf('%d_%02d', $anio, $mes).'.xlsx');
    }

    /** Descarga el plano SIESA 14→61 de lo asignado (no aplica nada). */
    public function plano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('operacion'), 403, 'No tienes permiso para ver Operaciones.');
        $bolsas = UnBolsa::where('activo', true)->orderBy('codigo')->get();
        [$bolsa, $mes, $anio] = $this->contexto($request, $bolsas);
        $numeroDoc = max(1, (int) $request->get('documento', 1));

        $lineas = $bolsa ? $this->svc->lineasPlano($bolsa, $mes, $anio) : [];
        if (empty($lineas)) {
            return back()->with('error', 'No hay asignaciones de mano de obra para generar el plano en este período.');
        }

        $mov = array_map(fn ($l) => $this->filaPlano($numeroDoc, $l['cuenta'], $l['tercero'], $l['unidad'], $l['centro'], $l['debito'], $l['credito'], self::TIPO_DOC), $lineas);

        $fecha = $this->ultimoDiaDelMesSiesa($anio, $mes);
        $obs   = 'DISTRIBUCION MANO DE OBRA '.$bolsa.' '.sprintf('%02d/%d', $mes, $anio);
        $archivo = $this->generarPlanoSiesa($mov, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $obs);

        return response()->download($archivo, 'PLANO_MO_'.$bolsa.'_'.sprintf('%d_%02d', $anio, $mes).'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /** Aplica en el sistema la partida doble 14→61 (idempotente por bolsa+período). */
    public function aplicar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403, 'No tienes permiso para editar en Operaciones.');
        $datos = $request->validate([
            'bolsa' => 'required|string',
            'mes'   => 'required|integer|between:1,12',
            'anio'  => 'required|integer|min:2000',
            'documento' => 'nullable|integer|min:1',
        ]);
        $bolsa = $datos['bolsa']; $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];

        if (CierreConciliacion::estaCerrado($mes, $anio)) {
            return back()->with('error', "El período {$mes}/{$anio} está cerrado. Reábrelo para aplicar planos ahí.");
        }

        $lineas = $this->svc->lineasPlano($bolsa, $mes, $anio);
        if (empty($lineas)) {
            return back()->with('error', 'No hay asignaciones de mano de obra para aplicar en este período.');
        }
        if (! $this->planoCuadra($lineas)) {
            return back()->with('error', 'El plano de mano de obra NO cuadra (débitos ≠ créditos). No se aplicó.');
        }

        $contables = $this->lineasContablesDePlano($lineas);

        $plano = $this->aplicarPlanoEnSistema([
            'tipo'             => 'mo_distribucion',
            'distribucion_id'  => null,
            'bolsa_un'         => $bolsa,
            'corte_mes'        => null,
            'corte_anio'       => null,
            'mes'              => $mes,
            'anio'             => $anio,
            'numero_documento' => ! empty($datos['documento']) ? (int) $datos['documento'] : null,
            'referencia'       => 'Distribución MO '.$bolsa.' — '.(self::MESES[$mes] ?? '').' '.$anio,
            'origen'           => 'distribucion_plano',
            'user_id'          => $request->user()->id,
        ], $contables);

        return back()->with('success',
            "Mano de obra aplicada en el sistema: {$plano->n_lineas} movimientos (partida doble 14/6) en {$plano->mes}/{$plano->anio}. "
            . "El saldo bajó en la bolsa y el costo quedó en cada obra.");
    }

    // ═══════════════════════ Helpers ═══════════════════════

    /**
     * Inserta las asignaciones de un tercero repartiendo cada monto por obra entre las cuentas 14
     * del tercero (buckets del saldo), proporcionalmente, para conservar el detalle por cuenta.
     */
    private function insertarAsignaciones(string $bolsa, int $mes, int $anio, array $porTercero, $saldos, string $origen, ?int $userId): void
    {
        $filas = [];
        $ahora = now();
        foreach ($porTercero as $ter => $asigs) {
            $info    = $saldos[$ter] ?? null;
            $buckets = $info['buckets'] ?? [];
            $doc     = $info['doc'] ?? '';
            $nombre  = $info['nombre'] ?? '';
            $totalBucket = array_sum($buckets);
            // Si no hay detalle de cuentas (raro), usa una sola "14" para no perder el monto.
            if ($totalBucket <= 0.005) $buckets = ['14' => 1.0];
            $totalBucket = array_sum($buckets);
            $cuentas = array_keys($buckets);

            foreach ($asigs as $a) {
                $monto = round((float) $a['monto'], 2);
                if ($monto <= 0.005) continue;

                $acum = 0.0; $ultima = end($cuentas);
                foreach ($cuentas as $c14) {
                    $parte = $c14 === $ultima
                        ? round($monto - $acum, 2)                          // la última cuenta absorbe el redondeo
                        : round($monto * ($buckets[$c14] / $totalBucket), 2);
                    $acum += $parte;
                    if ($parte <= 0.005) continue;
                    $filas[] = [
                        'bolsa_un' => $bolsa, 'cuenta_14' => (string) $c14, 'tercero' => (string) $ter,
                        'tercero_doc' => $doc ?: null, 'tercero_nombre' => $nombre ?: null,
                        'obra_destino' => (string) $a['obra'], 'monto' => $parte, 'mes' => $mes, 'anio' => $anio,
                        'observacion' => $a['obs'] ?? null, 'origen' => $origen, 'user_id' => $userId,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ];
                }
            }
        }
        if (! empty($filas)) {
            ManoObraAsignacion::insert($filas);
        }
    }

    /** [bolsa, mes, anio] del request; por defecto la primera bolsa y el último período con datos. */
    private function contexto(Request $request, $bolsas): array
    {
        $ult = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first();
        $mes  = (int) $request->get('mes', $ult->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $ult->anio ?? (int) date('Y'));
        $bolsa = $request->get('bolsa') ?: (string) ($bolsas->first()->codigo ?? '');

        return [$bolsa ?: null, $mes, $anio];
    }

    private function periodoAnterior(int $mes, int $anio): array
    {
        return $mes <= 1 ? [12, $anio - 1] : [$mes - 1, $anio];
    }
}
