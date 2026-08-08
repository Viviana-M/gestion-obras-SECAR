<?php

namespace Tests\Feature;

use App\Models\Distribucion;
use App\Models\DistribucionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La distribución final (enviada) queda consultable en "Mis distribuciones" con un
 * acceso directo a la FOTO CONGELADA del envío, que no cambia aunque cambien los datos.
 */
class DistribucionConsultaFinalTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    #[Test]
    public function la_enviada_muestra_boton_ver_final_congelada(): void
    {
        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'version' => 1,
            'estado' => 'enviado', 'edicion_habilitada' => false, 'enviado_at' => now(),
        ]);
        $ver = DistribucionVersion::create([
            'distribucion_id' => $dist->id, 'evento' => 'enviado',
            'snapshot' => ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento'],
        ]);

        $resp = $this->actingAs($this->operador())->get(route('operativo.distribucion.consultas'));

        $resp->assertOk();
        $resp->assertSee('Ver final (congelada)', false);
        // Apunta a la foto congelada de la versión enviada.
        $resp->assertSee(route('operativo.distribucion.version', $ver->id), false);
    }

    #[Test]
    public function un_borrador_sin_envio_no_muestra_ver_final(): void
    {
        Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'version' => 1,
            'estado' => 'borrador', 'edicion_habilitada' => false,
        ]);

        $this->actingAs($this->operador())->get(route('operativo.distribucion.consultas'))
            ->assertOk()
            ->assertDontSee('Ver final (congelada)', false);
    }

    #[Test]
    public function la_foto_congelada_se_ve_en_solo_lectura(): void
    {
        $dist = Distribucion::create([
            'mes' => 6, 'anio' => 2026, 'departamento' => 'instalaciones', 'version' => 1,
            'estado' => 'enviado', 'edicion_habilitada' => false, 'enviado_at' => now(),
        ]);
        $ver = DistribucionVersion::create([
            'distribucion_id' => $dist->id, 'evento' => 'enviado',
            'snapshot' => ['mes' => 6, 'anio' => 2026, 'departamento' => 'instalaciones',
                'tabla' => [], 'todo' => ['ingreso' => 0, 'cat' => [], 'costo_total' => 0, 'mc_pesos' => 0, 'mc_pct' => null],
                'tipos' => [], 'categorias' => []],
        ]);

        $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.version', $ver->id))
            ->assertOk();
    }
}
