<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorizaciones de gerencia para distribuir costos en proyectos SIN ingreso en
 * el mes. Un proyecto con ingreso_mes = 0 queda bloqueado; solo puede recibir
 * costos si existe aquí una autorización 'aprobada' para su codigo+mes+anio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizaciones_distribucion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto');
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('estado', 20)->default('pendiente'); // pendiente | aprobada | rechazada
            $table->text('motivo')->nullable();

            // Snapshot financiero al momento de solicitar (lo que el operador pretende
            // distribuir y su impacto en el margen), para que gerencia decida con contexto.
            $table->decimal('monto_a_distribuir', 18, 2)->default(0);
            $table->decimal('margen_mes_pesos', 18, 2)->nullable();
            $table->decimal('margen_mes_pct', 8, 2)->nullable();     // null = sin ingreso en el mes
            $table->decimal('margen_total_pesos', 18, 2)->nullable();
            $table->decimal('margen_total_pct', 8, 2)->nullable();

            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('solicitado_at')->nullable();

            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->text('comentario_gerencia')->nullable();

            $table->timestamps();

            // Una sola autorización por proyecto/mes/año.
            $table->unique(['codigo_proyecto', 'mes', 'anio'], 'aut_dist_proyecto_periodo_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizaciones_distribucion');
    }
};
