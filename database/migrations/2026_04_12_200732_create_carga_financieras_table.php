<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carga_financieras', function (Blueprint $table) {
            $table->id();
            $table->integer('mes');
            $table->integer('anio');
            $table->string('archivo_original');
            $table->string('ruta_archivo');
            $table->enum('estado', ['procesando', 'completado', 'error'])
                  ->default('procesando');
            $table->integer('registros')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carga_financieras');
    }
};