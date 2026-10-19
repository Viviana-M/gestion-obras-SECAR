<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Concerns\ProcesaCargaPorLotes;
use App\Http\Controllers\Controller;
use App\Models\AutoliquidacionAporte;
use App\Models\CargaPorLote;
use App\Support\Lotes\ImportadorAutoliquidacion;
use App\Support\Lotes\MotorLotes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class AutoliquidacionController extends Controller
{
    use ProcesaCargaPorLotes;

    public function index(Request $request)
    {
        // Períodos disponibles (para el selector).
        $periodos = AutoliquidacionAporte::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        $mes  = (int) $request->get('mes', $periodos->first()->mes ?? (int) date('n'));
        $anio = (int) $request->get('anio', $periodos->first()->anio ?? (int) date('Y'));
        $un   = trim((string) $request->get('un', '')) ?: null;      // filtro (pestaña por persona)
        // Por defecto abre la vista por persona (maestro-detalle); "resumen" solo si se pide.
        $tab  = $request->get('tab') === 'resumen' ? 'resumen' : 'personas';

        $base = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio);

        // "Personas" = empleados distintos (no terceros/fondos). Si la planilla aún no tiene
        // la columna empleado (migración pendiente), cae a la cédula del tercero.
        $persona = Schema::hasColumn('autoliquidacion_aportes', 'empleado')
            ? "COALESCE(NULLIF(empleado, ''), NULLIF(empleado_nombre, ''), cedula)"
            : 'cedula';

        $resumen = [
            'personas'       => (int) (clone $base)->selectRaw("COUNT(DISTINCT $persona) as n")->value('n'),
            'filas'          => (clone $base)->count(),
            'aporte_empresa' => (float) (clone $base)->sum('aporte_empresa'),
        ];

        $porUN = (clone $base)
            ->selectRaw("un_codigo,
                COUNT(DISTINCT $persona) as personas,
                SUM(aporte_empresa) as aporte_empresa")
            ->groupBy('un_codigo')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar valores en 0
            ->orderByDesc('aporte_empresa')
            ->get();

        $porConcepto = (clone $base)
            ->selectRaw('concepto_pila, SUM(aporte_empresa) as aporte_empresa')
            ->groupBy('concepto_pila')
            ->havingRaw('ABS(SUM(aporte_empresa)) > 0.005') // no mostrar valores en 0
            ->orderByDesc('aporte_empresa')
            ->get();

        // Pestaña "Seguridad social por persona": lista por persona + KPIs.
        $unidades = (clone $base)->whereNotNull('un_codigo')
            ->distinct()->orderBy('un_codigo')->pluck('un_codigo');
        ['personas' => $personas, 'total' => $total] = $this->datosSeguridadPersona($mes, $anio, $un);
        $numPersonas = $personas->count();
        $promedio    = $numPersonas ? $total / $numPersonas : 0.0;

        return view('contable.autoliquidacion', compact(
            'periodos', 'mes', 'anio', 'un', 'tab', 'resumen', 'porUN', 'porConcepto',
            'unidades', 'personas', 'total', 'numPersonas', 'promedio'
        ));
    }

    /**
     * Descarga a Excel del costo de seguridad social por persona: persona, cédula, UN,
     * total Aporte empresa y una columna por cada concepto PILA.
     */
    public function personasExcel(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = (int) $request->get('mes', (int) date('n'));
        $anio = (int) $request->get('anio', (int) date('Y'));
        $un   = trim((string) $request->get('un', '')) ?: null;

        ['personas' => $personas, 'total' => $total] = $this->datosSeguridadPersona($mes, $anio, $un);

        $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        $periodo = ($nombresMes[$mes] ?? $mes).'_'.$anio.($un ? '_'.$un : '');

        return Excel::download(
            new \App\Exports\SeguridadSocialPersonaExport($personas->all(), $total),
            'Seguridad_social_por_persona_'.$periodo.'.xlsx'
        );
    }

    /**
     * Arma el costo de seguridad social POR PERSONA (empleado) para un período y UN opcional:
     * total por persona (solo Aporte empresa), UN dominante y desglose por concepto PILA.
     * Se agrupa por el EMPLEADO (columnas "Empleado"/"Nombre del empl"), NO por el tercero
     * (fondo/EPS). Para planillas antiguas sin empleado, cae a la cédula/razón social del
     * tercero. Ordenado de mayor a menor por total y sin valores en 0.
     *
     * @return array{personas: \Illuminate\Support\Collection, total: float}
     */
    private function datosSeguridadPersona(int $mes, int $anio, ?string $un): array
    {
        // Se agrupa en PHP (no en SQL) para no depender del dialecto (only_full_group_by de
        // MySQL) y para funcionar aunque la columna empleado aún no exista (migración pendiente).
        $tieneEmpleado = Schema::hasColumn('autoliquidacion_aportes', 'empleado');

        $cols = ['cedula', 'razon_social', 'un_codigo', 'concepto_pila', 'aporte_empresa'];
        if ($tieneEmpleado) {
            $cols[] = 'empleado';
            $cols[] = 'empleado_nombre';
        }

        $filas = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)
            ->when($un, fn ($q) => $q->where('un_codigo', $un))
            ->get($cols);

        // Solo se muestran las personas del maestro de Mano de Obra Directa (las demás se trabajan
        // aparte). Se cruza por cédula normalizada y, como respaldo, por nombre normalizado.
        $normCed = fn ($s) => preg_replace('/[^A-Za-z0-9]/', '', mb_strtolower(trim((string) $s)));
        $normNom = function ($s) {
            $s = strtr(mb_strtolower(trim((string) $s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
            $t = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', ' ', $s)));
            sort($t);
            return implode(' ', $t);
        };
        $cedsMO = []; $nomsMO = [];
        foreach (\App\Models\ManoObraDirecta::where('activo', true)->get(['cedula', 'nombre']) as $p) {
            if (($c = $normCed($p->cedula)) !== '') $cedsMO[$c] = true;
            if (($n = $normNom($p->nombre)) !== '') $nomsMO[$n] = true;
        }

        $acc = [];
        foreach ($filas as $r) {
            // Persona = EMPLEADO. Se agrupa por la cédula del empleado ("Empleado"); si esa
            // viene vacía, se agrupa por su NOMBRE ("Nombre del empl"); y solo si tampoco hay
            // nombre, cae al tercero (planillas antiguas). El nombre mostrado siempre prefiere
            // "Nombre del empl".
            $empCed = $tieneEmpleado ? trim((string) $r->empleado) : '';
            $empNom = $tieneEmpleado ? trim((string) $r->empleado_nombre) : '';

            // Saltar quien NO esté en Mano de Obra Directa.
            $enMaestro = ($empCed !== '' && isset($cedsMO[$normCed($empCed)]))
                || ($empNom !== '' && isset($nomsMO[$normNom($empNom)]));
            if (! $enMaestro) {
                continue;
            }

            if ($empCed !== '') {
                $key = 'C:'.$empCed;  $cedDisp = $empCed;         $nombre = $empNom ?: '—';
            } elseif ($empNom !== '') {
                $key = 'N:'.$empNom;  $cedDisp = '';              $nombre = $empNom;
            } else {
                $key = 'T:'.$r->cedula; $cedDisp = (string) $r->cedula; $nombre = $r->razon_social ?: '—';
            }

            if (! isset($acc[$key])) {
                $acc[$key] = ['cedula' => $cedDisp, 'nombre' => $nombre, 'total' => 0.0, 'conceptos' => [], 'un' => []];
            }
            if (($acc[$key]['nombre'] === '—' || $acc[$key]['nombre'] === '') && $nombre) {
                $acc[$key]['nombre'] = $nombre;
            }

            $ap = (float) $r->aporte_empresa;
            $acc[$key]['total'] += $ap;
            $acc[$key]['conceptos'][$r->concepto_pila ?: '—'] = ($acc[$key]['conceptos'][$r->concepto_pila ?: '—'] ?? 0) + $ap;
            $acc[$key]['un'][$r->un_codigo ?: '—'] = ($acc[$key]['un'][$r->un_codigo ?: '—'] ?? 0) + $ap;
        }

        $personas = collect($acc)
            ->filter(fn ($p) => abs($p['total']) > 0.005)             // no personas en 0
            ->map(function ($p) {
                arsort($p['un']);
                $conceptos = collect($p['conceptos'])
                    ->filter(fn ($v) => abs($v) > 0.005)              // no conceptos en 0
                    ->sortDesc()
                    ->map(fn ($v, $k) => ['concepto' => $k, 'aporte' => (float) $v])
                    ->values()->all();

                return [
                    'cedula'    => $p['cedula'],
                    'nombre'    => $p['nombre'] ?: '—',
                    'un'        => (string) (array_key_first($p['un']) ?: '—'),
                    'total'     => (float) $p['total'],
                    'conceptos' => $conceptos,
                ];
            })
            ->sortByDesc('total')
            ->values();

        return ['personas' => $personas, 'total' => (float) $personas->sum('total')];
    }

    /**
     * Carga SÍNCRONA (formulario clásico y respaldo si el navegador no usa AJAX): procesa TODOS
     * los lotes en la misma petición. La web usa preparar()+procesar() con barra de progreso.
     */
    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        // Sin regla mimes: en algunos equipos/Windows el tipo MIME de un .xlsx se detecta mal y la
        // validación lo rechazaba EN SILENCIO. Que de verdad sea la planilla PILA se comprueba al
        // reconocer las columnas (con un mensaje claro).
        $request->validate(['archivo' => 'required|file|max:102400']);
        $this->elevarLimites();

        $nombre  = $request->file('archivo')->getClientOriginalName();
        $rutaRel = $this->guardarArchivoLote($request->file('archivo'), 'autoliquidacion');
        $imp     = new ImportadorAutoliquidacion();
        $motor   = new MotorLotes();

        try {
            $a = $motor->analizar($imp, $rutaRel);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $carga = CargaPorLote::create([
            'tipo' => $imp->tipo(), 'mes' => $a['mes'], 'anio' => $a['anio'],
            'archivo_original' => $nombre,
            'ruta_archivo' => $rutaRel, 'total_filas' => $a['total'],
            'meta_lotes' => $a['meta'], 'estado' => 'procesando',
            'user_id' => $request->user()?->id,
        ]);

        try {
            $motor->correrCompleto($imp, $carga, $this->loteTam);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo procesar el archivo: '.$e->getMessage());
        }

        $resumen = $imp->resumen($a['mes'], $a['anio'], $carga->getMetaLotes());

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $a['mes'], 'anio' => $a['anio']])
            ->with('success', $resumen['mensaje']);
    }

    /**
     * AJAX: recibe el archivo, lo guarda, reconoce el período (reemplaza el anterior) y cuenta las
     * filas. Devuelve el id de la carga y el total para iniciar la barra de progreso.
     */
    public function preparar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['archivo' => 'required|file|max:102400']);
        $this->elevarLimites();

        try {
            $nombre  = $request->file('archivo')->getClientOriginalName();
            $rutaRel = $this->guardarArchivoLote($request->file('archivo'), 'autoliquidacion');
            $imp     = new ImportadorAutoliquidacion();
            $motor   = new MotorLotes();

            $a = $motor->analizar($imp, $rutaRel);

            $carga = CargaPorLote::create([
                'tipo' => $imp->tipo(), 'mes' => $a['mes'], 'anio' => $a['anio'],
                'archivo_original' => $nombre,
                'ruta_archivo' => $rutaRel, 'total_filas' => $a['total'],
                'meta_lotes' => $a['meta'], 'estado' => $a['total'] > 0 ? 'procesando' : 'completado',
                'user_id' => $request->user()?->id,
            ]);

            $payload = ['ok' => true, 'carga_id' => $carga->id, 'total' => $a['total'], 'tam' => $this->loteTam];
            if ($a['total'] === 0) {
                $resumen = $imp->resumen($a['mes'], $a['anio'], $carga->getMetaLotes());
                $payload += ['mensaje' => $resumen['mensaje'], 'done' => true,
                    'redirigir' => route('contable.autoliquidacion.index', ['mes' => $a['mes'], 'anio' => $a['anio']])];
            }

            return response()->json($payload);
        } catch (\RuntimeException $e) {
            // Archivo con formato/período inválido: es corregible por el usuario.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->errorLote('autoliquidacion.preparar', $e);
        }
    }

    /** AJAX: procesa el siguiente lote de la carga y reporta el avance. Siempre responde JSON. */
    public function procesar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['carga_id' => 'required|integer']);
        $this->elevarLimites();

        try {
            $carga = CargaPorLote::where('tipo', 'autoliquidacion')->findOrFail($request->integer('carga_id'));
            $imp   = new ImportadorAutoliquidacion();
            $motor = new MotorLotes();

            $motor->procesarSiguiente($imp, $carga, $this->loteTam);

            $done = $carga->getEstado() !== 'procesando';
            $resp = ['ok' => true, 'procesadas' => $carga->getFilasProcesadas(),
                'total' => $carga->getTotalFilas(), 'done' => $done];
            if ($done) {
                $resumen = $imp->resumen($carga->getMes(), $carga->getAnio(), $carga->getMetaLotes());
                $resp += ['mensaje' => $resumen['mensaje'],
                    'redirigir' => route('contable.autoliquidacion.index', ['mes' => $carga->getMes(), 'anio' => $carga->getAnio()])];
            }

            return response()->json($resp);
        } catch (\Throwable $e) {
            return $this->errorLote('autoliquidacion.procesar', $e);
        }
    }

    /** Registra el error completo y responde SIEMPRE JSON con el mensaje real. */
    private function errorLote(string $contexto, \Throwable $e)
    {
        Log::error("Carga por lotes ({$contexto}) falló", [
            'error' => $e->getMessage(), 'archivo' => $e->getFile(), 'linea' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    /**
     * Vacía (borra) todos los aportes de un período. Sirve para recargar limpio cuando
     * quedaron datos duplicados o incorrectos.
     */
    public function vaciar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $mes  = (int) $request->input('mes');
        $anio = (int) $request->input('anio');

        $n = AutoliquidacionAporte::where('mes', $mes)->where('anio', $anio)->delete();

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mes, 'anio' => $anio, 'tab' => 'resumen'])
            ->with('success', "Período {$mes}/{$anio} vaciado: {$n} filas borradas. Ahora vuelve a cargar la planilla.");
    }

}
