<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Jobs\Financiero\ProcesarArchivoFinanciero;
use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class CargaFinancieraController extends Controller
{
    public function index()
    {
        $historial = CargaFinanciera::with('usuario')
            ->orderByDesc('created_at')
            ->get();

        return view('contable.carga', compact('historial'));
    }

    public function store(Request $request)
    {
    abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
    'No tienes permiso para editar en Contabilidad.');
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,csv,xls|max:102400',
            'mes'     => 'required|integer|between:1,12',
            'anio'    => 'required|integer|min:2020',
        ]);

        $mes  = $request->mes;
        $anio = $request->anio;

        if (!file_exists(storage_path('app/financiero'))) {
            mkdir(storage_path('app/financiero'), 0755, true);
        }

        $nombreArchivo = uniqid() . '.xlsx';
        $request->file('archivo')->move(
            storage_path('app/financiero'),
            $nombreArchivo
        );
        $ruta = 'financiero/' . $nombreArchivo;

        $carga = CargaFinanciera::create([
            'mes'              => $mes,
            'anio'             => $anio,
            'archivo_original' => $request->file('archivo')->getClientOriginalName(),
            'ruta_archivo'     => $ruta,
            'estado'           => 'procesando',
            'user_id' => $request->user()?->id,
        ]);

        // Se procesa AQUÍ mismo (no en la cola) para no depender de tener un worker corriendo.
        //  - ignore_user_abort: si el navegador/túnel corta la conexión a mitad, PHP TERMINA igual.
        //  - set_time_limit(0) + memoria alta: el cierre trae miles de filas.
        // El job marca la carga como 'completado'/'error', así que el historial refleja el resultado.
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        try {
            ProcesarArchivoFinanciero::dispatchSync($ruta, $mes, $anio, $carga->id);
        } catch (\Throwable $e) {
            report($e);
            $carga->update(['estado' => 'error', 'error' => $e->getMessage()]);

            return back()->with('error', 'No se pudo procesar el archivo: '.$e->getMessage());
        }

        return back()->with('success',
            'Archivo recibido y procesado. Revisa el historial y los reportes.');
    }

    
public function destroy($id)
{
    abort_unless(auth()->user()->puedeEditarModulo('contabilidad'), 403,
        'No tienes permiso para editar en Contabilidad.');

    $carga = CargaFinanciera::findOrFail($id);

        RegistroFinanciero::where('mes', $carga->mes)
            ->where('anio', $carga->anio)
            ->delete();

        if (file_exists(storage_path('app/' . $carga->ruta_archivo))) {
            unlink(storage_path('app/' . $carga->ruta_archivo));
        }

        $carga->delete();

        return back()->with('success', 'Carga eliminada correctamente.');
    }
}