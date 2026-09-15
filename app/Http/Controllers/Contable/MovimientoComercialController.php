<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Concerns\ProcesaCargaPorLotes;
use App\Http\Controllers\Controller;
use App\Models\CargaPorLote;
use App\Models\ItemDistribucion;
use App\Support\Lotes\ImportadorMovimiento;
use App\Support\Lotes\MotorLotes;
use Illuminate\Http\Request;

/**
 * Cargue del movimiento de almacén (BIABLE, hoja Comercial_Mvto) que puebla
 * items_distribucion. El mapeo de columnas y el cruce con la llave de cuentas viven en
 * ImportadorMovimiento; aquí solo se orquesta la carga (síncrona o por lotes vía AJAX).
 */
class MovimientoComercialController extends Controller
{
    use ProcesaCargaPorLotes;

    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $porPeriodo = ItemDistribucion::selectRaw('anio, mes, COUNT(*) as filas, COUNT(DISTINCT codigo_obra) as obras')
            ->groupBy('anio', 'mes')->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.movimiento-comercial', ['porPeriodo' => $porPeriodo]);
    }

    /** Carga SÍNCRONA (formulario clásico / respaldo). La web usa preparar()+procesar(). */
    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls|max:102400']);
        $this->elevarLimites();

        $nombre  = $request->file('archivo')->getClientOriginalName();
        $rutaRel = $this->guardarArchivoLote($request->file('archivo'), 'movimiento');
        $imp     = new ImportadorMovimiento();
        $motor   = new MotorLotes();

        try {
            $a = $motor->analizar($imp, $rutaRel);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $carga = CargaPorLote::create([
            'tipo' => $imp->tipo(), 'mes' => $a['mes'], 'anio' => $a['anio'],
            'archivo_original' => $nombre, 'ruta_archivo' => $rutaRel,
            'total_filas' => $a['total'], 'meta_lotes' => $a['meta'], 'estado' => 'procesando',
            'user_id' => $request->user()?->id,
        ]);

        try {
            $motor->correrCompleto($imp, $carga, $this->loteTam);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'No se pudo procesar el archivo: '.$e->getMessage());
        }

        return $this->redirigirConResumen($imp->resumen($a['mes'], $a['anio'], $carga->getMetaLotes()));
    }

    /** AJAX: guarda el archivo, reconoce los períodos (los reemplaza) y cuenta las filas. */
    public function preparar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls|max:102400']);
        $this->elevarLimites();

        $nombre  = $request->file('archivo')->getClientOriginalName();
        $rutaRel = $this->guardarArchivoLote($request->file('archivo'), 'movimiento');
        $imp     = new ImportadorMovimiento();
        $motor   = new MotorLotes();

        try {
            $a = $motor->analizar($imp, $rutaRel);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $carga = CargaPorLote::create([
            'tipo' => $imp->tipo(), 'mes' => $a['mes'], 'anio' => $a['anio'],
            'archivo_original' => $nombre, 'ruta_archivo' => $rutaRel,
            'total_filas' => $a['total'], 'meta_lotes' => $a['meta'],
            'estado' => $a['total'] > 0 ? 'procesando' : 'completado',
            'user_id' => $request->user()?->id,
        ]);

        $payload = ['carga_id' => $carga->id, 'total' => $a['total'], 'tam' => $this->loteTam];
        if ($a['total'] === 0) {
            $r = $imp->resumen($a['mes'], $a['anio'], $carga->getMetaLotes());
            $payload += ['done' => true, 'mensaje' => $r['mensaje'], 'warning' => $r['warning'] ?? null,
                'redirigir' => route('contable.movimiento-comercial.index')];
        }

        return response()->json($payload);
    }

    /** AJAX: procesa el siguiente lote y reporta el avance. */
    public function procesar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['carga_id' => 'required|integer']);
        $this->elevarLimites();

        $carga = CargaPorLote::where('tipo', 'movimiento')->findOrFail($request->integer('carga_id'));
        $imp   = new ImportadorMovimiento();
        $motor = new MotorLotes();

        try {
            $motor->procesarSiguiente($imp, $carga, $this->loteTam);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo procesar el archivo: '.$e->getMessage(), 'estado' => 'error'], 500);
        }

        $done = $carga->getEstado() !== 'procesando';
        $resp = [
            'procesadas' => $carga->getFilasProcesadas(),
            'total'      => $carga->getTotalFilas(),
            'done'       => $done,
        ];
        if ($done) {
            $r = $imp->resumen($carga->getMes(), $carga->getAnio(), $carga->getMetaLotes());
            $resp += ['mensaje' => $r['mensaje'], 'warning' => $r['warning'] ?? null,
                'redirigir' => route('contable.movimiento-comercial.index')];
        }

        return response()->json($resp);
    }

    /** @param array{mensaje:string,warning?:?string} $resumen */
    private function redirigirConResumen(array $resumen)
    {
        $r = redirect()->route('contable.movimiento-comercial.index')->with('success', $resumen['mensaje']);
        if (! empty($resumen['warning'])) {
            $r->with('warning', $resumen['warning']);
        }

        return $r;
    }
}
