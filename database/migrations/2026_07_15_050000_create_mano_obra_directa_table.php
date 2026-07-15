<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro de personal de mano de obra directa. La cédula es la llave para cruzar
 * con la planilla de autoliquidación (PILA). Los dos porcentajes definen cómo se
 * reparte esa persona a las bolsas de cada departamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mano_obra_directa', function (Blueprint $table) {
            $table->id();
            $table->string('cedula')->unique();
            $table->string('nombre');
            $table->decimal('pct_mantenimiento', 5, 2)->default(0);
            $table->decimal('pct_instalaciones', 5, 2)->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mano_obra_directa');
    }
};
