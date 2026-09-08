<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Porcentajes de redistribución de la mano de obra del personal especial (Grupo B) entre
 * las bolsas de área, POR PERÍODO (mes/año). Editables por período (no fijos). Por persona
 * y período, los porcentajes deben sumar 100%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redistribucion_mo_especial', function (Blueprint $table) {
            $table->id();
            $table->string('cedula');                       // persona del Grupo B
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('un_codigo');                    // UN de la bolsa destino (ej. MTO00099)
            $table->decimal('porcentaje', 6, 2)->default(0); // 0..100
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->unique(['cedula', 'anio', 'mes', 'un_codigo'], 'rme_persona_periodo_un');
            $table->index(['anio', 'mes'], 'rme_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redistribucion_mo_especial');
    }
};
