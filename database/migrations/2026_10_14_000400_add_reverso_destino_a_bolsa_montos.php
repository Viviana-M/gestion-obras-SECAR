<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destino de la contrapartida del reverso de "reversado de más" (reclasificación 14→14). Se guarda
 * como token: "cuenta:XXXX" para reclasificar a otra cuenta 1420 CON saldo de la MISMA U.N., o
 * "un:YYYY" para llevarlo a la misma cuenta en OTRA U.N. El plano del mes arma el asiento con esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bolsa_montos', 'reverso_destino')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->string('reverso_destino')->nullable()->after('reverso_solicitado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bolsa_montos', 'reverso_destino')) {
            Schema::table('bolsa_montos', function (Blueprint $table) {
                $table->dropColumn('reverso_destino');
            });
        }
    }
};
