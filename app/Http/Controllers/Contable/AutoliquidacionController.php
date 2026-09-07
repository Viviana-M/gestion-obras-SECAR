<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Imports\Contable\AutoliquidacionImport;
use App\Models\AutoliquidacionAporte;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        // Planillas grandes (miles de filas): dar tiempo y memoria a esta petición. Si el servidor
        // tiene el memory_limit ajustado (p. ej. 128M), leer/importar el archivo completo lo agota y
        // la carga "no hace nada" (muere sin alcanzar a mostrar mensaje). Ambas llamadas son @ por
        // si el hosting no permite cambiarlas (no rompen: la lectura ya se hace por partes y por
        // chunks para no depender de esto).
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $archivo = $request->file('archivo');

        // Para reconocer las columnas (por su nombre) y sacar el período solo hace falta el
        // encabezado y las primeras filas: se leen SOLO esas, no el archivo completo. Leerlo entero
        // aquí con Excel::toArray dispara la memoria (una planilla de miles de filas puede pasar de
        // 100 MB y morir sin mensaje en servidores con memory_limit ajustado). La importación de
        // todas las filas la hace Excel::import por lotes (liviano en memoria).
        $filas = $this->leerPrimerasFilas($archivo, 2000);
        $mapa  = AutoliquidacionImport::mapaColumnas($filas[0] ?? []);

        // Validar que sea una planilla de autoliquidación (PILA): debe traer, reconocidas por su
        // nombre, las columnas Empleado, Aporte empresa y Fecha. Así se aceptan tanto el plano
        // estándar como el movimiento contable de la PILA, y se rechaza (con mensaje claro, en vez
        // de fallar en silencio) cualquier otro reporte que no traiga el detalle por empleado.
        if (! $this->formatoPilaOk($mapa)) {
            return back()->with('error',
                'El archivo no tiene el formato de la planilla de autoliquidación (PILA). '.
                'Debe traer, al menos, las columnas "Empleado", "Aporte empresa" y "Fecha". '.
                'El archivo que subiste parece otro reporte del ERP. '.
                'Descarga la AUTOLIQUIDACIÓN (PILA) con las columnas indicadas abajo.');
        }

        // El período se toma de la columna FECHA (ej. 2026-04-30 → abril 2026).
        $periodo = $this->periodoDesdeFilas($filas, $mapa);
        if (! $periodo) {
            return back()->with('error',
                'No pude leer el período de la columna "Fecha". Revisa que traiga fechas '.
                'válidas (ej. 2026-04-30).');
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Reemplazar la planilla del mismo período (borrar e insertar). Se serializa con un
        // candado por período: si el archivo es grande y tarda, un doble clic o un reenvío del
        // navegador NO procesa dos veces a la vez (el segundo espera y, al entrar, vuelve a
        // borrar antes de insertar). Además el borrado+insert es atómico (transacción).
        Cache::lock("autoliquidacion:{$mesArchivo}:{$anioArchivo}", 300)->block(45, function () use ($mesArchivo, $anioArchivo, $archivo, $mapa) {
            DB::transaction(function () use ($mesArchivo, $anioArchivo, $archivo, $mapa) {
                AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();
                Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo, $mapa), $archivo);
            });
        });

        $base     = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo);
        $filas    = (clone $base)->count();
        // "Personas" = empleados distintos (la cédula del empleado), no los fondos/EPS (cedula = NIT
        // del tercero). Si la planilla no trae la columna empleado, cae a la cédula del tercero.
        $colPersona = Schema::hasColumn('autoliquidacion_aportes', 'empleado')
            ? DB::raw("COALESCE(NULLIF(empleado, ''), cedula)")
            : 'cedula';
        $personas = (clone $base)->distinct()->count($colPersona);
        $empresa  = (float) (clone $base)->sum('aporte_empresa');
        $totalFmt = '$'.number_format($empresa, 0, ',', '.');

        return redirect()->route('contable.autoliquidacion.index', ['mes' => $mesArchivo, 'anio' => $anioArchivo])
            ->with('success', "Planilla {$mesArchivo}/{$anioArchivo}: {$filas} filas, {$personas} personas, aporte empresa {$totalFmt}.");
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

    /**
     * Lee SOLO las primeras filas del archivo (encabezado + hasta $maxFilas de datos) de la primera
     * hoja, sin cargar el resto en memoria. Sirve para reconocer las columnas y detectar el período
     * de planillas grandes sin dispararse la memoria. Devuelve la matriz de filas (0-based).
     *
     * @return array<int, array<int, mixed>>
     */
    private function leerPrimerasFilas(\Illuminate\Http\UploadedFile $archivo, int $maxFilas): array
    {
        $ruta = $archivo->getRealPath();

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($ruta);
            $reader->setReadDataOnly(true);

            // Leer únicamente las primeras (maxFilas + encabezado) filas.
            if (method_exists($reader, 'setReadFilter')) {
                $reader->setReadFilter(new class($maxFilas + 1) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function __construct(private int $max) {}

                    public function readCell($column, $row, $worksheetName = ''): bool
                    {
                        return $row <= $this->max;
                    }
                });
            }

            // Solo la primera hoja (evita cargar copias/resúmenes).
            if (method_exists($reader, 'listWorksheetNames') && method_exists($reader, 'setLoadSheetsOnly')) {
                $hojas = $reader->listWorksheetNames($ruta);
                if (! empty($hojas[0])) {
                    $reader->setLoadSheetsOnly($hojas[0]);
                }
            }

            return $reader->load($ruta)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            // Ante cualquier problema con el lector acotado, caer a la lectura estándar.
            return Excel::toArray(new class {}, $archivo)[0] ?? [];
        }
    }

    /**
     * Lee el período [mes, anio] de la columna Fecha (ubicada por su nombre, no por posición)
     * tomando la primera fila de datos con una fecha válida. Null si ninguna es legible.
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
                continue; // encabezado
            }
            $fecha = AutoliquidacionImport::parsearFecha($fila[$col] ?? null);
            if ($fecha) {
                return [(int) $fecha->month, (int) $fecha->year];
            }
        }

        return null;
    }

    /**
     * Valida que el archivo sea una planilla de autoliquidación (PILA): que se hayan reconocido
     * —por su nombre de columna— el Empleado, el Aporte empresa y la Fecha. Así se aceptan tanto
     * el plano estándar como el movimiento contable de la PILA, y se distingue de otros reportes
     * (p. ej. un movimiento contable sin detalle por empleado, con Valor Débito/Crédito).
     *
     * @param  array<string, int>  $mapa
     */
    private function formatoPilaOk(array $mapa): bool
    {
        return isset($mapa['empleado'], $mapa['aporte_empresa'], $mapa['fecha']);
    }
}
