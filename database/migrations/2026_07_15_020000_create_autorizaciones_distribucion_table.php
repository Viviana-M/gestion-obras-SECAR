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
