<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ficha_proyectos', function (Blueprint $table) {
            $table->id();

            // Llave de cruce con el feed financiero (la OT)
            $table->string('codigo_proyecto')->unique();

            // Datos de contexto (informativos)
            $table->string('cliente')->nullable();
            $table->string('nombre_obra')->nullable();
            $table->decimal('valor_contratado', 18, 2)->nullable();
            $table->decimal('costo_estimado', 18, 2)->nullable();

            // Referencias de comercial (lo ofertado)
            $table->decimal('margen_ofertado', 8, 2)->nullable();   // % ej: 26.00
            $table->decimal('utilidad_ofertada', 18, 2)->nullable(); // $

            // Responsables (texto libre por ahora)
            $table->string('responsable_obra')->nullable();
            $table->string('responsable_comercial')->nullable();

            // Trazabilidad de la carga
            $table->string('origen')->default('manual'); // excel | manual
            $table->foreignId('user_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_proyectos');
    }
};