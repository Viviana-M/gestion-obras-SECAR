<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de progreso de las cargas pesadas que se procesan POR LOTES (en varias
 * peticiones AJAX). El usuario sube UN solo archivo; el archivo se guarda y se procesa
 * en ventanas de N filas, actualizando aquí el avance para pintar la barra de progreso.
 * La usan autoliquidación (PILA) y movimiento de almacén; el cierre contable lleva su
 * propio historial en carga_financieras (que también recibió las columnas de avance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargas_por_lotes', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');                 // autoliquidacion | movimiento
            $table->integer('mes')->nullable();
            $table->integer('anio')->nullable();
            $table->string('archivo_original')->nullable();
            $table->string('ruta_archivo')->nullable();
            $table->unsignedInteger('total_filas')->default(0);
            $table->unsignedInteger('filas_procesadas')->default(0);
            $table->unsignedInteger('filas_insertadas')->default(0);
            $table->longText('meta_lotes')->nullable();   // hoja, encabezado, mapa de columnas, contadores…
            $table->string('estado')->default('procesando'); // procesando | completado | error
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'anio', 'mes']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargas_por_lotes');
    }
};
