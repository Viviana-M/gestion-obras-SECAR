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
});

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('/financiero/dashboard', function () {
        return view('financiero.dashboard');
    });
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