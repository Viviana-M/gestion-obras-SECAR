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
        $ultimaCarga = RegistroFinanciero::selectRaw('mes, anio, COUNT(*) as registros')
            ->groupBy('mes', 'anio')
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->get();

        return view('financiero.carga', compact('ultimaCarga'));
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

    // Solo guarda el archivo y registra la carga
    $ruta = $request->file('archivo')->store('financiero');

    $carga = CargaFinanciera::create([
        'mes'              => $mes,
        'anio'             => $anio,
        'archivo_original' => $request->file('archivo')->getClientOriginalName(),
        'ruta_archivo'     => $ruta,
        'estado'           => 'procesando',
        'user_id'          => auth()->id(),
    ]);

    // Despacha sin procesar nada aquí
    ProcesarArchivoFinanciero::dispatch($ruta, $mes, $anio, $carga->id);

    return back()->with('success',
        'Archivo recibido. Procesando en segundo plano — revisa el historial en unos minutos.');
}
}