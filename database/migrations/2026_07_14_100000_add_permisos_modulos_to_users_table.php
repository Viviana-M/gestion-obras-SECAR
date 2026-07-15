<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Permisos por modulo con DOS niveles: ver | editar.
 * Convierte a cada usuario existente de "ver" a "editar" para NO quitarle acceso a nadie.
 */
return new class extends Migration
{
    private array $departamentos = ['dep_mantenimiento', 'dep_instalaciones'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permisos_modulos')->nullable()->after('modulos_permitidos');
        });

        foreach (DB::table('users')->get() as $u) {
            if ($u->rol === 'admin') continue;

            $viejos = $this->decodificar($u->modulos_permitidos);
            if (empty($viejos)) {
                $viejos = $this->modulosLegado($u->rol);
            }

            $mapa = [];
            foreach ($viejos as $modulo) {
                $mapa[$modulo] = in_array($modulo, $this->departamentos, true) ? 'ver' : 'editar';
            }

            DB::table('users')->where('id', $u->id)->update([
                'permisos_modulos' => json_encode($mapa),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permisos_modulos');
        });
    }

    private function decodificar($valor): array
    {
        if (is_array($valor)) return $valor;
        if (is_string($valor)) {
            $d = json_decode($valor, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    private function modulosLegado(?string $rol): array
    {
        return [
            'financiero' => ['gestion_financiera'],
            'operativo'  => ['operacion'],
            'comercial'  => ['comercial'],
            'contable'   => ['contabilidad'],
        ][$rol] ?? [];
    }
};