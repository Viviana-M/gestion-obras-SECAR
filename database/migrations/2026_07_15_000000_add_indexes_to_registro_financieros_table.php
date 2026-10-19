<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * registro_financieros es la tabla mas grande y consultada del sistema
 * (movimientos cargados desde Excel/Biable) pero no tenia ningun indice,
 * lo que forzaba full-table-scans en todos los dashboards y distribuciones.
 * Se agregan indices para los patrones de consulta reales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            // Filtro/agrupacion por proyecto y periodo (dashboards, distribucion de costos)
            $table->index(['codigo_proyecto', 'anio', 'mes'], 'rf_proyecto_periodo_idx');
            // Filtro por tipo de cuenta mayor (Ingreso / Costos aplicados / por aplicar / Gasto)
            $table->index('cuenta_mayor', 'rf_cuenta_mayor_idx');
            // Recorrido por periodo (listado de periodos disponibles, comparativos)
            $table->index(['anio', 'mes'], 'rf_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropIndex('rf_proyecto_periodo_idx');
            $table->dropIndex('rf_cuenta_mayor_idx');
            $table->dropIndex('rf_periodo_idx');
        });
    }
};
