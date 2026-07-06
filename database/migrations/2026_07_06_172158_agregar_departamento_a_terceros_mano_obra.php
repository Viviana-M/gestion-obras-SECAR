<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terceros_mano_obra', function (Blueprint $table) {
            if (!Schema::hasColumn('terceros_mano_obra', 'departamento')) {
                $table->string('departamento', 20)->default('mantenimiento')->after('nombre');
            }
        });

        // Las 4 que ya existen son de mantenimiento
        DB::table('terceros_mano_obra')->update(['departamento' => 'mantenimiento']);
    }

    public function down(): void
    {
        Schema::table('terceros_mano_obra', function (Blueprint $table) {
            if (Schema::hasColumn('terceros_mano_obra', 'departamento')) {
                $table->dropColumn('departamento');
            }
        });
    }
};