<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de cierre/período de edición manejado por Contabilidad. Por cada (mes, año)
 * un estado abierto/cerrado. La Distribución (Operaciones) solo se puede editar cuando
 * el período está ABIERTO. Sin registro = cerrado (mes en curso, solo lectura).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierre_periodos', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->boolean('abierto')->default(false);
            $table->unsignedBigInteger('abierto_por')->nullable();
            $table->timestamp('abierto_at')->nullable();
            $table->unsignedBigInteger('cerrado_por')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->timestamps();

            $table->unique(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_periodos');
    }
};
