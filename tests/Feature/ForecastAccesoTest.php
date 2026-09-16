<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForecastAccesoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function el_admin_ve_el_enlace_de_proyeccion_de_obras_y_abre_la_pantalla(): void
    {
        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);

        $resp = $this->actingAs($admin)->get(route('operativo.forecast'));

        $resp->assertOk();
        // El enlace vive en el menú Administración (se ve en cualquier página con el layout).
        $resp->assertSee('Proyección de obras', false);
        $resp->assertSee(route('operativo.forecast'), false);
    }

    #[Test]
    public function un_usuario_sin_operacion_no_ve_el_enlace(): void
    {
        // El enlace está dentro del bloque solo-admin; un no-admin no lo ve.
        $contable = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'ver'],
        ]);

        $resp = $this->actingAs($contable)->get(route('contable.autoliquidacion.index'));
        $resp->assertOk();
        $resp->assertDontSee('Proyección de obras', false);
    }
}
