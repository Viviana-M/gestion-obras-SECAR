<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            if (!Schema::hasColumn('distribuciones', 'departamento')) {
                // 'mantenimiento' o 'instalaciones'
                $table->string('departamento', 20)->nullable()->after('anio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('distribuciones', function (Blueprint $table) {
            if (Schema::hasColumn('distribuciones', 'departamento')) {
                $table->dropColumn('departamento');
            }
        });
    }
};