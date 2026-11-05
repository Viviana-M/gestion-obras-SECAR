<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro "Grupo B": personal especial cuya mano de obra se REDISTRIBUYE por porcentaje
 * entre las bolsas de área (no por proyecto, a diferencia del Grupo A). Gestionado en
 * Contabilidad. La persona se identifica por su cédula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mano_obra_especial', function (Blueprint $table) {
            $table->id();
            $table->string('cedula')->unique();     // cédula de la persona (Grupo B)
            $table->string('nombre');               // nombre de la persona
            $table->boolean('activo')->default(true);
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mano_obra_especial');
    }
};
