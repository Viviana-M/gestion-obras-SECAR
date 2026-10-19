<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            // Una versión enviada queda "reemplazada" cuando se reenvía otra del mismo
            // mes/año/departamento. El plano solo considera la vigente (reemplazada = false).
            $table->boolean('reemplazada')->default(false)->after('estado')->index();
        });
    }

    public function down(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            $table->dropColumn('reemplazada');
        });
    }
};
