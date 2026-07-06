<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribucion_envios', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('mes');
            $table->unsignedSmallInteger('anio');
            $table->string('estado')->default('borrador'); // borrador | enviado
            $table->boolean('edicion_habilitada')->default(false);
            $table->timestamp('enviado_at')->nullable();
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->timestamps();
            $table->unique(['mes', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribucion_envios');
    }
};