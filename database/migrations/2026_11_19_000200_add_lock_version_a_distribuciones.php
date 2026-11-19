<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candado optimista para la distribución: un contador que SUBE en cada guardado. El formulario trae
 * el valor con que se cargó; si al guardar el del servidor es mayor (otra persona guardó entretanto),
 * se rechaza para no pisar su trabajo. Más fiable que updated_at (que es por segundo y no cambia si
 * el guardado solo toca las líneas hijas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
