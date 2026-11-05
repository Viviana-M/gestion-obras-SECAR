<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La planilla PILA cambió de estructura: ahora trae "id. C.O. del Mov" (centro de
 * operación) y "NDC", y ya NO trae "Descripción UN", "Aporte del empl" ni
 * "Real Descontado". Agregamos las dos columnas nuevas (nullable) y dejamos de
 * exigir las que ya no vienen (las decimales conservan default 0, así que un insert
 * que las omita no falla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoliquidacion_aportes', function (Blueprint $table) {
            if (! Schema::hasColumn('autoliquidacion_aportes', 'centro_operacion')) {
                $table->string('centro_operacion')->nullable()->after('cuenta_contable'); // id. C.O. del Mov
            }
            if (! Schema::hasColumn('autoliquidacion_aportes', 'ndc')) {
                $table->string('ndc')->nullable()->after('concepto_pila'); // NDC
            }
        });
    }

    public function down(): void
    {
        Schema::table('autoliquidacion_aportes', function (Blueprint $table) {
            foreach (['centro_operacion', 'ndc'] as $col) {
                if (Schema::hasColumn('autoliquidacion_aportes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
