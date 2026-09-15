<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Concerns\ProcesaCargaPorLotes;
use App\Http\Controllers\Controller;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use App\Support\Lotes\ImportadorCierre;
use App\Support\Lotes\MotorLotes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CargaFinancieraController extends Controller
{
    use ProcesaCargaPorLotes;

    public function index()
    {
        $historial = CargaFinanciera::with('usuario')
            ->orderByDesc('created_at')
            ->get();

        return view('contable.carga', compact('historial'));
    }

    /** Carga SÍNCRONA (formulario clásico / respaldo). La web usa preparar()+procesar(). */
    public function store(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,csv,xls|max:102400',
            'mes'     => 'required|integer|between:1,12',
            'anio'    => 'required|integer|min:2020',
        ]);
        $this->elevarLimites();

        $carga = $this->crearCarga($request);
        $imp   = new ImportadorCierre();
        $motor = new MotorLotes();

        try {
            $this->analizarEn($motor, $imp, $carga);
            $motor->correrCompleto($imp, $carga, $this->loteTam);
        } catch (\Throwable $e) {
            report($e);
            $carga->update(['estado' => 'error', 'error' => $e->getMessage()]);

            return back()->with('error', 'No se pudo procesar el archivo: '.$e->getMessage());
        }

        return back()->with('success',
            $imp->resumen($carga->getMes(), $carga->getAnio(), $carga->getMetaLotes())['mensaje']);
    }

    /** AJAX: guarda el archivo, borra el período y cuenta las filas. */
    public function preparar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,csv,xls|max:102400',
            'mes'     => 'required|integer|between:1,12',
            'anio'    => 'required|integer|min:2020',
        ]);
        $this->elevarLimites();

        $carga = null;
        try {
            $carga = $this->crearCarga($request);
            $imp   = new ImportadorCierre();
            $motor = new MotorLotes();

            $this->analizarEn($motor, $imp, $carga);

            if ($carga->getTotalFilas() === 0) {
                $carga->update(['estado' => 'completado']);

                return response()->json([
                    'ok' => true, 'carga_id' => $carga->id, 'total' => 0, 'tam' => $this->loteTam, 'done' => true,
                    'mensaje' => $imp->resumen($carga->getMes(), $carga->getAnio(), $carga->getMetaLotes())['mensaje'],
                    'redirigir' => route('contable.carga'),
                ]);
            }

            return response()->json(['ok' => true, 'carga_id' => $carga->id, 'total' => $carga->getTotalFilas(), 'tam' => $this->loteTam]);
        } catch (\RuntimeException $e) {
            $carga?->update(['estado' => 'error', 'error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $carga?->update(['estado' => 'error', 'error' => $e->getMessage()]);

            return $this->errorLote('cierre.preparar', $e);
        }
    }

    /** AJAX: procesa el siguiente lote y reporta el avance. Siempre responde JSON. */
    public function procesar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');
        $request->validate(['carga_id' => 'required|integer']);
        $this->elevarLimites();

        try {
            $carga = CargaFinanciera::findOrFail($request->integer('carga_id'));
            $imp   = new ImportadorCierre();
            $motor = new MotorLotes();

            $motor->procesarSiguiente($imp, $carga, $this->loteTam);

            $done = $carga->getEstado() !== 'procesando';
            $resp = ['ok' => true, 'procesadas' => $carga->getFilasProcesadas(),
                'total' => $carga->getTotalFilas(), 'done' => $done];
            if ($done) {
                $resp += ['mensaje' => $imp->resumen($carga->getMes(), $carga->getAnio(), $carga->getMetaLotes())['mensaje'],
                    'redirigir' => route('contable.carga')];
            }

            return response()->json($resp);
        } catch (\Throwable $e) {
            return $this->errorLote('cierre.procesar', $e);
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

    public function destroy($id)
    {
        abort_unless(auth()->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $carga = CargaFinanciera::findOrFail($id);

        RegistroFinanciero::where('mes', $carga->mes)->where('anio', $carga->anio)->delete();
        SaldoBalance::where('mes', $carga->mes)->where('anio', $carga->anio)->delete();

        if ($carga->ruta_archivo && file_exists(storage_path('app/'.$carga->ruta_archivo))) {
            unlink(storage_path('app/'.$carga->ruta_archivo));
        }

        $carga->delete();

        return back()->with('success', 'Carga eliminada correctamente.');
    }

    /** Crea el registro de la carga (historial + progreso) con el archivo ya guardado. */
    private function crearCarga(Request $request): CargaFinanciera
    {
        $nombre  = $request->file('archivo')->getClientOriginalName();
        $rutaRel = $this->guardarArchivoLote($request->file('archivo'), 'financiero');

        return CargaFinanciera::create([
            'mes' => (int) $request->mes, 'anio' => (int) $request->anio,
            'archivo_original' => $nombre, 'ruta_archivo' => $rutaRel,
            'estado' => 'procesando', 'user_id' => $request->user()?->id,
        ]);
    }

    /** Reconoce el archivo (borra el período) y fija total + meta en la carga. */
    private function analizarEn(MotorLotes $motor, ImportadorCierre $imp, CargaFinanciera $carga): void
    {
        $a = $motor->analizar($imp, $carga->ruta_archivo, ['mes' => $carga->getMes(), 'anio' => $carga->getAnio()]);
        $carga->setTotalFilas($a['total']);
        $carga->setMetaLotes($a['meta']);
        $carga->save();
    }
}
