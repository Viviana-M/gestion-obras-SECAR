<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homologaciones', function (Blueprint $table) {
            $table->id();
            $table->string('cuenta_14')->unique();   // llave de búsqueda
            $table->string('cuenta_61');             // contracuenta fija
            $table->string('nombre')->nullable();
            $table->string('estructura')->nullable(); // EQU-MAT-SUM, MOI, etc.
            $table->string('origen')->default('manual'); // excel | manual
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homologaciones');
    }
};