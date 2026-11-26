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
 * "Guardar cambios" no debe perder las obras precargadas / "Aplicar todo": el PRIMER guardado de un
 * borrador nuevo persiste COMPLETO (no merge), y a partir del 2º el merge por obra protege lo guardado.
 */
class GuardadoPrecargaCompletaTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function seedObra(string $cod): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 5_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -2_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
    }

    #[Test]
    public function el_primer_guardado_persiste_todas_las_obras_aunque_solo_una_este_marcada(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');

        // Precarga/"Aplicar todo": el front marca ambas, pero aunque mandara solo una en obras_editadas,
        // el primer guardado (sin nada guardado aún) debe persistir COMPLETO (no merge).
        $r = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 300], 'C-2' => ['14200105' => 400]],
            'estado_obra' => ['C-1' => 'parcial', 'C-2' => 'parcial'],
            'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 0],
        ])->assertOk()->assertJson(['ok' => true]);

        $dist = $r->json('dist');
        $this->assertSame(1, Distribucion::count());
        // Las DOS obras quedaron guardadas (no solo la marcada).
        $this->assertEqualsWithDelta(300, AplicacionCosto::where('distribucion_id', $dist)->where('codigo_proyecto', 'C-1')->sum('monto_aplicar'), 1);
        $this->assertEqualsWithDelta(400, AplicacionCosto::where('distribucion_id', $dist)->where('codigo_proyecto', 'C-2')->sum('monto_aplicar'), 1);
    }

    #[Test]
    public function a_partir_del_segundo_guardado_el_merge_protege_lo_guardado(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $op = $this->operador();

        // 1º guardado completo (C-1=300, C-2=400).
        $r1 = $this->actingAs($op)->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 300], 'C-2' => ['14200105' => 400]],
            'estado_obra' => ['C-1' => 'parcial', 'C-2' => 'parcial'],
            'obras_editadas' => ['C-1', 'C-2'], 'obras_base' => ['C-1' => 0, 'C-2' => 0],
        ])->assertOk();
        $dist = $r1->json('dist');

        // 2º guardado: SOLO C-1 editada (C-2 no viene). Como ya hay guardado, es merge: C-2 se conserva.
        $this->actingAs($op)->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'dist' => $dist,
            'aplicar' => ['C-1' => ['14200105' => 999]],
            'estado_obra' => ['C-1' => 'parcial'],
            'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 300],
            'lock_version' => $r1->json('lock_version'),
        ])->assertOk()->assertJson(['ok' => true]);

        // C-1 se actualizó; C-2 quedó intacta (merge protegió lo guardado).
        $this->assertEqualsWithDelta(999, AplicacionCosto::where('distribucion_id', $dist)->where('codigo_proyecto', 'C-1')->sum('monto_aplicar'), 1);
        $this->assertEqualsWithDelta(400, AplicacionCosto::where('distribucion_id', $dist)->where('codigo_proyecto', 'C-2')->sum('monto_aplicar'), 1);
    }

    #[Test]
    public function aplicar_todo_y_el_costo_sugerido_marcan_la_obra_como_editada(): void
    {
        $this->seedObra('C-1');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        // El helper global existe y las acciones por código marcan la obra para que entre al guardado.
        $this->assertStringContainsString('function marcarObraEditada(', $html);
        $this->assertStringContainsString('marcarObraEditada(cod)', $html);          // FIFO / costo sugerido
        $this->assertStringContainsString("card.setAttribute('data-editada', '1')", $html); // Aplicar todo / en 0
    }
}
