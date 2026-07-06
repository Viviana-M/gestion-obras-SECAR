<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obras_estado', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto')->unique();
            $table->string('estado')->default('abierta'); // abierta | parcial | cerrada
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obras_estado');
    }
};