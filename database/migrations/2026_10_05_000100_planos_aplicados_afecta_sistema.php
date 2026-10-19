<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control explícito de qué planos afectan la cuenta 14 del sistema (chulito "Afectar el sistema con
 * estos movimientos"). Se guarda por plano aplicado: si afecta, quién lo marcó, cuándo y con qué
 * documento (el CCC con que se contabiliza en el ERP), para auditar y para deduplicar en recargas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos_aplicados', function (Blueprint $table) {
            $table->boolean('afecta_sistema')->default(true)->after('tipo');
            $table->unsignedBigInteger('afectado_por')->nullable()->after('user_id');
            $table->timestamp('afectado_en')->nullable()->after('afectado_por');
            $table->string('documento_ccc', 40)->nullable()->after('numero_documento');
        });
    }

    public function down(): void
    {
        Schema::table('planos_aplicados', function (Blueprint $table) {
            $table->dropColumn(['afecta_sistema', 'afectado_por', 'afectado_en', 'documento_ccc']);
        });
    }
};
