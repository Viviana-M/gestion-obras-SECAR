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
 * Guardado colaborativo (merge por obra): dos personas en obras distintas suman al mismo borrador
 * sin pisarse; solo hay conflicto si DOS editan la MISMA obra.
 */
class GuardadoColaborativoTest extends TestCase
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
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod,
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 5000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod,
            'cuenta_contable' => '14200105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -2000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);
    }

    private function dist(): Distribucion
    {
        return Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
            'lock_version' => 1,
        ]);
    }

    private function aplicacionDe(Distribucion $d, string $cod, float $monto): void
    {
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 7, 'anio' => 2026,
            'codigo_proyecto' => $cod, 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => $monto, 'es_provision' => false,
            'estado' => 'borrador', 'user_id' => $this->operador()->id]);
    }

    private function sumObra(Distribucion $d, string $cod): float
    {
        return (float) AplicacionCosto::where('distribucion_id', $d->id)->where('codigo_proyecto', $cod)->sum('monto_aplicar');
    }

    #[Test]
    public function dos_personas_en_obras_distintas_suman_sin_pisarse(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $d = $this->dist();
        $this->aplicacionDe($d, 'C-1', 1000000); // A ya guardó la obra C-1

        // B (que entró después) edita SOLO C-2 y guarda (el form trae C-1 también, pero no editada).
        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'lock_version' => 1, 'obras_editadas' => ['C-2'], 'obras_base' => ['C-2' => 0],
            'aplicar' => ['C-1' => ['14200105' => 1000000], 'C-2' => ['14200105' => 500000]],
        ])->assertOk()->assertJson(['ok' => true]);

        // C-1 (de A) se conserva y C-2 (de B) se agrega: ninguna se borra.
        $this->assertEqualsWithDelta(1000000, $this->sumObra($d, 'C-1'), 1);
        $this->assertEqualsWithDelta(500000, $this->sumObra($d, 'C-2'), 1);
    }

    #[Test]
    public function el_autoguardado_tambien_mergea_por_obra(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $d = $this->dist();
        $this->aplicacionDe($d, 'C-1', 1000000);

        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'lock_version' => 1, 'obras_editadas' => ['C-2'], 'obras_base' => ['C-2' => 0],
            'aplicar' => ['C-2' => ['14200105' => 300000]],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertEqualsWithDelta(1000000, $this->sumObra($d, 'C-1'), 1); // intacta
        $this->assertEqualsWithDelta(300000, $this->sumObra($d, 'C-2'), 1);
    }

    #[Test]
    public function misma_obra_editada_por_dos_avisa_conflicto_sin_perder_el_resto(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $d = $this->dist();
        $this->aplicacionDe($d, 'C-1', 1000000); // valor ACTUAL de C-1 (lo dejó otra persona)
        $d->update(['lock_version' => 5]); // el servidor avanzó desde que B cargó (lock viejo = 1)

        // B cargó C-1 en 500.000 (base), pero el servidor ya tiene 1.000.000 → conflicto en C-1.
        // B además edita C-2 (sin conflicto): esa SÍ se guarda.
        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'lock_version' => 1, // desfasado
            'obras_editadas' => ['C-1', 'C-2'], 'obras_base' => ['C-1' => 500000, 'C-2' => 0],
            'aplicar' => ['C-1' => ['14200105' => 999000], 'C-2' => ['14200105' => 700000]],
        ]);

        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertContains('C-1', $resp->json('conflictos')); // avisa solo de C-1

        // C-1 NO se sobreescribió (sigue en 1.000.000); C-2 sí se guardó (700.000).
        $this->assertEqualsWithDelta(1000000, $this->sumObra($d, 'C-1'), 1);
        $this->assertEqualsWithDelta(700000, $this->sumObra($d, 'C-2'), 1);
    }
}
