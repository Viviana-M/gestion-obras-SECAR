<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

/**
 * Operaciones trabaja desde el TOTAL: al cargar la distribución de la cuenta 14 se
 * precarga el saldo pendiente COMPLETO de cada cuenta (ellos ajustan hacia abajo), y al
 * guardar se respeta lo que dejen, con el único límite del saldo abierto de cada cuenta
 * (ya NO se topa al facturado del mes).
 */
class PrecargaDistribucionTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

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
            'codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Obra',
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function sembrar(): void
    {
        // Facturado del mes 1000 (ya no importa para el tope), pendiente 600 + 800 = 1400.
        $this->rf('Ingreso', 1000, 7, 2026, '41350100');
        $this->rf('Costos por aplicar', -600, 5, 2026, '14350105');
        $this->rf('Costos por aplicar', -800, 6, 2026, '14350206');
    }

    #[Test]
    public function la_distribucion_precarga_el_saldo_pendiente_completo(): void
    {
        $this->sembrar();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('id="card-C-700"', false);
        // Se precarga el total de cada cuenta (600 y 800), sin toparlo al facturado (1000).
        $resp->assertSee('data-tope="600"', false);
        $resp->assertSee('data-tope="800"', false);
        // El input arranca con el saldo completo.
        $resp->assertSee('value="600"', false);
        $resp->assertSee('value="800"', false);
    }

    #[Test]
    public function la_precarga_nunca_excede_el_saldo_neto_abierto(): void
    {
        // Bruto 2.000.000 con una reversa de 714.393 => neto abierto 1.285.607.
        $this->rf('Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('Costos por aplicar', -2000000, 4, 2026, '14200506');
        $this->rf('Costos por aplicar', 714393, 5, 2026, '14200506');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('data-tope="1285607"', false);
        $resp->assertDontSee('data-tope="2000000"', false);
    }

    #[Test]
    public function guardar_respeta_lo_que_deja_operaciones_sin_topar_al_facturado(): void
    {
        $this->sembrar(); // facturado 1000, pero pendiente 600 + 800 = 1400

        // Operaciones deja el total (1400 > 1000 facturado): se guarda completo.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 600, '14350206' => 800]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(800, (float) AplicacionCosto::where('cuenta_14', '14350206')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(1400, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function guardar_no_aplica_mas_que_el_saldo_de_la_cuenta(): void
    {
        $this->sembrar(); // 14350105 con saldo 600

        // Aunque manden 9999, se recorta al saldo abierto de la cuenta (600).
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 9999]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function guardar_aplica_aunque_no_haya_cupo_de_facturado(): void
    {
        // Facturado 500 ya consumido por costo aplicado 500 (antes: tope 0 => 0 aplicado).
        // Ahora, al haber ingreso, se aplica el pendiente hasta el saldo (900).
        $this->rf('Ingreso', 500, 7, 2026, '41350100');
        $this->rf('Costos aplicados', -500, 7, 2026, '61350100');
        $this->rf('Costos por aplicar', -900, 6, 2026, '14350206');

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350206' => 900]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(900, (float) AplicacionCosto::where('cuenta_14', '14350206')->sum('monto_aplicar'), 0.5);
    }
}
