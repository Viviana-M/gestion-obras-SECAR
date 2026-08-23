<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monto a distribuir por persona y período en el módulo MO Apoyo administrativo y operativo.
 * Contabilidad puede distribuir la TOTALIDAD o una PORCIÓN del saldo retirado de cada tercero;
 * lo distribuido se lleva a costo real (cuenta 6) y el resto queda pendiente. Si no hay registro
 * para el período, por defecto se distribuye el total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monto_distribuir_mo_especial', function (Blueprint $table) {
            $table->id();
            $table->string('cedula');                        // persona (misma clave que el maestro)
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->decimal('monto_distribuir', 16, 2)->default(0); // ≤ total retirado del período
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['cedula', 'anio', 'mes'], 'mdme_persona_periodo');
            $table->index(['anio', 'mes'], 'mdme_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monto_distribuir_mo_especial');
    }
};
