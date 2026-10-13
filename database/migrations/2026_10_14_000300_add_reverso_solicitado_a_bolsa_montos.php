<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las líneas de "reversado de más" (neto negativo de una (U.N., cuenta)) pueden marcarse en el detalle
 * con "Solicitar reverso a contabilidad": operaciones pide que contabilidad las reverse ESE mismo mes.
 * Al guardar, esa marca se persiste aquí (reverso_solicitado) y la observación de la línea lleva la
 * U.N. destino de la contrapartida (reclasificación 14→14). El plano del mes genera el asiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bolsa_montos', 'reverso_solicitado')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->boolean('reverso_solicitado')->default(false)->after('documento');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bolsa_montos', 'reverso_solicitado')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->dropColumn('reverso_solicitado');
            });
        }
    }
};
