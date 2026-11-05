<?php

namespace App\Http\Controllers\Contable;

use App\Exports\ConciliacionCierreExport;
use App\Http\Controllers\Controller;
use App\Models\CierreConciliacion;
use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Support\AuxiliarErpReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * CONCILIACIÓN Y CIERRE DE CUENTA 14 (y 6)
 *
 * Cruza el saldo del sistema contra el ERP para la cuenta 14 (Costos por aplicar) Y la cuenta 6
 * (Costos aplicados), cada una por obra, con corte acumulado hasta un período. Muestra el desglose
 * por origen (BIABLE / reverso_plano / distribucion_plano), la diferencia, el estado con la causa
 * probable, y el cuadre de utilidad (ingreso − costo aplicado − costo por aplicar). Cuando cuadra,
 * contabilidad "cierra" el período: se guarda y se bloquea (no se recarga BIABLE ni se tocan planos
 * de ese mes hasta reabrirlo). Convive con la "Reconciliación 14" existente (no la reemplaza).
 */
class ConciliacionCierreController extends Controller
{
    private const CLAVE_SESION = 'concil_cierre_erp';
    /** Tolerancia (centavos) para dar por conciliado y permitir el cierre. */
    private const TOL_CIERRE = 1.0;
    /** Tolerancia para marcar una obra como "revisar" en pantalla. */
    private const TOL_OBRA = 1000.0;

    private const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodoSeleccionado($request);

        $erp   = session(self::CLAVE_SESION);
        $datos = $this->cruce($mes, $anio, $erp);

        $periodos = RegistroFinanciero::selectRaw('anio, mes')->distinct()
            ->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.conciliacion-cierre', array_merge($datos, [
            'mes'       => $mes,
            'anio'      => $anio,
            'periodos'  => $periodos,
            'meses'     => self::MESES,
            'erp'       => $erp,
            'cerrado'   => $mes && $anio ? CierreConciliacion::estaCerrado($mes, $anio) : false,
            'historial' => CierreConciliacion::with('usuario')->orderByDesc('anio')->orderByDesc('mes')->get(),
            'tolCierre' => self::TOL_CIERRE,
        ]));
    }

    public function subirErp(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate([
            'archivo_14' => 'required|file|mimes:xlsx,xls|max:102400',
            'archivo_6'  => 'nullable|file|mimes:xlsx,xls|max:102400',
        ], [
            'archivo_14.required' => 'Sube al menos el auxiliar de la cuenta 14 del ERP.',
        ]);

        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        DB::connection()->disableQueryLog();

        $lector = new AuxiliarErpReader();
        try {
            $erp14 = $lector->leer($request->file('archivo_14')->getRealPath());
            $erp6  = $request->hasFile('archivo_6')
                ? $lector->leer($request->file('archivo_6')->getRealPath())
                : ['obras' => [], 'porMes' => [], 'nombres' => [], 'detalle' => 0];
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo leer el archivo del ERP: '.$e->getMessage());
        }

        session([self::CLAVE_SESION => [
            'archivo_14' => $request->file('archivo_14')->getClientOriginalName(),
            'archivo_6'  => $request->file('archivo_6')?->getClientOriginalName(),
            'erp14'      => $erp14,
            'erp6'       => $erp6,
            'generado'   => now()->format('Y-m-d H:i'),
        ]]);

        return back()->with('success',
            "ERP procesado: {$erp14['detalle']} movimientos de cuenta 14".
            ($erp6['detalle'] ? " y {$erp6['detalle']} de cuenta 6" : '').'.');
    }

    public function limpiar(Request $request)
    {
        session()->forget(self::CLAVE_SESION);

        return back();
    }

    public function excel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        [$mes, $anio] = $this->periodoSeleccionado($request);
        $datos = $this->cruce($mes, $anio, session(self::CLAVE_SESION));

        $rows = [['Código', 'Obra',
            'Sistema 14', 'BIABLE 14', 'Reverso 14', 'Distribución 14', 'ERP 14', 'Dif 14',
            'Sistema 6', 'ERP 6', 'Dif 6',
            'Utilidad sistema', 'Utilidad ERP', 'Dif utilidad', 'Estado', 'Causa']];
        foreach ($datos['filas'] as $f) {
            $rows[] = [
                $f['codigo'], $f['nombre'],
                $f['sis14'], $f['biable14'], $f['reverso14'], $f['distribucion14'], $f['erp14'], $f['dif14'],
                $f['sis6'], $f['erp6'], $f['dif6'],
                $f['util_sis'], $f['util_erp'], $f['dif_util'], $f['ok'] ? 'OK' : 'Revisar', $f['causa'],
            ];
        }

        $sufijo = ($mes && $anio) ? sprintf('_hasta_%04d%02d', $anio, $mes) : '';

        return Excel::download(new ConciliacionCierreExport($rows), 'Conciliacion_14_6'.$sufijo.'.xlsx');
    }

    public function cerrar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $datos = $request->validate([
            'mes'  => 'required|integer|between:1,12',
            'anio' => 'required|integer|min:2000',
        ]);
        $mes = (int) $datos['mes']; $anio = (int) $datos['anio'];

        if (CierreConciliacion::estaCerrado($mes, $anio)) {
            return back()->with('error', 'Ese período ya está cerrado.');
        }

        $erp = session(self::CLAVE_SESION);
        if (! $erp) {
            return back()->with('error', 'Primero sube el auxiliar del ERP para poder conciliar y cerrar.');
        }

        $cruce = $this->cruce($mes, $anio, $erp);
        $t = $cruce['tot'];

        if (abs($t['dif14']) > self::TOL_CIERRE || abs($t['dif6']) > self::TOL_CIERRE) {
            return back()->with('error', sprintf(
                'No se puede cerrar: la conciliación no está en cero (dif. cuenta 14 %s, cuenta 6 %s). Corrige antes de cerrar.',
                number_format($t['dif14'], 2, ',', '.'), number_format($t['dif6'], 2, ',', '.')));
        }

        CierreConciliacion::create([
            'mes' => $mes, 'anio' => $anio,
            'saldo_sistema_14' => round($t['sis14'], 2), 'saldo_sistema_6' => round($t['sis6'], 2),
            'saldo_erp_14' => round($t['erp14'], 2), 'saldo_erp_6' => round($t['erp6'], 2),
            'diferencia' => round($t['dif14'] + $t['dif6'], 2),
            'user_id' => $request->user()->id, 'cerrado_at' => now(),
        ]);

        return back()->with('success',
            "Período {$mes}/{$anio} cerrado y bloqueado: no se podrá recargar BIABLE ni tocar planos de ese mes hasta reabrirlo.");
    }

    public function reabrir(Request $request, CierreConciliacion $cierre)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $mes = $cierre->mes; $anio = $cierre->anio;
        $cierre->delete();

        return back()->with('success', "Período {$mes}/{$anio} reabierto: ya se puede recargar y editar de nuevo.");
    }

    // ═══════════════════════ Cálculo del cruce ═══════════════════════

    /** [mes, anio] del filtro; por defecto el último período con datos. */
    private function periodoSeleccionado(Request $request): array
    {
        if ($request->filled('mes') && $request->filled('anio')) {
            return [(int) $request->get('mes'), (int) $request->get('anio')];
        }
        $ult = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first();

        return $ult ? [(int) $ult->mes, (int) $ult->anio] : [null, null];
    }

    private function aplicarCorte($q, ?int $mes, ?int $anio)
    {
        return $q->when($mes && $anio, function ($x) use ($mes, $anio) {
            $x->where(function ($s) use ($mes, $anio) {
                $s->where('anio', '<', $anio)->orWhere(fn ($y) => $y->where('anio', $anio)->where('mes', '<=', $mes));
            });
        });
    }

    /**
     * Saldo del sistema por obra (convención ERP = −SUM(estado_er)) de una cuenta mayor, con corte
     * acumulado. Devuelve [codObra => saldo].
     */
    private function saldoSistema(string $cuentaMayor, ?int $mes, ?int $anio): array
    {
        $out = [];
        $q = RegistroFinanciero::where('cuenta_mayor', $cuentaMayor)
            ->selectRaw('codigo_proyecto, SUM(estado_er) as er')->groupBy('codigo_proyecto');
        foreach ($this->aplicarCorte($q, $mes, $anio)->get() as $r) {
            $out[$this->norm($r->codigo_proyecto)] = -1 * (float) $r->er;
        }
        return $out;
    }

    /** Desglose del saldo de la cuenta 14 por obra y origen (convención ERP). */
    private function saldo14PorOrigen(?int $mes, ?int $anio): array
    {
        $out = [];
        $q = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, origen, SUM(estado_er) as er')->groupBy('codigo_proyecto', 'origen');
        foreach ($this->aplicarCorte($q, $mes, $anio)->get() as $r) {
            $out[$this->norm($r->codigo_proyecto)][$r->origen] = -1 * (float) $r->er;
        }
        return $out;
    }

    /** Ingreso por obra en estado_er (para el cuadre de utilidad). */
    private function ingresoEr(?int $mes, ?int $anio): array
    {
        $out = [];
        $q = RegistroFinanciero::where('cuenta_mayor', 'Ingreso')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as er')->groupBy('codigo_proyecto');
        foreach ($this->aplicarCorte($q, $mes, $anio)->get() as $r) {
            $out[$this->norm($r->codigo_proyecto)] = (float) $r->er;
        }
        return $out;
    }

    /**
     * Cruza sistema vs ERP para 14 y 6 por obra + utilidad. Devuelve ['filas'=>..., 'tot'=>...].
     * La utilidad = ingreso − costo aplicado − costo por aplicar (en estado_er nativo del sistema);
     * la "utilidad ERP" usa el mismo ingreso pero los costos del ERP.
     */
    private function cruce(?int $mes, ?int $anio, ?array $erp): array
    {
        $sis14  = $this->saldoSistema('Costos por aplicar', $mes, $anio);
        $sis6   = $this->saldoSistema('Costos aplicados', $mes, $anio);
        $desg14 = $this->saldo14PorOrigen($mes, $anio);
        $ingEr  = $this->ingresoEr($mes, $anio);

        $erp14 = $erp['erp14']['obras'] ?? [];
        $erp6  = $erp['erp6']['obras'] ?? [];
        $erpNombres = array_merge($erp['erp6']['nombres'] ?? [], $erp['erp14']['nombres'] ?? []);

        // Nombres de obra desde la ficha y desde el sistema.
        $nombres = [];
        foreach (FichaProyecto::get(['codigo_proyecto', 'nombre_obra']) as $f) {
            $nombres[$this->norm($f->codigo_proyecto)] = (string) $f->nombre_obra;
        }
        $qn = RegistroFinanciero::selectRaw('codigo_proyecto, MAX(nombre_proyecto) as n')->groupBy('codigo_proyecto');
        foreach ($this->aplicarCorte($qn, $mes, $anio)->get() as $r) {
            $k = $this->norm($r->codigo_proyecto);
            if (empty($nombres[$k])) $nombres[$k] = (string) $r->n;
        }

        $tieneErp = ! empty($erp);
        $codigos = array_unique(array_merge(
            array_keys($sis14), array_keys($sis6), array_keys($erp14), array_keys($erp6)
        ));

        $filas = [];
        $tot = ['sis14' => 0.0, 'erp14' => 0.0, 'dif14' => 0.0, 'sis6' => 0.0, 'erp6' => 0.0, 'dif6' => 0.0,
            'util_sis' => 0.0, 'util_erp' => 0.0, 'dif_util' => 0.0];

        foreach ($codigos as $k) {
            $s14 = round((float) ($sis14[$k] ?? 0), 2);
            $s6  = round((float) ($sis6[$k] ?? 0), 2);
            $e14 = round((float) ($erp14[$k] ?? 0), 2);
            $e6  = round((float) ($erp6[$k] ?? 0), 2);
            $d14 = round($e14 - $s14, 2);
            $d6  = round($e6 - $s6, 2);

            $bd = $desg14[$k] ?? [];
            $biable14      = round((float) ($bd['biable'] ?? 0), 2);
            $reverso14     = round((float) ($bd['reverso_plano'] ?? 0), 2);
            $distribucion14 = round((float) ($bd['distribucion_plano'] ?? 0), 2);

            // Utilidad (estado_er nativo): ingreso + costo aplicado_er + costo por aplicar_er.
            // saldo ERP-convención = −estado_er, así que estado_er = −saldo.
            $ing   = round((float) ($ingEr[$k] ?? 0), 2);
            $utSis = round($ing + (-$s6) + (-$s14), 2);
            $utErp = round($ing + (-$e6) + (-$e14), 2);

            $enErp = isset($erp14[$k]) || isset($erp6[$k]);
            $enSis = isset($sis14[$k]) || isset($sis6[$k]);
            $ok    = $tieneErp ? (abs($d14) <= self::TOL_OBRA && abs($d6) <= self::TOL_OBRA) : true;

            $filas[] = [
                'codigo'         => $erpNombres[$k] ?? $k,
                'nombre'         => $nombres[$k] ?? '',
                'sis14'          => $s14, 'biable14' => $biable14, 'reverso14' => $reverso14,
                'distribucion14' => $distribucion14, 'erp14' => $e14, 'dif14' => $d14,
                'sis6'           => $s6, 'erp6' => $e6, 'dif6' => $d6,
                'util_sis'       => $utSis, 'util_erp' => $utErp, 'dif_util' => round($utSis - $utErp, 2),
                'ok'             => $ok, 'en_erp' => $enErp, 'en_sistema' => $enSis,
                'causa'          => $this->causa($ok, $enErp, $enSis, $k, $reverso14, $distribucion14, $d14, $d6, $tieneErp),
            ];

            $tot['sis14'] += $s14; $tot['erp14'] += $e14; $tot['dif14'] += $d14;
            $tot['sis6']  += $s6;  $tot['erp6']  += $e6;  $tot['dif6']  += $d6;
            $tot['util_sis'] += $utSis; $tot['util_erp'] += $utErp; $tot['dif_util'] += ($utSis - $utErp);
        }

        usort($filas, fn ($a, $b) => (abs($b['dif14']) + abs($b['dif6'])) <=> (abs($a['dif14']) + abs($a['dif6'])));
        foreach ($tot as $k => $v) $tot[$k] = round($v, 2);

        return ['filas' => $filas, 'tot' => $tot, 'tieneErp' => $tieneErp];
    }

    /** Causa probable de la diferencia (heurística para orientar la revisión). */
    private function causa(bool $ok, bool $enErp, bool $enSis, string $cod, float $rev, float $dist, float $d14, float $d6, bool $tieneErp): string
    {
        if (! $tieneErp) return '';
        if ($ok) return 'OK';
        if (! $enErp) return 'Falta en ERP';
        if (! $enSis) return 'Falta en el sistema (mes sin cargar)';
        if (str_starts_with($cod, 'COM')) return 'Obra COM (revisar inclusión)';
        if (abs($rev) > 0.5 || abs($dist) > 0.5) return 'Revisar plano aplicado / sobre-reversión';
        if (abs($d14) < self::TOL_OBRA && abs($d6) < self::TOL_OBRA) return 'Centavos';
        return 'Revisar (mes faltante o cargue incompleto)';
    }

    private function norm($s): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $s)));
    }
}
