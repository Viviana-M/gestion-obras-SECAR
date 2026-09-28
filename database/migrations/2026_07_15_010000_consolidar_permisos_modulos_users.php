<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolida el modelo de permisos a una sola columna (permisos_modulos) y
 * elimina la columna vieja (modulos_permitidos), que quedaba como segunda
 * fuente de verdad. Antes de borrarla, migra a cualquier usuario que todavía
 * dependiera de ella.
 */
return new class extends Migration
{
    private array $departamentos = ['dep_mantenimiento', 'dep_instalaciones'];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'modulos_permitidos')) {
            return; // ya consolidado
        }

        foreach (DB::table('users')->get() as $u) {
            if ($u->rol === 'admin') {
                continue; // los admin no usan permisos por modulo
            }

            // Si ya tiene permisos nuevos, no se toca.
            if (! empty($this->decodificar($u->permisos_modulos ?? null))) {
                continue;
            }

            $viejos = $this->decodificar($u->modulos_permitidos ?? null);
            if (empty($viejos)) {
                continue;
            }

            // Reconstruye igual que la migración original: departamentos -> 'ver',
            // el resto -> 'editar' (para no reducir el acceso existente).
            $mapa = [];
            foreach ($viejos as $modulo) {
                $mapa[$modulo] = in_array($modulo, $this->departamentos, true) ? 'ver' : 'editar';
            }

            DB::table('users')->where('id', $u->id)->update([
                'permisos_modulos' => json_encode($mapa),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('modulos_permitidos');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'modulos_permitidos')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('modulos_permitidos')->nullable()->after('rol');
            });
        }
    }

    private function decodificar($valor): array
    {
        if (is_array($valor)) {
            return $valor;
        }
        if (is_string($valor)) {
            $d = json_decode($valor, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
};
