<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/financiero/dashboard', function () {
        return view('financiero.dashboard');
    });
    Route::get('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'index'])->name('contable.carga');
    Route::post('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'store'])->name('contable.carga.store');

    Route::get('/operativo/dashboard', function () {
        return view('operativo.dashboard');
    });
    Route::get('/comercial/cotizaciones', function () {
        return view('comercial.cotizaciones');
    });
    Route::get('/contable/dashboard', function () {
        return view('contable.dashboard');
    });
});

require __DIR__.'/auth.php';
Route::get('/diagnostico-excel', function() {
    $path = storage_path('app/test.xlsx');
    if (!file_exists($path)) {
        return 'Sube primero el archivo a storage/app/test.xlsx';
    }
    $data = \Maatwebsite\Excel\Facades\Excel::toArray(new \App\Imports\Financiero\DiagnosticoImport(), $path);
    $headers = array_keys($data[0][0] ?? []);
    return '<pre>' . implode("\n", $headers) . '</pre>';
})->name('diagnostico');
Route::get('/diagnostico-conteo', function() {
    $path = storage_path('app/test.xlsx');
    $data = \Maatwebsite\Excel\Facades\Excel::toArray(
        new \App\Imports\Financiero\DiagnosticoImport(), $path
    );
    $filas = $data[0];
    $prefijos = ['O', 'GI', 'MOA', 'MOB', 'MOC', 'MO', 'C', 'R', 'GM'];
    $total = count($filas);
    $conMovto = 0;
    $conPrefijo = 0;
    $ejemplos = [];

    foreach ($filas as $row) {
        $movto = floatval($row['movto_libro2'] ?? 0);
        if ($movto != 0) {
            $conMovto++;
            $unidad = trim($row['unidad_de_negocio'] ?? $row['unidad_negocio'] ?? '');
            foreach ($prefijos as $p) {
                if (str_starts_with($unidad, $p)) {
                    $conPrefijo++;
                    if (count($ejemplos) < 5) {
                        $ejemplos[] = $unidad . ' | ' . ($row['cuenta'] ?? '') . ' | ' . $movto;
                    }
                    break;
                }
            }
        }
    }

    return '<pre>'
        . "Total filas: $total\n"
        . "Con movto_libro2 != 0: $conMovto\n"
        . "Con prefijo valido: $conPrefijo\n\n"
        . "Ejemplos:\n" . implode("\n", $ejemplos)
        . '</pre>';
});