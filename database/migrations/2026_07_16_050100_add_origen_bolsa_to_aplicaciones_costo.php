<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aplicaciones_costo', function (Blueprint $table) {
            // Marca la cuenta cuyo costo viene de una bolsa de área (código de la UN de
            // origen). Null = aplicación normal de la cuenta 14 propia de la obra.
            // Sirve para (1) no repoblar por error los inputs de la obra al recargar y
            // (2) que el plano acredite la cuenta 14 en la OT de la bolsa, no en la obra.
            $table->string('origen_bolsa', 30)->nullable()->after('cuenta_14')->index();
        });
    }

    public function down(): void
    {
        Schema::table('aplicaciones_costo', function (Blueprint $table) {
            $table->dropColumn('origen_bolsa');
        });
    }
};
