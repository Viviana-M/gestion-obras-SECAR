<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columna "Activa" del maestro de proyectos: permite marcar una obra como inactiva para
 * cerrarla en Distribución. El cierre respeta el saldo de la cuenta 14 (no cierra si tiene
 * saldo pendiente; la deja para revisión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ficha_proyectos', function (Blueprint $table) {
            $table->boolean('activa')->default(true)->after('origen');
        });
    }

    public function down(): void
    {
        Schema::table('ficha_proyectos', function (Blueprint $table) {
            $table->dropColumn('activa');
        });
    }
};
