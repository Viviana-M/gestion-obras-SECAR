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
    Schema::create('movimiento_contable', function (Blueprint $table) {
        $table->id();
        $table->integer('mes');
        $table->integer('anio');
        $table->string('codigo_proyecto');
        $table->string('cuenta_origen');
        $table->string('cuenta_destino');
        $table->decimal('monto', 18, 2);
        $table->string('descripcion')->nullable();
        $table->enum('estado', ['pendiente', 'generado', 'cargado'])->default('pendiente');
        $table->foreignId('user_operativo_id')->constrained('users');
        $table->foreignId('user_contable_id')->nullable()->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimiento_contable');
    }
};
