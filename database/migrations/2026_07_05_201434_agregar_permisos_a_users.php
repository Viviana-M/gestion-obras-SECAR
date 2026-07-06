<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'modulos_permitidos')) {
                $table->json('modulos_permitidos')->nullable()->after('rol');
            }
            if (!Schema::hasColumn('users', 'activo')) {
                $table->boolean('activo')->default(true)->after('modulos_permitidos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'modulos_permitidos')) {
                $table->dropColumn('modulos_permitidos');
            }
            if (Schema::hasColumn('users', 'activo')) {
                $table->dropColumn('activo');
            }
        });
    }
};