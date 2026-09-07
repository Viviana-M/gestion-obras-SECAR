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

        @set_time_limit(0);

        $archivo = $request->file('archivo');

        // Se lee el archivo una vez para validar el formato y sacar el período.
        $filas = Excel::toArray(new class {}, $archivo)[0] ?? [];

        // Validar que sea la planilla PILA esperada (13 columnas, con "Fecha" en la col 6 y
        // una columna "Aporte empresa"). Si no, avisar claramente en vez de fallar en silencio.
        if (! $this->formatoPilaOk($filas[0] ?? [])) {
            return back()->with('error',
                'El archivo no tiene el formato de la planilla PILA que espera el módulo '.
                '(13 columnas: … Fecha en la columna 6, Empleado y Aporte empresa). '.
                'El archivo que subiste parece un movimiento contable de otro reporte. '.
                'Descarga desde el ERP el reporte de AUTOLIQUIDACIÓN (PILA) con las columnas indicadas abajo.');
        }

        // El período se toma de la columna FECHA (ej. 2026-04-30 → abril 2026).
        $periodo = $this->periodoDesdeFilas($filas);
        if (! $periodo) {
            return back()->with('error',
                'No pude leer el período de la columna "Fecha" (columna 6). Revisa que traiga fechas '.
                'válidas (ej. 2026-04-30).');
        }
        [$mesArchivo, $anioArchivo] = $periodo;

        // Reemplazar la planilla del mismo período (borrar e insertar). Se serializa con un
        // candado por período: si el archivo es grande y tarda, un doble clic o un reenvío del
        // navegador NO procesa dos veces a la vez (el segundo espera y, al entrar, vuelve a
        // borrar antes de insertar). Además el borrado+insert es atómico (transacción).
        Cache::lock("autoliquidacion:{$mesArchivo}:{$anioArchivo}", 300)->block(45, function () use ($mesArchivo, $anioArchivo, $archivo) {
            DB::transaction(function () use ($mesArchivo, $anioArchivo, $archivo) {
                AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo)->delete();
                Excel::import(new AutoliquidacionImport($mesArchivo, $anioArchivo), $archivo);
            });
        });

        $base     = AutoliquidacionAporte::where('mes', $mesArchivo)->where('anio', $anioArchivo);
        $filas    = (clone $base)->count();
        $personas = (clone $base)->distinct()->count('cedula');
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
     * Lee el período [mes, anio] de la columna Fecha (índice 5) tomando la primera fila de
     * datos con una fecha válida. Null si ninguna es legible.
     */
    private function periodoDesdeFilas(array $filas): ?array
    {
        foreach ($filas as $i => $fila) {
            if ($i === 0) {
                continue; // encabezado
            }
            $fecha = AutoliquidacionImport::parsearFecha($fila[5] ?? null);
            if ($fecha) {
                return [(int) $fecha->month, (int) $fecha->year];
            }
        }

        return null;
    }

    /**
     * Valida que el encabezado corresponda a la planilla PILA esperada: la columna 6 (índice 5)
     * debe ser "Fecha" y debe existir una columna "Aporte empresa". Así se distingue de otros
     * reportes (p. ej. un movimiento contable con Valor Débito/Crédito y la Fecha en otra columna).
     */
    private function formatoPilaOk(array $encabezado): bool
    {
        $norm = static function ($s): string {
            $s = mb_strtolower(trim((string) $s));
            return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        };
        $cols = array_map($norm, $encabezado);

        $fechaEnCol6 = str_contains($cols[5] ?? '', 'fecha');
        $hayAporteEmpresa = false;
        foreach ($cols as $c) {
            if (str_contains($c, 'aporte') && str_contains($c, 'empresa')) {
                $hayAporteEmpresa = true;
                break;
            }
        }

        return $fechaEnCol6 && $hayAporteEmpresa;
    }
}
