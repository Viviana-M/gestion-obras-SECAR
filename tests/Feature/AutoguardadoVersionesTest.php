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
 * Autoguardado: no revive borradores borrados, no multiplica versiones y respeta el candado óptimista
 * entre pestañas. Una sola versión viva por (mes, depto): el autoguardado ACTUALIZA, no crea.
 */
class AutoguardadoVersionesTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function seedObra(): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 5_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
    }

    private function borrador(int $version = 1, int $lock = 0): Distribucion
    {
        return Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => $version, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
            'lock_version' => $lock]);
    }

    #[Test]
    public function el_autoguardado_no_revive_un_borrador_borrado(): void
    {
        $this->seedObra();
        $d = $this->borrador();
        $idBorrado = $d->id;
        $d->delete();   // el usuario eliminó el borrador vigente
        $this->assertSame(0, Distribucion::count());

        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'dist' => $idBorrado, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 500]], 'obras_editadas' => ['C-1'],
        ]);

        $resp->assertStatus(409)->assertJson(['ok' => false, 'motivo' => 'borrador_inexistente']);
        // No se recreó NADA: el borrado sigue borrado.
        $this->assertSame(0, Distribucion::count());
    }

    #[Test]
    public function el_autoguardado_actualiza_la_misma_version_no_crea_decenas(): void
    {
        $this->seedObra();
        $d = $this->borrador(lock: 3);

        // Varios autoguardados seguidos (sin dist la 1ª vez: reutiliza el vigente; luego con dist).
        $r1 = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 300]], 'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 0],
            'lock_version' => 3,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($d->id, $r1->json('dist'));        // reutilizó el vigente, no creó otro
        $this->assertSame(1, Distribucion::count());

        // Segundo autoguardado con el dist y el lock refrescado: sigue siendo la MISMA versión.
        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 700]], 'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 300],
            'lock_version' => $r1->json('lock_version'),
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, Distribucion::count());
    }

    #[Test]
    public function el_autoguardado_con_candado_desfasado_avisa_conflicto_y_no_pisa(): void
    {
        $this->seedObra();
        $d = $this->borrador(lock: 5);

        // La pestaña trae un lock viejo (4): otra pestaña ya guardó (lock real 5).
        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 999]], 'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 0],
            'lock_version' => 4,
        ]);

        $resp->assertStatus(409)->assertJson(['ok' => false, 'motivo' => 'conflicto', 'stale' => true]);
        // No se pisó nada ni se creó versión nueva.
        $this->assertSame(1, Distribucion::count());
        $this->assertSame(0, AplicacionCosto::where('distribucion_id', $d->id)->count());
    }

    #[Test]
    public function sin_dist_y_sin_borrador_el_autoguardado_crea_uno_solo(): void
    {
        $this->seedObra();
        $this->assertSame(0, Distribucion::count());

        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 500]], 'obras_editadas' => ['C-1'], 'obras_base' => ['C-1' => 0],
        ])->assertOk()->assertJson(['ok' => true]);

        // Alta explícita (sin dist): se crea UNA sola distribución.
        $this->assertSame(1, Distribucion::count());
    }

    #[Test]
    public function el_front_editable_cablea_el_manejo_de_motivos_y_el_aviso_multipestana(): void
    {
        $this->seedObra();

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        // El front detiene el guardado (auto y manual) ante borrador borrado / conflicto de pestañas.
        $this->assertStringContainsString('manejarMotivoGuardado', $html);
        $this->assertStringContainsString("payload.motivo === 'borrador_inexistente'", $html);
        $this->assertStringContainsString("payload.motivo === 'conflicto'", $html);
        // Aviso NO bloqueante de otra pestaña editando el mismo mes/depto (cierre abierto → editable).
        $this->assertStringContainsString('BroadcastChannel', $html);
    }
}
