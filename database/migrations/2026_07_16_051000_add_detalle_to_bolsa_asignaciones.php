<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bolsa_asignaciones', function (Blueprint $table) {
            // Trazabilidad: de qué cuentas 14 (y períodos) salió el monto asignado,
            // en orden FIFO. Permite mostrar el detalle sin recalcular.
            // [ {cuenta_14, cuenta_61, periodo (AAAAMM), monto}, ... ]
            $table->json('detalle')->nullable()->after('monto');
        });
    }

    public function down(): void
    {
        Schema::table('bolsa_asignaciones', function (Blueprint $table) {
            $table->dropColumn('detalle');
        });
    }
};
