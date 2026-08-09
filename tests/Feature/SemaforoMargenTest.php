<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Semáforo de márgenes por departamento:
 *  Mantenimiento: verde ≥30, amarillo ≥27, rojo ≥25, gris <25.
 *  Instalaciones: verde ≥23, amarillo ≥20, rojo ≥18, gris <18.
 */
class SemaforoMargenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function los_niveles_de_mantenimiento_respetan_los_umbrales(): void
    {
        $this->assertSame('verde',    DistribucionService::nivelMargen(30, 'mantenimiento'));
        $this->assertSame('verde',    DistribucionService::nivelMargen(35, 'mantenimiento'));
        $this->assertSame('amarillo', DistribucionService::nivelMargen(27, 'mantenimiento'));
        $this->assertSame('amarillo', DistribucionService::nivelMargen(29.9, 'mantenimiento'));
        $this->assertSame('rojo',     DistribucionService::nivelMargen(25, 'mantenimiento'));
        $this->assertSame('rojo',     DistribucionService::nivelMargen(26.9, 'mantenimiento'));
        $this->assertSame('gris',     DistribucionService::nivelMargen(24, 'mantenimiento'));
        $this->assertSame('gris',     DistribucionService::nivelMargen(-5, 'mantenimiento'));
    }

    #[Test]
    public function los_niveles_de_instalaciones_respetan_los_umbrales(): void
    {
        $this->assertSame('verde',    DistribucionService::nivelMargen(23, 'instalaciones'));
        $this->assertSame('amarillo', DistribucionService::nivelMargen(20, 'instalaciones'));
        $this->assertSame('amarillo', DistribucionService::nivelMargen(22.9, 'instalaciones'));
        $this->assertSame('rojo',     DistribucionService::nivelMargen(18, 'instalaciones'));
        $this->assertSame('gris',     DistribucionService::nivelMargen(17, 'instalaciones'));
    }

    #[Test]
    public function sin_margen_o_sin_departamento_es_gris(): void
    {
        $this->assertSame('gris', DistribucionService::nivelMargen(null, 'mantenimiento'));
        $this->assertSame('gris', DistribucionService::nivelMargen(30, null));
    }

    #[Test]
    public function el_departamento_se_deduce_del_prefijo_del_codigo(): void
    {
        $this->assertSame('mantenimiento', User::departamentoDeCodigo('C-100'));
        $this->assertSame('mantenimiento', User::departamentoDeCodigo('MO4501'));
        $this->assertSame('mantenimiento', User::departamentoDeCodigo('GM000045'));
        $this->assertSame('instalaciones', User::departamentoDeCodigo('GI200'));
        $this->assertSame('instalaciones', User::departamentoDeCodigo('O-300'));
        $this->assertNull(User::departamentoDeCodigo('XX-999'));
    }

    #[Test]
    public function la_tarjeta_pinta_el_margen_del_mes_con_el_color_del_semaforo(): void
    {
        // Obra de mantenimiento con ingreso 1.000.000 y sin costo → margen mes = 100% → verde.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-777', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 1000000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-777', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14350105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -100, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);

        $op = User::factory()->create(['rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar']]);

        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertStatus(200);
        // La leyenda del semáforo está presente.
        $resp->assertSee('Semáforo de margen', false);
        // El % del mes es un badge verde (fondo #16A34A).
        $resp->assertSee('id="mcpct-C-777"', false);
        $resp->assertSee('background:#16A34A', false);
    }
}
