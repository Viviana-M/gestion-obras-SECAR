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

    Route::get('/financiero/dashboard', [\App\Http\Controllers\Financiero\DashboardController::class, 'index'])->name('financiero.dashboard');
    Route::get('/financiero/detalle', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalle'])->name('financiero.detalle');
    Route::get('/financiero/detalle-cuenta', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalleCuenta'])->name('financiero.detalle.cuenta');
    
    Route::get('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'index'])->name('contable.carga');
    Route::post('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'store'])->name('contable.carga.store');
    Route::delete('/contable/carga/{id}', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'destroy'])->name('contable.carga.eliminar');

    Route::get('/operativo/forecast', [\App\Http\Controllers\Operativo\ForecastController::class, 'index'])->name('operativo.forecast');
    Route::post('/operativo/forecast/guardar', [\App\Http\Controllers\Operativo\ForecastController::class, 'guardar'])->name('operativo.forecast.guardar');
    Route::post('/operativo/forecast/enviar', [\App\Http\Controllers\Operativo\ForecastController::class, 'enviar'])->name('operativo.forecast.enviar');
    });
    Route::get('/comercial/cotizaciones', function () {
        return view('comercial.cotizaciones');
    });
    Route::get('/contable/dashboard', function () {
        return view('contable.dashboard');
    });

require __DIR__.'/auth.php';