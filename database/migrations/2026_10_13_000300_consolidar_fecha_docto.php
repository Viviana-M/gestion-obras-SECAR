<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolida la fecha del documento en UNA sola columna: fecha_docto. En algunos entornos quedó
 * también una columna duplicada fecha_dcto (por el historial de migraciones). Aquí se asegura que
 * fecha_docto exista, se traspasa cualquier dato de fecha_dcto a fecha_docto y se elimina fecha_dcto.
 * Idempotente: en una base nueva (solo fecha_docto) no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('registro_financieros', 'fecha_docto')) {
            Schema::table('registro_financieros', function (Blueprint $table) {
                $table->date('fecha_docto')->nullable()->after('documento');
            });
        }

        if (Schema::hasColumn('registro_financieros', 'fecha_dcto')) {
            // No perder datos: lo que tenga fecha_dcto y le falte a fecha_docto, se traspasa.
            DB::table('registro_financieros')
                ->whereNull('fecha_docto')->whereNotNull('fecha_dcto')
                ->update(['fecha_docto' => DB::raw('fecha_dcto')]);

            Schema::table('registro_financieros', function (Blueprint $table) {
                $table->dropColumn('fecha_dcto');
            });
        }
    }

    public function down(): void
    {
        // No se recrea fecha_dcto: la columna canónica es fecha_docto.
    }
};
