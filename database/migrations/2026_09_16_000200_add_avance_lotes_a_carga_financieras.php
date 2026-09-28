<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de avance para procesar el cierre contable (BIABLE) POR LOTES en varias
 * peticiones AJAX, sin cambiar el historial existente (carga_financieras) ni su borrado.
 * 'registros' se sigue usando como el total de registros insertados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carga_financieras', function (Blueprint $table) {
            $table->unsignedInteger('total_filas')->default(0)->after('registros');
            $table->unsignedInteger('filas_procesadas')->default(0)->after('total_filas');
            $table->longText('meta_lotes')->nullable()->after('filas_procesadas');
        });
    }

    public function down(): void
    {
        Schema::table('carga_financieras', function (Blueprint $table) {
            $table->dropColumn(['total_filas', 'filas_procesadas', 'meta_lotes']);
        });
    }
};
