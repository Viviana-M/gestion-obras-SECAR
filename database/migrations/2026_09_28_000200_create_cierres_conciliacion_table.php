<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierres de conciliación por período (mes, año). Cuando la conciliación de la cuenta 14 y la 6
 * contra el ERP queda en cero (o tolerancia de centavos), contabilidad "cierra" el período: se
 * guarda el saldo, la fecha y el usuario, y queda BLOQUEADO — no se puede recargar BIABLE ni
 * aplicar/deshacer planos con destino en ese mes hasta reabrirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_conciliacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->smallInteger('anio');
            $table->decimal('saldo_sistema_14', 18, 2)->default(0);   // convención ERP (positivo)
            $table->decimal('saldo_sistema_6', 18, 2)->default(0);
            $table->decimal('saldo_erp_14', 18, 2)->nullable();
            $table->decimal('saldo_erp_6', 18, 2)->nullable();
            $table->decimal('diferencia', 18, 2)->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->timestamps();
            $table->unique(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierres_conciliacion');
    }
};
