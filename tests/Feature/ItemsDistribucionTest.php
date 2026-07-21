<?php

namespace Tests\Feature;

use App\Models\Homologacion;
use App\Models\ItemDistribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Detalle de ítems INLINE bajo cada fila de cuenta 14 (conciliación contra el saldo
 * de la cuenta 14, con ítems acumulados al mes filtrado).
 */
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

    /** Homologa la cuenta 14 con su cuenta 61 (para que la fila 14→61 se pinte y cruce ítems). */
    private function homologar(): void
    {
        Homologacion::create([
            'cuenta_14' => '14350105', 'cuenta_61' => '73950505',
            'nombre' => 'Materiales', 'estructura' => 'EQU-MAT-SUM', 'vigente_desde' => 200001,
        ]);
    }

    #[Test]
    public function el_detalle_se_despliega_inline_y_cuadra_contra_la_cuenta_14(): void
    {
        $this->homologar();
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        // Saldo pendiente de la cuenta 14 = 600.000 (neto de ítems, nada reclasificado aún).
        $this->rf('C-700', 'Costos por aplicar', -600000, 6, 2026, '14350105');

        // Dos salidas (suman) y un reintegro (resta): neto 500+300−200 = 600.000.
        $this->item(['item' => 'Cemento', 'costo' => 500000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Arena', 'costo' => 300000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Cemento devuelto', 'codigo_movimiento' => '15',
            'tipo_movimiento' => 'Reintegro salida directa', 'naturaleza' => 'Crédito', 'costo' => 200000]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        // Ya NO existe la sección separada; el detalle va inline bajo la fila de la cuenta 14.
        $resp->assertDontSee('Detalle de ítems por cuenta', false);
        // La fila expandible de la cuenta 14 y su detalle inline.
        $resp->assertSee('14350105', false);          // fila de la cuenta 14
        $resp->assertSee('caret-itc', false);          // el ▸ para expandir
        $resp->assertSee('Cemento', false);
        $resp->assertSee('FERRETERIA X', false);
        $resp->assertSee('Reasignar', false);
        $resp->assertSee('Suma de ítems', false);
        $resp->assertSee('Saldo de la cuenta 14', false);
        // Ítems pendientes 600.000 == saldo cuenta 14 600.000 → cuadra.
        $resp->assertSee('Cuadra con la cuenta 14', false);
        // El reintegro se muestra con signo −.
        $resp->assertSee('−$200.000', false);
    }

    #[Test]
    public function marca_la_diferencia_cuando_no_cuadra_con_la_cuenta_14(): void
    {
        $this->homologar();
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        // Saldo cuenta 14 = 1.000.000, pero los ítems solo suman 600.000 → dif 400.000.
        $this->rf('C-700', 'Costos por aplicar', -1000000, 6, 2026, '14350105');

        $this->item(['item' => 'Cemento', 'costo' => 500000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Arena', 'costo' => 300000, 'naturaleza' => 'Débito']);
        $this->item(['item' => 'Cemento devuelto', 'codigo_movimiento' => '15',
            'tipo_movimiento' => 'Reintegro salida directa', 'naturaleza' => 'Crédito', 'costo' => 200000]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertDontSee('Cuadra con la cuenta 14', false);
        $resp->assertSee('Diferencia — revisar', false);
        // Diferencia = 1.000.000 − 600.000 = 400.000.
        $resp->assertSee('$400.000', false);
    }

    #[Test]
    public function acumula_los_items_hasta_el_mes_filtrado_y_excluye_los_posteriores(): void
    {
        $this->homologar();
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -600000, 6, 2026, '14350105');

        // Ítem de un mes anterior (junio): SÍ entra en el acumulado a julio.
        $this->item(['item' => 'ItemJunio', 'mes' => 6, 'anio' => 2026, 'costo' => 111111]);
        // Ítem del mes filtrado (julio): entra.
        $this->item(['item' => 'ItemJulio', 'mes' => 7, 'anio' => 2026, 'costo' => 222222]);
        // Ítem de un mes posterior (agosto): NO entra.
        $this->item(['item' => 'ItemAgosto', 'mes' => 8, 'anio' => 2026, 'costo' => 999999]);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('ItemJunio', false);   // acumulado (mes anterior)
        $resp->assertSee('ItemJulio', false);
        $resp->assertDontSee('ItemAgosto', false); // mes posterior, excluido por el corte
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
