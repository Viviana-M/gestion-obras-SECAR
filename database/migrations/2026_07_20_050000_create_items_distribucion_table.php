<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items_distribucion', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_obra')->index();               // OT / proyecto
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('cuenta');                             // cuenta de costo (agrupador)
            $table->string('item');                               // ítem
            $table->string('tipo_inventario')->nullable();
            $table->string('codigo_movimiento')->nullable();
            $table->string('tipo_movimiento')->nullable();        // descripción del movimiento
            $table->string('naturaleza')->nullable();             // Débito = salida (suma) / Crédito = reintegro (resta)
            $table->string('tercero')->nullable();
            $table->decimal('cantidad', 18, 2)->nullable();
            $table->date('fecha')->nullable();
            $table->string('numero_documento')->nullable();
            $table->decimal('costo', 18, 2)->default(0);          // monto POSITIVO; el signo lo da la naturaleza
            $table->timestamps();

            $table->index(['codigo_obra', 'anio', 'mes']);
            $table->index(['codigo_obra', 'cuenta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items_distribucion');
    }
};
