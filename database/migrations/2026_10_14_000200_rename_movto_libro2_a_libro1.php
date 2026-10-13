<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Todo el sistema trabaja en Libro 1 (PCGA = débito − crédito). La columna histórica se llamaba
 * `movto_libro2` pero desde hace tiempo guarda el valor de Libro 1 (no el libro 2 NIIF). Se renombra
 * a `movto_libro1` para que el nombre refleje lo que contiene. El valor NO cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('registro_financieros', 'movto_libro2')
            && ! Schema::hasColumn('registro_financieros', 'movto_libro1')) {
            Schema::table('registro_financieros', function (Blueprint $table) {
                $table->renameColumn('movto_libro2', 'movto_libro1');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('registro_financieros', 'movto_libro1')
            && ! Schema::hasColumn('registro_financieros', 'movto_libro2')) {
            Schema::table('registro_financieros', function (Blueprint $table) {
                $table->renameColumn('movto_libro1', 'movto_libro2');
            });
        }
    }
};
