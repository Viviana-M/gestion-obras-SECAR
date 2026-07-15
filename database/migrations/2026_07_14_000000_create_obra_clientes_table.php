<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obra_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto')->unique();
            $table->string('nit', 30)->nullable();
            $table->string('razon_social')->nullable();
            $table->enum('origen', ['derivado', 'manual'])->default('derivado');
            $table->boolean('revisar')->default(false);
            $table->string('motivo_revision')->nullable();
            $table->json('candidatos')->nullable();
            $table->decimal('facturado', 18, 2)->default(0);
            $table->timestamp('derivado_at')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
            $table->index('nit');
            $table->index('revisar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obra_clientes');
    }
};