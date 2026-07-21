<?php

namespace Tests\Feature;

use App\Models\ItemDistribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemsDistribucionTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cm, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cm,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function item(array $attrs): void
    {
        ItemDistribucion::create(array_merge([
            'codigo_obra' => 'C-700', 'mes' => 7, 'anio' => 2026, 'cuenta' => '73950505',
            'item' => 'Cemento', 'tipo_inventario' => '01', 'codigo_movimiento' => '14',
            'tipo_movimiento' => 'Salida Directa Inventario en Obra', 'naturaleza' => 'Débito',
            'tercero' => 'FERRETERIA X', 'cantidad' => 10, 'fecha' => '2026-07-10',
            'numero_documento' => 'FAC-100', 'costo' => 500000,
        ], $attrs));
    }

    #[Test]
    public function la_obra_muestra_los_items_por_cuenta_con_neto_descontando_reintegros(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');

        // Dos salidas (suman) y un reintegro (resta) en la misma cuenta.
        $this->item(['item' => 'Cemento', 'costo' => 500000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Arena', 'costo' => 300000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Cemento devuelto', 'codigo_movimiento' => '15',
            'tipo_movimiento' => 'Reintegro salida directa', 'naturaleza' => 'Crédito', 'costo' => 200000]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Detalle de ítems por cuenta', false);
        $resp->assertSee('73950505', false);
        $resp->assertSee('Cemento', false);
        $resp->assertSee('FERRETERIA X', false);
        $resp->assertSee('Reasignar', false);
        // Neto = 500.000 + 300.000 − 200.000 = 600.000.
        $resp->assertSee('Neto $600.000', false);
        // El reintegro se muestra con signo −.
        $resp->assertSee('−$200.000', false);
    }

    #[Test]
    public function solo_trae_los_items_del_periodo_filtrado(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');

        $this->item(['item' => 'DelMes', 'mes' => 7, 'anio' => 2026, 'costo' => 111111]);
        $this->item(['item' => 'OtroMes', 'mes' => 8, 'anio' => 2026, 'costo' => 999999]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('DelMes', false);
        $resp->assertDontSee('OtroMes', false); // ítem de agosto no aparece en julio
    }

    #[Test]
    public function el_modelo_calcula_el_costo_neto_segun_la_naturaleza(): void
    {
        $salida = new ItemDistribucion(['naturaleza' => 'Débito', 'costo' => 500000]);
        $reint  = new ItemDistribucion(['naturaleza' => 'Crédito', 'costo' => 200000]);

        $this->assertFalse($salida->esReintegro());
        $this->assertEqualsWithDelta(500000, $salida->costoNeto(), 0.5);
        $this->assertTrue($reint->esReintegro());
        $this->assertEqualsWithDelta(-200000, $reint->costoNeto(), 0.5);
    }
}
