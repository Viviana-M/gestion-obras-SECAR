<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribucion_versiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distribucion_id');
            $table->string('evento');            // guardado, enviado, reabierto, reenviado
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre')->nullable();
            $table->json('snapshot')->nullable(); // foto de los números en ese momento
            $table->timestamps();

            $table->index('distribucion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribucion_versiones');
    }
};