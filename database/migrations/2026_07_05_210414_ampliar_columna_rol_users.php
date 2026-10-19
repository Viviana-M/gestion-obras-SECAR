<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La columna 'rol' estaba limitada a una lista fija de valores (ENUM).
        // La convertimos en texto libre para que acepte 'usuario' y cualquier rol futuro.
        // En MySQL el ENUM requiere ALTER ... MODIFY; en otros motores (SQLite, el default
        // de local/CI) se usa el cambio de columna portable de Laravel para no romper migrate.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY rol VARCHAR(30) NOT NULL DEFAULT 'usuario'");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('rol', 30)->default('usuario')->change();
            });
        }
    }

    public function down(): void
    {
        // No revertimos a la lista fija anterior para no perder los roles nuevos.
    }
};