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
            'user_id'          => auth()->id(),
        ]);

        ProcesarArchivoFinanciero::dispatch($ruta, $mes, $anio, $carga->id);

        return back()->with('success',
            'Archivo recibido. Procesando en segundo plano — revisa el historial en unos minutos.');
    }

    public function destroy($id)
    {
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