<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Política de costeo (decisión de contabilidad): se distribuye TODO el inventario 1420, también en
 * obras SIN ingreso del mes. Antes se saltaban y el borrador quedaba incompleto.
 */
class InventarioSinIngresoTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    /** Obra CON inventario 1420 pendiente pero SIN ingreso del mes. */
    private function seedSinIngreso(string $cod, float $saldo = -2_000_000): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => $saldo, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
    }

    #[Test]
    public function una_obra_sin_ingreso_ahora_persiste_su_inventario_al_guardar(): void
    {
        $this->seedSinIngreso('C-9');

        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-9' => ['14200105' => 2_000_000]],
            'estado_obra' => ['C-9' => 'parcial'],
            'obras_editadas' => ['C-9'], 'obras_base' => ['C-9' => 0],
        ])->assertOk()->assertJson(['ok' => true]);

        // Antes se saltaba (sin ingreso → 0). Ahora se persiste el inventario completo.
        $this->assertEqualsWithDelta(2_000_000, AplicacionCosto::where('codigo_proyecto', 'C-9')->sum('monto_aplicar'), 1);
    }

    #[Test]
    public function el_input_de_una_obra_sin_ingreso_queda_editable(): void
    {
        $this->seedSinIngreso('C-9');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="aplicar[C-9][14200105]"', $html);  // la obra se lista
        // El input de C-9 ya NO está bloqueado ni readonly.
        $this->assertMatchesRegularExpression('/name="aplicar\[C-9\]\[14200105\]"[^>]*data-bloqueado="0"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="aplicar\[C-9\]\[14200105\]"[^>]*\breadonly\b/s', $html);
    }

    #[Test]
    public function entrar_a_un_borrador_nuevo_y_guardar_incluye_las_sin_ingreso(): void
    {
        // Mezcla: C-1 con ingreso, C-9 sin ingreso. Ambas tienen inventario 1420.
        RegistroFinanciero::create(['codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra C-1', 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 5_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra C-1', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        $this->seedSinIngreso('C-9', -2_000_000);

        // Guardado "completo" (primer guardado, sin merge): persiste ambas, con y sin ingreso.
        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 1_000_000], 'C-9' => ['14200105' => 2_000_000]],
            'estado_obra' => ['C-1' => 'parcial', 'C-9' => 'parcial'],
        ])->assertOk()->assertJson(['ok' => true]);

        // Total = inventario completo (con + sin ingreso).
        $this->assertEqualsWithDelta(3_000_000, AplicacionCosto::sum('monto_aplicar'), 1);
    }
}
