<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class AutoliquidacionController extends Controller
{
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

        $acc = [];
        foreach ($filas as $r) {
            // Persona = EMPLEADO. Se agrupa por la cédula del empleado ("Empleado"); si esa
            // viene vacía, se agrupa por su NOMBRE ("Nombre del empl"); y solo si tampoco hay
            // nombre, cae al tercero (planillas antiguas). El nombre mostrado siempre prefiere
            // "Nombre del empl".
            $empCed = $tieneEmpleado ? trim((string) $r->empleado) : '';
            $empNom = $tieneEmpleado ? trim((string) $r->empleado_nombre) : '';

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

    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        // Sin regla mimes: en algunos equipos/Windows el tipo MIME de un .xlsx se detecta mal y la
        // validación lo rechazaba EN SILENCIO. Se valida solo que sea un archivo; que de verdad sea
        // la planilla PILA se comprueba leyendo el encabezado más abajo (con mensaje claro).
        $request->validate([
            'archivo' => 'required|file|max:102400',
        ]);

        // Blindajes para importar sin caerse ni depender de un worker:
        //  - ignore_user_abort: si el navegador o el túnel cortan la conexión a mitad, PHP TERMINA
        //    la importación igual (antes, un corte dejaba la carga "sin hacer nada").
        //  - set_time_limit(0) + memory alto: para planillas de miles de filas.
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $archivo = $request->file('archivo');

        // Encabezado + primeras filas (lectura liviana) para reconocer columnas y período.
        $filas = $this->leerPrimerasFilas($archivo, 2000);
        $mapa  = AutoliquidacionImport::mapaColumnas($filas[0] ?? []);

        if (! isset($mapa['empleado'], $mapa['aporte_empresa'], $mapa['fecha'])) {
            return back()->with('error',
                'El archivo no tiene el formato de la planilla de autoliquidación (PILA): faltan las '.
                'columnas "Empleado", "Aporte empresa" y/o "Fecha". Revisa que sea el reporte correcto.');
        }

        $periodo = $this->periodoDesdeFilas($filas, $mapa);
        if (! $periodo) {
            return back()->with('error',
                'No pude leer el período de la columna "Fecha". Revisa que traiga fechas válidas (ej. 2026-08-31).');
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Importa directo (sin transacción envolvente: si la conexión se corta a mitad, lo ya
        // insertado queda). Se desactiva el log de queries (se come la memoria). Cualquier error
        // se muestra en pantalla en vez de fallar en silencio.
        DB::connection()->disableQueryLog();
        try {
            AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();
            Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo, $mapa), $archivo);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo procesar el archivo: '.$e->getMessage());
        }

        $base       = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo);
        $nFilas     = (clone $base)->count();
        $colPersona = Schema::hasColumn('autoliquidacion_aportes', 'empleado')
            ? DB::raw("COALESCE(NULLIF(empleado, ''), cedula)")
            : 'cedula';
        $personas = (clone $base)->distinct()->count($colPersona);
        $empresa  = (float) (clone $base)->sum('aporte_empresa');
        $totalFmt = '$'.number_format($empresa, 0, ',', '.');

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mesArchivo, 'anio' => $anioArchivo])
            ->with('success', "Planilla {$mesArchivo}/{$anioArchivo}: {$nFilas} filas, {$personas} personas, aporte empresa {$totalFmt}.");
    }

    /**
     * Lee SOLO las primeras filas (encabezado + hasta $maxFilas) de la primera hoja, para reconocer
     * columnas y período sin cargar el archivo entero. Ante cualquier problema, cae a la lectura
     * estándar.
     *
     * @return array<int, array<int, mixed>>
     */
    private function leerPrimerasFilas(\Illuminate\Http\UploadedFile $archivo, int $maxFilas): array
    {
        $ruta = $archivo->getRealPath();

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($ruta);
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setReadFilter')) {
                $reader->setReadFilter(new class($maxFilas + 1) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function __construct(private int $max) {}

                    public function readCell($column, $row, $worksheetName = ''): bool
                    {
                        return $row <= $this->max;
                    }
                });
            }

            return $reader->load($ruta)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return Excel::toArray(new class {}, $archivo)[0] ?? [];
        }
    }

    /**
     * Primer par [mes, anio] legible de la columna Fecha; null si ninguna es válida.
     *
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<string, int>  $mapa
     */
    private function periodoDesdeFilas(array $filas, array $mapa): ?array
    {
        $col = $mapa['fecha'] ?? null;
        if ($col === null) {
            return null;
        }
        foreach ($filas as $i => $fila) {
            if ($i === 0) {
                continue;
            }
            $fecha = AutoliquidacionImport::parsearFecha($fila[$col] ?? null);
            if ($fecha) {
                return [(int) $fecha->month, (int) $fecha->year];
            }
        }

        return null;
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
