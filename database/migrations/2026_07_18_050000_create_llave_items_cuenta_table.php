<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llave_items_cuenta', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_inventario');                     // código del tipo de inventario
            $table->string('nombre_tipo_inventario')->nullable();  // descripción (opcional)
            $table->string('tipo_movimiento');                     // motivo del movimiento
            $table->string('cuenta');                              // cuenta de costo/gasto destino
            $table->string('naturaleza')->nullable();             // Débito / Crédito (opcional)
            $table->boolean('activo')->default(true);
            $table->timestamps();

            // La llave es el par (tipo de inventario + tipo de movimiento).
            $table->unique(['tipo_inventario', 'tipo_movimiento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llave_items_cuenta');
    }
};
