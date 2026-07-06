<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PreferenciaController;
use App\Http\Controllers\Operativo\MaestroComercialController;
use App\Http\Controllers\Contable\HomologacionController;
use App\Http\Controllers\Contable\PlanoReversionController;
use App\Http\Controllers\Contable\PlanoContableController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Middleware\UsuarioActivo;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [\App\Http\Controllers\DashboardInicioController::class, 'index'])
    ->middleware(['auth', 'verified', UsuarioActivo::class])
    ->name('dashboard');

Route::middleware(['auth', UsuarioActivo::class])->group(function () {

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Homologaciones (plan de cuentas 14 ↔ 61)
    Route::get('/contable/homologaciones', [HomologacionController::class, 'index'])->name('contable.homologaciones.index');
    Route::post('/contable/homologaciones/importar', [HomologacionController::class, 'importar'])->name('contable.homologaciones.importar');
    Route::post('/contable/homologaciones/manual', [HomologacionController::class, 'guardar'])->name('contable.homologaciones.guardar');
    Route::put('/contable/homologaciones/{homologacion}', [HomologacionController::class, 'actualizar'])->name('contable.homologaciones.actualizar');
    Route::delete('/contable/homologaciones/{homologacion}', [HomologacionController::class, 'eliminar'])->name('contable.homologaciones.eliminar');

    // Financiero
    Route::get('/financiero/dashboard', [\App\Http\Controllers\Financiero\DashboardController::class, 'index'])->name('financiero.dashboard');
    Route::get('/financiero/detalle', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalle'])->name('financiero.detalle');
    Route::get('/financiero/detalle-cuenta', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalleCuenta'])->name('financiero.detalle.cuenta');
    Route::get('/financiero/historicos', [\App\Http\Controllers\Financiero\HistoricosController::class, 'index'])->name('financiero.historicos');
    Route::get('/financiero/historicos/detalle', [\App\Http\Controllers\Financiero\HistoricosController::class, 'detalle'])->name('financiero.historicos.detalle');
    Route::get('/financiero/historicos/detalle-cuenta', [\App\Http\Controllers\Financiero\HistoricosController::class, 'detalleCuenta'])->name('financiero.historicos.detalle.cuenta');
    Route::get('/financiero/estados-financieros', [\App\Http\Controllers\Financiero\EstadosFinancierosController::class, 'index'])->name('financiero.estados');
    Route::get('/financiero/comparativo', [\App\Http\Controllers\Financiero\DashboardController::class, 'comparativo'])->name('financiero.comparativo');

    // Contable - cargas y cierres
    Route::get('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'index'])->name('contable.carga');
    Route::post('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'store'])->name('contable.carga.store');
    Route::delete('/contable/carga/{id}', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'destroy'])->name('contable.carga.eliminar');
    Route::get('/contable/cierre-obras', [\App\Http\Controllers\Contable\CierreObrasController::class, 'index'])->name('contable.cierre-obras');
    Route::post('/contable/cierre-obras/excel', [\App\Http\Controllers\Contable\CierreObrasController::class, 'cargarExcel'])->name('contable.cierre-obras.excel');
    Route::post('/contable/cierre-obras/manual', [\App\Http\Controllers\Contable\CierreObrasController::class, 'cerrarManual'])->name('contable.cierre-obras.manual');
    Route::delete('/contable/cierre-obras/{id}', [\App\Http\Controllers\Contable\CierreObrasController::class, 'destroy'])->name('contable.cierre-obras.eliminar');
    Route::get('/contable/plano-reversion', [PlanoReversionController::class, 'exportarPlano'])->name('contable.plano.reversion');

    // Contable - Plano contable (distribución 14 → 61)
    Route::get('/contable/plano-contable', [PlanoContableController::class, 'index'])->name('contable.plano-contable');
    Route::get('/contable/plano-contable/{distribucion}/descargar', [PlanoContableController::class, 'exportarPlano'])->name('contable.plano-contable.descargar');
    Route::post('/contable/plano-contable/{distribucion}/habilitar', [PlanoContableController::class, 'habilitar'])->name('contable.plano-contable.habilitar');

    // Operativo - Forecast (respaldo)
    Route::get('/operativo/forecast', [\App\Http\Controllers\Operativo\ForecastController::class, 'index'])->name('operativo.forecast');
    Route::post('/operativo/forecast/guardar', [\App\Http\Controllers\Operativo\ForecastController::class, 'guardar'])->name('operativo.forecast.guardar');
    Route::post('/operativo/forecast/enviar', [\App\Http\Controllers\Operativo\ForecastController::class, 'enviar'])->name('operativo.forecast.enviar');
    Route::get('/operativo/dashboard', function () {
        return view('operativo.dashboard');
    });

    // Operativo - Distribución de costos
    Route::get('/operativo/distribucion/consultas', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'consultas'])->name('operativo.distribucion.consultas');
    Route::get('/operativo/distribucion', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'index'])->name('operativo.distribucion');
    Route::post('/operativo/distribucion/guardar', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'guardar'])->name('operativo.distribucion.guardar');
    Route::post('/operativo/distribucion/resumen', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'resumen'])->name('operativo.distribucion.resumen');
    Route::get('/operativo/distribucion/{distribucion}/trazabilidad', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'trazabilidad'])->name('operativo.distribucion.trazabilidad');
    Route::get('/operativo/distribucion/version/{version}', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'verVersion'])->name('operativo.distribucion.version');
    Route::delete('/operativo/distribucion/{distribucion}', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'eliminar'])->name('operativo.distribucion.eliminar');

    // Maestro de proyectos (carga comercial)
    Route::get('/operativo/maestro-comercial', [MaestroComercialController::class, 'index'])->name('operativo.maestro.index');
    Route::post('/operativo/maestro-comercial/importar', [MaestroComercialController::class, 'importar'])->name('operativo.maestro.importar');
    Route::post('/operativo/maestro-comercial/manual', [MaestroComercialController::class, 'guardar'])->name('operativo.maestro.guardar');
    Route::put('/operativo/maestro-comercial/{ficha}', [MaestroComercialController::class, 'actualizar'])->name('operativo.maestro.actualizar');
    Route::delete('/operativo/maestro-comercial/{ficha}', [MaestroComercialController::class, 'eliminar'])->name('operativo.maestro.eliminar');

    // Comercial
    Route::get('/comercial/cotizaciones', function () {
        return view('comercial.cotizaciones');
    });

    // Administración de usuarios (solo admin, protegido dentro del controlador)
    Route::get('/admin/usuarios', [UsuarioController::class, 'index'])->name('admin.usuarios.index');
    Route::get('/admin/usuarios/crear', [UsuarioController::class, 'create'])->name('admin.usuarios.create');
    Route::post('/admin/usuarios', [UsuarioController::class, 'store'])->name('admin.usuarios.store');
    Route::get('/admin/usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('admin.usuarios.edit');
    Route::put('/admin/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('admin.usuarios.update');
    Route::post('/admin/usuarios/{usuario}/estado', [UsuarioController::class, 'toggleActivo'])->name('admin.usuarios.estado');

    // Preferencias
    Route::post('/preferencias/menu', [PreferenciaController::class, 'guardarMenu'])->name('preferencias.menu');
});
Route::get('/diag-cuentas', function () {
    // Un registro de ejemplo de "Costos aplicados" con TODAS sus columnas,
    // para ver qué campos existen y si hay alguno que apunte a la cuenta 14 de origen.
    $ejemplo = \App\Models\RegistroFinanciero::where('cuenta_mayor', 'Costos aplicados')
        ->orderByDesc('id')
        ->first();

    // Un registro de ejemplo de "Costos por aplicar" (cuenta 14) para comparar columnas.
    $ejemplo14 = \App\Models\RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
        ->orderByDesc('id')
        ->first();

    // Lista de las columnas reales de la tabla.
    $columnas = \Illuminate\Support\Facades\Schema::getColumnListing(
        (new \App\Models\RegistroFinanciero)->getTable()
    );

    return response()->json([
        'columnas_de_la_tabla'        => $columnas,
        'ejemplo_costos_aplicados_61' => $ejemplo,
        'ejemplo_costos_por_aplicar_14' => $ejemplo14,
    ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
})->middleware('auth');
require __DIR__.'/auth.php';