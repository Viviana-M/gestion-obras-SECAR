<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\DistribucionVersion;
use App\Models\ManoObraAsignacion;
use App\Models\Provision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El comando distribucion:regenerar-snapshots recalcula el snapshot congelado de las versiones con
 * la lógica actual (incluye provisión y mano de obra), sin crear versiones nuevas.
 */
class RegenerarSnapshotsResumenTest extends TestCase
{
    use RefreshDatabase;

    private function seedDistribucionConSnapshotViejo(): DistribucionVersion
    {
        $uid = User::factory()->create(['rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar']])->id;

        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'enviado', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);
        AplicacionCosto::create(['distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026,
            'codigo_proyecto' => 'C-700', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 600, 'es_provision' => false,
            'estado' => 'ok', 'user_id' => $uid]);
        Provision::create(['codigo_proyecto' => 'C-700', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 1000,
            'descripcion' => 'X', 'mes' => 7, 'anio' => 2026, 'estado' => 'activa']);
        ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111',
            'tercero' => '111', 'tercero_nombre' => 'Juan', 'obra_destino' => 'C-700', 'monto' => 2000,
            'mes' => 7, 'anio' => 2026, 'origen' => 'manual']);

        // Snapshot VIEJO (como antes del arreglo): costo sin provisión ni MO.
        return DistribucionVersion::create([
            'distribucion_id' => $dist->id, 'evento' => 'enviado', 'user_id' => $uid, 'user_nombre' => 'X',
            'snapshot' => ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
                'todo' => ['ingreso' => 0, 'cat' => [], 'costo_total' => 600, 'mc_pesos' => -600, 'mc_pct' => null],
                'tabla' => [], 'tipos' => [], 'categorias' => []],
        ]);
    }

    #[Test]
    public function regenera_el_snapshot_incluyendo_provision_y_mo(): void
    {
        $v = $this->seedDistribucionConSnapshotViejo();

        $this->artisan('distribucion:regenerar-snapshots', ['--id' => $v->id])->assertSuccessful();

        // Nuevo costo_total = aplicado 600 + provisión 1000 + MO 2000 = 3600 (incluye provisión y MO).
        $this->assertEqualsWithDelta(3600, (float) $v->fresh()->snapshot['todo']['costo_total'], 0.5);
        // No se crearon versiones nuevas.
        $this->assertSame(1, DistribucionVersion::count());
    }

    #[Test]
    public function dry_run_no_modifica_el_snapshot(): void
    {
        $v = $this->seedDistribucionConSnapshotViejo();

        $this->artisan('distribucion:regenerar-snapshots', ['--id' => $v->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(600, (float) $v->fresh()->snapshot['todo']['costo_total'], 0.5);
    }
}
