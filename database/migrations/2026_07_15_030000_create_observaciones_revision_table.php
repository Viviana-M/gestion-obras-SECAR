<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observación/justificación cuando se decide dejar ABIERTA una obra que está
 * costando pero ya no tiene saldo en la cuenta 14 (módulo de revisión).
 * Una nota por obra (la última vigente), con autor y fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observaciones_revision', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_proyecto')->unique();
            $table->text('observacion');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observaciones_revision');
    }
};
