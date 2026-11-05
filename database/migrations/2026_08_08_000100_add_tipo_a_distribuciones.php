<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de distribución para separar la consulta en "Mis distribuciones":
 *  - 'obras'  → distribución de obras (inventario en tránsito / cuenta 14). La actual.
 *  - 'areas'  → otros costos (áreas / bolsas). Se poblará cuando esa distribución se guarde.
 * Los registros existentes quedan como 'obras'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->string('tipo', 20)->default('obras')->after('departamento');
        });
    }

    public function down(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
