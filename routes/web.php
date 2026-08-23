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
use App\Http\Controllers\Contable\PlanoReclasificacionController;

Route::get('/', function () {
    // Si ya inició sesión, llévalo a su página de inicio (primer módulo con permiso);
    // si no, la portada pública.
    if (auth()->check()) {
        return redirect(auth()->user()->paginaInicio());
    }
    return view('welcome');
});

Route::get('/dashboard', [\App\Http\Controllers\DashboardInicioController::class, 'index'])
    ->middleware(['auth', 'verified', UsuarioActivo::class])
    ->name('dashboard');

Route::middleware(['auth', UsuarioActivo::class])->group(function () {

    // Perfil (cualquier usuario autenticado)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Preferencias de la interfaz (cualquier usuario autenticado)
    Route::post('/preferencias/menu', [PreferenciaController::class, 'guardarMenu'])->name('preferencias.menu');

    // ══════════════════════ CONTABILIDAD ══════════════════════
    Route::middleware('modulo:contabilidad')->group(function () {

        // Homologaciones (plan de cuentas 14 ↔ 61)
        Route::get('/contable/homologaciones', [HomologacionController::class, 'index'])->name('contable.homologaciones.index');
        Route::post('/contable/homologaciones/importar', [HomologacionController::class, 'importar'])->name('contable.homologaciones.importar');
        Route::post('/contable/homologaciones/manual', [HomologacionController::class, 'guardar'])->name('contable.homologaciones.guardar');
        Route::put('/contable/homologaciones/{homologacion}', [HomologacionController::class, 'actualizar'])->name('contable.homologaciones.actualizar');
        Route::delete('/contable/homologaciones/{homologacion}', [HomologacionController::class, 'eliminar'])->name('contable.homologaciones.eliminar');

        // Cargas y cierres
        Route::get('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'index'])->name('contable.carga');
        Route::post('/contable/carga', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'store'])->name('contable.carga.store');
        Route::delete('/contable/carga/{id}', [\App\Http\Controllers\Financiero\CargaFinancieraController::class, 'destroy'])->name('contable.carga.eliminar');
        Route::get('/contable/cierre-obras', [\App\Http\Controllers\Contable\CierreObrasController::class, 'index'])->name('contable.cierre-obras');
        Route::post('/contable/cierre-obras/excel', [\App\Http\Controllers\Contable\CierreObrasController::class, 'cargarExcel'])->name('contable.cierre-obras.excel');
        Route::post('/contable/cierre-obras/manual', [\App\Http\Controllers\Contable\CierreObrasController::class, 'cerrarManual'])->name('contable.cierre-obras.manual');
        Route::delete('/contable/cierre-obras/{id}', [\App\Http\Controllers\Contable\CierreObrasController::class, 'destroy'])->name('contable.cierre-obras.eliminar');
        // Plano de cuentas 14 con saldos contrarios (reversión): página + descarga.
        Route::get('/contable/plano-reversion', [PlanoReversionController::class, 'index'])->name('contable.plano-reversion.index');
        Route::get('/contable/plano-reversion/excel', [PlanoReversionController::class, 'exportarPlano'])->name('contable.plano-reversion.excel');

        // Cierre / apertura del período de edición de la Distribución.
        Route::get('/contable/cierre', [\App\Http\Controllers\Contable\CierrePeriodoController::class, 'index'])->name('contable.cierre.index');
        Route::post('/contable/cierre', [\App\Http\Controllers\Contable\CierrePeriodoController::class, 'toggle'])->name('contable.cierre.toggle');

        // Autoliquidación de aportes (PILA) — carga y resumen (fase 1).
        Route::get('/contable/autoliquidacion', [\App\Http\Controllers\Contable\AutoliquidacionController::class, 'index'])->name('contable.autoliquidacion.index');
        Route::post('/contable/autoliquidacion', [\App\Http\Controllers\Contable\AutoliquidacionController::class, 'store'])->name('contable.autoliquidacion.store');
        // Vaciar (borrar) los aportes de un período, para recargar limpio.
        Route::post('/contable/autoliquidacion/vaciar', [\App\Http\Controllers\Contable\AutoliquidacionController::class, 'vaciar'])->name('contable.autoliquidacion.vaciar');
        // Descarga a Excel del costo de seguridad social por persona (pestaña del módulo).
        Route::get('/contable/autoliquidacion/personas/excel', [\App\Http\Controllers\Contable\AutoliquidacionController::class, 'personasExcel'])->name('contable.autoliquidacion.personas.excel');
        // Cargue del movimiento comercial (ítems → items_distribucion)
        Route::get('/contable/movimiento-comercial', [\App\Http\Controllers\Contable\MovimientoComercialController::class, 'index'])->name('contable.movimiento-comercial.index');
        Route::post('/contable/movimiento-comercial', [\App\Http\Controllers\Contable\MovimientoComercialController::class, 'store'])->name('contable.movimiento-comercial.store');

        // Plano contable (distribución 14 → 61)
        Route::get('/contable/plano-contable', [PlanoContableController::class, 'index'])->name('contable.plano-contable');
        Route::get('/contable/plano-contable/{distribucion}/descargar', [PlanoContableController::class, 'exportarPlano'])->name('contable.plano-contable.descargar');
        Route::post('/contable/plano-contable/{distribucion}/habilitar', [PlanoContableController::class, 'habilitar'])->name('contable.plano-contable.habilitar');
        Route::get   ('/contable/reclasificaciones',                    [PlanoReclasificacionController::class, 'index'])    ->name('contable.reclasificaciones.index');
        Route::get   ('/contable/reclasificaciones/{homologacion}',     [PlanoReclasificacionController::class, 'detalle'])  ->name('contable.reclasificaciones.detalle');
        Route::get   ('/contable/reclasificaciones/{homologacion}/plano',[PlanoReclasificacionController::class, 'descargar'])->name('contable.reclasificaciones.descargar');
        Route::post  ('/contable/reclasificaciones/{homologacion}/marcar',[PlanoReclasificacionController::class, 'marcar']) ->name('contable.reclasificaciones.marcar');
    });

    // ══════════════════════ FINANCIERO ══════════════════════
    Route::middleware('modulo:gestion_financiera')->group(function () {
        Route::get('/financiero/dashboard', [\App\Http\Controllers\Financiero\DashboardController::class, 'index'])->name('financiero.dashboard');
        Route::get('/financiero/detalle', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalle'])->name('financiero.detalle');
        Route::get('/financiero/detalle-cuenta', [\App\Http\Controllers\Financiero\DashboardController::class, 'detalleCuenta'])->name('financiero.detalle.cuenta');
        Route::get('/financiero/historicos', [\App\Http\Controllers\Financiero\HistoricosController::class, 'index'])->name('financiero.historicos');
        Route::get('/financiero/historicos/detalle', [\App\Http\Controllers\Financiero\HistoricosController::class, 'detalle'])->name('financiero.historicos.detalle');
        Route::get('/financiero/historicos/detalle-cuenta', [\App\Http\Controllers\Financiero\HistoricosController::class, 'detalleCuenta'])->name('financiero.historicos.detalle.cuenta');
        Route::get('/financiero/estados-financieros', [\App\Http\Controllers\Financiero\EstadosFinancierosController::class, 'index'])->name('financiero.estados');
        Route::get('/financiero/comparativo', [\App\Http\Controllers\Financiero\DashboardController::class, 'comparativo'])->name('financiero.comparativo');
    });

    // ══════════════════════ OPERATIVO ══════════════════════
    Route::middleware('modulo:operacion')->group(function () {
        // Forecast (respaldo)
        Route::get('/operativo/forecast', [\App\Http\Controllers\Operativo\ForecastController::class, 'index'])->name('operativo.forecast');
        Route::post('/operativo/forecast/guardar', [\App\Http\Controllers\Operativo\ForecastController::class, 'guardar'])->name('operativo.forecast.guardar');
        Route::post('/operativo/forecast/enviar', [\App\Http\Controllers\Operativo\ForecastController::class, 'enviar'])->name('operativo.forecast.enviar');
        Route::get('/operativo/dashboard', function () {
            return view('operativo.dashboard');
        });

        // Distribución de costos
        Route::get('/operativo/distribucion/consultas', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'consultas'])->name('operativo.distribucion.consultas');
        Route::get('/operativo/distribucion/reporte-saldos', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'reporteSaldos'])->name('operativo.distribucion.reporte-saldos');
        Route::get('/operativo/distribucion', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'index'])->name('operativo.distribucion');
        Route::post('/operativo/distribucion/guardar', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'guardar'])->name('operativo.distribucion.guardar');
        Route::post('/operativo/distribucion/resumen', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'resumen'])->name('operativo.distribucion.resumen');
        Route::post('/operativo/distribucion/bolsa-montos', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'guardarBolsaMontos'])->name('operativo.distribucion.bolsa-montos');
        Route::post('/operativo/provisiones', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'crearProvision'])->name('operativo.provisiones.crear');
        Route::post('/operativo/provisiones/{provision}/reversar', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'reversarProvision'])->name('operativo.provisiones.reversar');
        // Informe: facturado por tipo de obra
        Route::get('/operativo/facturado', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'facturado'])->name('operativo.facturado');
        // Fase D: reasignar un ítem de una obra a otra
        Route::post('/operativo/items/{item}/reasignar', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'reasignarItem'])->name('operativo.items.reasignar');
        Route::get('/operativo/distribucion/{distribucion}/trazabilidad', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'trazabilidad'])->name('operativo.distribucion.trazabilidad');
        Route::get('/operativo/distribucion/version/{version}', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'verVersion'])->name('operativo.distribucion.version');
        Route::get('/operativo/distribucion/areas/{distribucion}/consultar', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'consultarAreas'])->name('operativo.distribucion.areas-consultar');
        Route::get('/operativo/distribucion/{distribucion}/reporte-obras', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'reporteObras'])->name('operativo.distribucion.reporte-obras');
        Route::delete('/operativo/distribucion/{distribucion}', [\App\Http\Controllers\Operativo\DistribucionCostosController::class, 'eliminar'])->name('operativo.distribucion.eliminar');
        Route::get('/operativo/distribucion-areas', [\App\Http\Controllers\Operativo\DistribucionAreasController::class, 'index'])->name('operativo.distribucion-areas');

        // Maestro de proyectos (carga comercial)
        Route::get('/operativo/maestro-comercial', [MaestroComercialController::class, 'index'])->name('operativo.maestro.index');
        Route::post('/operativo/maestro-comercial/importar', [MaestroComercialController::class, 'importar'])->name('operativo.maestro.importar');
        Route::post('/operativo/maestro-comercial/manual', [MaestroComercialController::class, 'guardar'])->name('operativo.maestro.guardar');
        Route::put('/operativo/maestro-comercial/{ficha}', [MaestroComercialController::class, 'actualizar'])->name('operativo.maestro.actualizar');
        Route::delete('/operativo/maestro-comercial/{ficha}', [MaestroComercialController::class, 'eliminar'])->name('operativo.maestro.eliminar');

        // Solicitar autorización de gerencia para distribuir en un proyecto sin ingreso.
        Route::post('/operativo/autorizaciones/solicitar', [\App\Http\Controllers\Operativo\AutorizacionDistribucionController::class, 'solicitar'])->name('operativo.autorizaciones.solicitar');

        // Obras abiertas con costo pero sin saldo en cuenta 14 (lista de revisión).
        Route::get('/operativo/obras-revision', [\App\Http\Controllers\Operativo\ObrasRevisionController::class, 'index'])->name('operativo.obras-revision.index');
        Route::post('/operativo/obras-revision/observar', [\App\Http\Controllers\Operativo\ObrasRevisionController::class, 'observar'])->name('operativo.obras-revision.observar');
    });

    // ══════════════════════ AUTORIZACIONES (solo gerencia, verificado en el controlador) ══════════════════════
    // No va bajo modulo:operacion: un director/gerente puede resolver aunque no tenga ese módulo.
    Route::get('/operativo/autorizaciones', [\App\Http\Controllers\Operativo\AutorizacionDistribucionController::class, 'index'])->name('operativo.autorizaciones.index');
    Route::post('/operativo/autorizaciones/{autorizacion}/aprobar', [\App\Http\Controllers\Operativo\AutorizacionDistribucionController::class, 'aprobar'])->name('operativo.autorizaciones.aprobar');
    Route::post('/operativo/autorizaciones/{autorizacion}/rechazar', [\App\Http\Controllers\Operativo\AutorizacionDistribucionController::class, 'rechazar'])->name('operativo.autorizaciones.rechazar');

    // ══════════════════════ COMERCIAL ══════════════════════
    // ══════════════════════ ADMINISTRACIÓN (solo admin, verificado en el controlador) ══════════════════════
    Route::get('/admin/usuarios', [UsuarioController::class, 'index'])->name('admin.usuarios.index');
    Route::get('/admin/usuarios/crear', [UsuarioController::class, 'create'])->name('admin.usuarios.create');
    Route::post('/admin/usuarios', [UsuarioController::class, 'store'])->name('admin.usuarios.store');
    Route::get('/admin/usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('admin.usuarios.edit');
    Route::put('/admin/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('admin.usuarios.update');
    Route::post('/admin/usuarios/{usuario}/estado', [UsuarioController::class, 'toggleActivo'])->name('admin.usuarios.estado');
    Route::get('/admin/un-bolsas', [\App\Http\Controllers\Admin\UnBolsaController::class, 'index'])->name('admin.un-bolsas.index');
    Route::post('/admin/un-bolsas', [\App\Http\Controllers\Admin\UnBolsaController::class, 'store'])->name('admin.un-bolsas.store');
    Route::put('/admin/un-bolsas/{unBolsa}', [\App\Http\Controllers\Admin\UnBolsaController::class, 'update'])->name('admin.un-bolsas.update');
    Route::put('/admin/un-bolsas/{unBolsa}/toggle', [\App\Http\Controllers\Admin\UnBolsaController::class, 'toggle'])->name('admin.un-bolsas.toggle');
    Route::get('/admin/terceros-mano-obra', [\App\Http\Controllers\Admin\TerceroManoObraController::class, 'index'])->name('admin.terceros-mano-obra.index');
    Route::post('/admin/terceros-mano-obra', [\App\Http\Controllers\Admin\TerceroManoObraController::class, 'store'])->name('admin.terceros-mano-obra.store');
    Route::put('/admin/terceros-mano-obra/{terceroManoObra}', [\App\Http\Controllers\Admin\TerceroManoObraController::class, 'update'])->name('admin.terceros-mano-obra.update');
    Route::put('/admin/terceros-mano-obra/{terceroManoObra}/toggle', [\App\Http\Controllers\Admin\TerceroManoObraController::class, 'toggle'])->name('admin.terceros-mano-obra.toggle');
    Route::get('/admin/mano-obra-directa', [\App\Http\Controllers\Admin\ManoObraDirectaController::class, 'index'])->name('admin.mano-obra-directa.index');
    Route::post('/admin/mano-obra-directa', [\App\Http\Controllers\Admin\ManoObraDirectaController::class, 'store'])->name('admin.mano-obra-directa.store');
    Route::put('/admin/mano-obra-directa/{manoObraDirecta}', [\App\Http\Controllers\Admin\ManoObraDirectaController::class, 'update'])->name('admin.mano-obra-directa.update');
    Route::put('/admin/mano-obra-directa/{manoObraDirecta}/toggle', [\App\Http\Controllers\Admin\ManoObraDirectaController::class, 'toggle'])->name('admin.mano-obra-directa.toggle');
    Route::get('/admin/llave-items', [\App\Http\Controllers\Admin\LlaveItemCuentaController::class, 'index'])->name('admin.llave-items.index');
    Route::post('/admin/llave-items', [\App\Http\Controllers\Admin\LlaveItemCuentaController::class, 'store'])->name('admin.llave-items.store');
    Route::post('/admin/llave-items/importar', [\App\Http\Controllers\Admin\LlaveItemCuentaController::class, 'importar'])->name('admin.llave-items.importar');
    Route::put('/admin/llave-items/{llaveItemCuenta}', [\App\Http\Controllers\Admin\LlaveItemCuentaController::class, 'update'])->name('admin.llave-items.update');
    Route::put('/admin/llave-items/{llaveItemCuenta}/toggle', [\App\Http\Controllers\Admin\LlaveItemCuentaController::class, 'toggle'])->name('admin.llave-items.toggle');
});
require __DIR__.'/auth.php';
