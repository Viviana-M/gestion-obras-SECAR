<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // La columna 'rol' estaba limitada a una lista fija de valores (ENUM).
        // La convertimos en texto libre para que acepte 'usuario' y cualquier rol futuro.
        DB::statement("ALTER TABLE users MODIFY rol VARCHAR(30) NOT NULL DEFAULT 'usuario'");
    }

    public function down(): void
    {
        // No revertimos a la lista fija anterior para no perder los roles nuevos.
    }
};