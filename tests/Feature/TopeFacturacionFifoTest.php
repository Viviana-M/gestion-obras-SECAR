<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TopeFacturacionFifoTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cuentaMayor, float $er, int $mes, int $anio, string $cc = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Obra FIFO',
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /**
     * Escenario: facturado del mes 1000 (costo del mes 0 => tope 1000).
     * Pendiente cuenta 14: 600 en 2026-05 (más antiguo) y 800 en 2026-06 (más nuevo) = 1400 > tope.
     * FIFO al tope 1000 => 600 (mayo, completo) + 400 (junio, recortado).
     */
    private function seedFifo(): void
    {
        $this->rf('Ingreso', 1000, 7, 2026, '41350100');            // facturado del mes
        $this->rf('Costos por aplicar', -600, 5, 2026, '14350105'); // pendiente antiguo
        $this->rf('Costos por aplicar', -800, 6, 2026, '14350206'); // pendiente nuevo
    }

    #[Test]
    public function la_propuesta_inicial_se_topa_al_facturado_y_reparte_fifo(): void
    {
        $this->seedFifo();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        // La obra aparece (tiene saldo neto en cuenta 14)
        $resp->assertSee('id="card-C-700"', false);
        // Reparto FIFO topado: 600 al saldo antiguo, 400 al nuevo (no 800).
        $resp->assertSee('data-tope="600"', false);
        $resp->assertSee('data-tope="400"', false);
        // El pendiente completo (800) NO se propone en el saldo nuevo.
        $resp->assertDontSee('data-tope="800"', false);
    }

    #[Test]
    public function guardar_recorta_al_tope_consumiendo_lo_mas_antiguo(): void
    {
        $this->seedFifo();

        // El formulario intenta aplicar el pendiente completo (600 + 800 = 1400 > tope 1000).
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 600, '14350206' => 800]],
        ])->assertRedirect();

        // Se recorta al tope 1000: 600 (mayo, completo) + 400 (junio, recortado).
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(400, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->where('cuenta_14', '14350206')->sum('monto_aplicar'), 0.5);
        // Total aplicado = tope.
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function guardar_respeta_montos_dentro_del_tope(): void
    {
        $this->seedFifo();

        // Dentro del tope (300 + 200 = 500 <= 1000): se respeta tal cual.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 300, '14350206' => 200]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(300, (float) AplicacionCosto::where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(200, (float) AplicacionCosto::where('cuenta_14', '14350206')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function sin_facturacion_del_mes_el_tope_es_cero_para_proyecto_con_costo_ya_aplicado(): void
    {
        // Facturado 500, pero ya se aplicó 500 en el mes => tope 0: no debe entrar nada nuevo.
        $this->rf('Ingreso', 500, 7, 2026, '41350100');
        $this->rf('Costos aplicados', -500, 7, 2026, '61350100'); // ya aplicado en el mes
        $this->rf('Costos por aplicar', -900, 6, 2026, '14350206');

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350206' => 900]],
        ])->assertRedirect();

        $this->assertSame(0, AplicacionCosto::where('codigo_proyecto', 'C-700')->count());
    }
}
