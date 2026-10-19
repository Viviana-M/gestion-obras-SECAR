<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `proyectos_cerrados.origen` pasa de enum('excel','forecast','manual') a string, para admitir
 * nuevos orígenes de cierre sin tocar la tabla cada vez. En particular 'masivo', usado por el
 * cierre masivo de "Obras en revisión" (enviar todas a cierre de una vez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyectos_cerrados', function (Blueprint $table) {
            $table->string('origen', 30)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('proyectos_cerrados', function (Blueprint $table) {
            $table->enum('origen', ['excel', 'forecast', 'manual'])->default('manual')->change();
        });
    }
};
