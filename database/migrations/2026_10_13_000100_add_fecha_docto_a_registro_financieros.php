<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha del documento (columna "Fecha_docto" del archivo de cierre, en formato AAAAMMDD). Sirve para
 * mostrar la fecha del movimiento y para ordenar el cruce FIFO de SECAR (lo más antiguo primero) en
 * el detalle por cuenta de las bolsas. Nullable: los registros ya cargados quedan sin fecha hasta
 * recargar; el cruce cae entonces al período (anio*100+mes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->date('fecha_docto')->nullable()->after('documento');
        });
    }

    public function down(): void
    {
        Schema::table('registro_financieros', function (Blueprint $table) {
            $table->dropColumn('fecha_docto');
        });
    }
};
