<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

class TopeFacturacionFifoTest extends TestCase
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
    public function la_propuesta_inicial_aplica_saldos_completos_por_antiguedad_sin_exceder_el_tope(): void
    {
        $this->seedFifo();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('id="card-C-700"', false);
        // Tope 1000: cabe completo el saldo antiguo (600); el nuevo (800) NO cabe entero,
        // así que NO se aplica (no se parte para no dejar poquitos).
        $resp->assertSee('data-tope="600"', false);
        $resp->assertSee('data-tope="0"', false);      // el saldo nuevo queda sin proponer
        $resp->assertDontSee('data-tope="400"', false); // no hay recorte parcial (poquito)
        $resp->assertDontSee('data-tope="800"', false); // ni se propone el saldo nuevo entero
    }

    #[Test]
    public function la_propuesta_nunca_excede_el_saldo_abierto_neto(): void
    {
        // Cuenta con período que se reversa: bruto 2.000.000, pero neto abierto 1.285.607.
        $this->rf('Ingreso', 5000000, 7, 2026, '41350100'); // tope muy alto
        $this->rf('Costos por aplicar', -2000000, 4, 2026, '14200506'); // pendiente bruto
        $this->rf('Costos por aplicar', 714393, 5, 2026, '14200506');   // reversa parcial => neto 1.285.607

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        // Propone como máximo el saldo neto abierto (1.285.607), nunca el bruto (2.000.000).
        $resp->assertSee('data-tope="1285607"', false);
        $resp->assertDontSee('data-tope="2000000"', false);
    }

    #[Test]
    public function guardar_aplica_saldos_completos_por_antiguedad_sin_exceder_el_tope(): void
    {
        $this->seedFifo();

        // El formulario intenta aplicar el pendiente completo (600 + 800 = 1400 > tope 1000).
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 600, '14350206' => 800]],
        ])->assertRedirect();

        // Cabe el saldo antiguo completo (600); el nuevo (800) no cabe entero => no se aplica.
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
        $this->assertSame(0, AplicacionCosto::where('codigo_proyecto', 'C-700')->where('cuenta_14', '14350206')->count());
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('codigo_proyecto', 'C-700')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function salta_la_cuenta_que_no_cabe_y_sigue_distribuyendo_lo_que_si_cabe(): void
    {
        // Tope 1000. Antiguo 600 (cabe), siguiente 800 (no cabe en 400 restante),
        // último 100 (cabe). Debe aplicar 600 + 100 = 700 (salta el 800, no corta).
        $this->rf('Ingreso', 1000, 7, 2026, '41350100');
        $this->rf('Costos por aplicar', -600, 4, 2026, '14350104');
        $this->rf('Costos por aplicar', -800, 5, 2026, '14350205');
        $this->rf('Costos por aplicar', -100, 6, 2026, '14350306');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertStatus(200);
        $resp->assertSee('data-tope="600"', false);   // antiguo, completo
        $resp->assertSee('data-tope="100"', false);   // se sigue distribuyendo
        $resp->assertDontSee('data-tope="800"', false); // el que no cabe queda abierto

        // guardar: mismo comportamiento (600 + 100, salta 800).
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350104' => 600, '14350205' => 800, '14350306' => 100]],
        ])->assertRedirect();
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('cuenta_14', '14350104')->sum('monto_aplicar'), 0.5);
        $this->assertSame(0, AplicacionCosto::where('cuenta_14', '14350205')->count());
        $this->assertEqualsWithDelta(100, (float) AplicacionCosto::where('cuenta_14', '14350306')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function guardar_no_aplica_mas_que_el_saldo_abierto_de_la_cuenta(): void
    {
        $this->seedFifo(); // 14350105 pendiente 600

        // Se manipula el formulario para aplicar 9999 en una cuenta cuyo saldo abierto es 600.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 9999]],
        ])->assertRedirect();

        // Se recorta al saldo abierto (600), nunca más.
        $this->assertEqualsWithDelta(600, (float) AplicacionCosto::where('cuenta_14', '14350105')->sum('monto_aplicar'), 0.5);
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
