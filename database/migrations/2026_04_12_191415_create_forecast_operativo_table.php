<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('forecast_operativo', function (Blueprint $table) {
        $table->id();
        $table->integer('mes');
        $table->integer('anio');
        $table->string('codigo_proyecto');
        $table->string('nombre_proyecto')->nullable();
        $table->string('cuenta_contable');
        $table->string('descripcion')->nullable();
        $table->decimal('saldo_14', 18, 2)->default(0);
        $table->decimal('monto_a_mover', 18, 2)->default(0);
        $table->boolean('facturado')->default(false);
        $table->decimal('margen_minimo_pct', 5, 2)->default(0);
        $table->enum('estado', ['borrador', 'enviado', 'aprobado'])->default('borrador');
        $table->foreignId('user_id')->constrained();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_operativo');
    }
};
