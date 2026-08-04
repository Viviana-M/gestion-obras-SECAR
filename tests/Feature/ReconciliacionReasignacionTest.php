<?php

namespace Tests\Feature;

use App\Http\Controllers\Contable\PlanoContableController;
use App\Models\Homologacion;
use App\Models\ItemDistribucion;
use App\Models\ReasignacionItem;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

class ReconciliacionReasignacionTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cm, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'P '.$cod, 'cuenta_contable' => $cc,
            'cuenta_mayor' => $cm, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function item(string $obra, string $item, float $costo, string $fecha, string $nat = 'Débito'): ItemDistribucion
    {
        // La cuenta del ítem es la CUENTA 14 de la llave (reconocimiento y conciliación por la 14).
        return ItemDistribucion::create([
            'codigo_obra' => $obra, 'mes' => 7, 'anio' => 2026, 'cuenta' => '14350105', 'item' => $item,
            'tipo_inventario' => '01', 'codigo_movimiento' => '14', 'tipo_movimiento' => 'Salida',
            'naturaleza' => $nat, 'tercero' => 'X', 'cantidad' => 1, 'fecha' => $fecha,
            'numero_documento' => 'D-'.$item, 'costo' => $costo,
        ]);
    }

    // ───────── Instrucción 1: conciliación FIFO ─────────

    #[Test]
    public function al_reclasificar_parcial_reconoce_los_items_mas_antiguos_por_fifo(): void
    {
        Homologacion::create(['cuenta_14' => '14350105', 'cuenta_61' => '73950505', 'nombre' => 'MO', 'estructura' => 'MOI', 'vigente_desde' => 200001]);
        $this->rf('C-700', 'Ingreso', 9000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14350105'); // pendiente cuenta 14

        // Ítems (mismo costo total 600) por fecha ascendente.
        $this->item('C-700', 'A', 200, '2026-07-05');
        $this->item('C-700', 'B', 300, '2026-07-10');
        $this->item('C-700', 'C', 100, '2026-07-15');

        // Reclasificar 400 (14→61) de la cuenta.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14350105' => 400]],
        ])->assertRedirect();

        $a = ItemDistribucion::where('item', 'A')->first();
        $b = ItemDistribucion::where('item', 'B')->first();
        $c = ItemDistribucion::where('item', 'C')->first();

        // FIFO: A (200) completo; B (300) parcial 200; C sin reconocer.
        $this->assertTrue($a->reconocido);
        $this->assertEqualsWithDelta(200, (float) $a->monto_reconocido, 0.5);
        $this->assertFalse($b->reconocido);
        $this->assertEqualsWithDelta(200, (float) $b->monto_reconocido, 0.5);
        $this->assertEqualsWithDelta(0, (float) $c->monto_reconocido, 0.5);

        // Pendiente total = 600 − 400 = 200 (cuadra con lo que sigue en cuenta 14).
        $pendiente = ItemDistribucion::where('codigo_obra', 'C-700')->get()->sum(fn ($i) => $i->pendiente());
        $this->assertEqualsWithDelta(200, $pendiente, 0.5);
    }

    // ───────── Instrucción 2: reasignar ítem ─────────

    #[Test]
    public function reasignar_mueve_el_item_y_deja_trazabilidad(): void
    {
        $it = $this->item('MO4501', 'Cemento', 500000, '2026-07-10');

        $this->actingAs($this->operador())->post(route('operativo.items.reasignar', $it->id), [
            'destino' => 'MO4502', 'motivo' => 'Se usó en la otra obra',
        ])->assertRedirect();

        // El ítem quedó en la obra destino (baja en origen, sube en destino).
        $this->assertSame('MO4502', $it->refresh()->codigo_obra);
        // Trazabilidad.
        $this->assertDatabaseHas('reasignaciones_item', [
            'item_distribucion_id' => $it->id, 'codigo_obra_origen' => 'MO4501',
            'codigo_obra_destino' => 'MO4502', 'cuenta' => '14350105', 'costo' => 500000, 'motivo' => 'Se usó en la otra obra',
        ]);
    }

    #[Test]
    public function la_reasignacion_genera_movimiento_14_a_14_en_el_plano(): void
    {
        ReasignacionItem::create([
            'mes' => 7, 'anio' => 2026, 'codigo_obra_origen' => 'MO4501', 'codigo_obra_destino' => 'MO4502',
            'cuenta' => '73950505', 'item' => 'Cemento', 'costo' => 500000, 'naturaleza' => 'Débito',
        ]);

        $mov = (new PlanoContableController())->movimientosReasignaciones(7, 2026, 'mantenimiento', 99);

        // Crédito en la OT origen, débito en la OT destino, misma cuenta.
        $cred = collect($mov)->firstWhere('unidad', 'MO4501');
        $deb  = collect($mov)->firstWhere('unidad', 'MO4502');
        $this->assertSame('73950505', $cred['cuenta']);
        $this->assertEqualsWithDelta(500000, $cred['credito'], 0.5);
        $this->assertSame('73950505', $deb['cuenta']);
        $this->assertEqualsWithDelta(500000, $deb['debito'], 0.5);
        // Cuadra.
        $this->assertEqualsWithDelta(array_sum(array_column($mov, 'debito')), array_sum(array_column($mov, 'credito')), 0.5);
    }

    #[Test]
    public function un_no_operacion_no_puede_reasignar(): void
    {
        $it = $this->item('MO4501', 'Cemento', 500000, '2026-07-10');
        $sinPermiso = User::factory()->create(['rol' => 'x', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'ver']]);

        $this->actingAs($sinPermiso)->post(route('operativo.items.reasignar', $it->id), ['destino' => 'MO4502'])
            ->assertForbidden();
        $this->assertSame('MO4501', $it->refresh()->codigo_obra);
    }
}
