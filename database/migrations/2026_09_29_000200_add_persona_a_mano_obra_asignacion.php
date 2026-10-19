<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mano de obra directa por persona: se agrega `persona` (cédula del maestro de mano de obra
 * directa) para agrupar la asignación por PERSONA, distinta del `tercero` SIESA de cada línea
 * (la persona en el salario, el fondo/EPS en la seguridad social).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mano_obra_asignacion', function (Blueprint $table) {
            $table->string('persona', 120)->nullable()->after('cuenta_14')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mano_obra_asignacion', function (Blueprint $table) {
            $table->dropColumn('persona');
        });
    }
};
