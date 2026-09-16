<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La planilla PILA trae al TERCERO (fondo: EPS/AFP/ARL/Caja) en "Id. Tercero Mov" /
 * "Razon Social", y al EMPLEADO en "Empleado" (cédula) / "Nombre del empl". Para poder
 * agrupar el costo POR PERSONA (y no por fondo) guardamos las columnas del empleado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoliquidacion_aportes', function (Blueprint $table) {
            if (! Schema::hasColumn('autoliquidacion_aportes', 'empleado')) {
                $table->string('empleado')->nullable()->after('razon_social');          // cédula del empleado
            }
            if (! Schema::hasColumn('autoliquidacion_aportes', 'empleado_nombre')) {
                $table->string('empleado_nombre')->nullable()->after('empleado');        // nombre del empleado
            }
        });

        // Índice para agrupar por persona por período.
        Schema::table('autoliquidacion_aportes', function (Blueprint $table) {
            if (Schema::hasColumn('autoliquidacion_aportes', 'empleado')) {
                $table->index(['anio', 'mes', 'empleado'], 'aa_periodo_empleado_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('autoliquidacion_aportes', function (Blueprint $table) {
            if (Schema::hasColumn('autoliquidacion_aportes', 'empleado')) {
                $table->dropIndex('aa_periodo_empleado_idx');
            }
            foreach (['empleado', 'empleado_nombre'] as $col) {
                if (Schema::hasColumn('autoliquidacion_aportes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
