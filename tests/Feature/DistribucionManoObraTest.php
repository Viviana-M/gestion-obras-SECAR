<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mano de obra por tercero integrada en el grid de bolsas: desglose real por tercero, subtotales
 * MO/sin-MO, asignación por (UN, cuenta, tercero) → obra, y plano 14→61 por departamento.
 */
class DistribucionManoObraTest extends TestCase
{
    use RefreshDatabase;

    private function op(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'operacion', 'activo' => true, 'permisos_modulos' => ['operacion' => $nivel],
        ]);
    }

    private function homolog(string $c14, string $c61, string $estructura): void
    {
        Homologacion::create(['cuenta_14' => $c14, 'cuenta_61' => $c61, 'nombre' => 'H',
            'estructura' => $estructura, 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
    }

    /** Línea de la cuenta 14 de una bolsa con un tercero. estado_er negativo = pendiente. */
    private function rf(string $bolsa, string $c14, string $doc, float $monto, int $mes = 5, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $bolsa, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $doc, 'razon_social' => 'T '.$doc,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio, 'origen' => 'biable',
        ]);
    }

    private function base(): void
    {
        // Bolsa MTO00099 (mantenimiento) ya sembrada. 14200530 = MO (estructura), 14350105 = materiales.
        $this->homolog('14200530', '73950505', 'MOI');
        $this->homolog('14350105', '61350105', 'EQU-MAT-SUM');
        FichaProyecto::create(['codigo_proyecto' => 'OB1', 'nombre_obra' => 'Obra 1', 'activa' => true]);
        FichaProyecto::create(['codigo_proyecto' => 'OB2', 'nombre_obra' => 'Obra 2', 'activa' => true]);
    }

    private function bolsaMto($resp)
    {
        return collect($resp->viewData('bolsas'))->firstWhere('codigo', 'mantenimiento');
    }

    // ─────────── Grid: subtotales y desglose por tercero (bug corregido) ───────────

    #[Test]
    public function el_grid_abre_la_mo_por_tercero_real_y_muestra_subtotales(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);   // MO
        $this->rf('MTO00099', '14200530', '222', 300000);   // MO, otro tercero
        $this->rf('MTO00099', '14350105', '900', 200000);   // materiales (no MO)

        $b = $this->bolsaMto($this->actingAs($this->op('ver'))
            ->get('/operativo/distribucion?mes=5&anio=2026&departamento=mantenimiento'));

        $this->assertNotNull($b);
        // Subtotales: X (sin MO) + Y (MO) = Total.
        $this->assertEqualsWithDelta(800000, $b['total_mo'], 1);
        $this->assertEqualsWithDelta(200000, $b['total_sinmo'], 1);
        $this->assertEqualsWithDelta($b['total'], $b['total_mo'] + $b['total_sinmo'], 1);

        // La cuenta de MO se abre por tercero (no colapsada en uno solo).
        $lineaMo = collect($b['lineas'])->firstWhere('cuenta_14', '14200530');
        $ters = collect($lineaMo['terceros'])->keyBy('tercero');
        $this->assertCount(2, $ters);
        $this->assertEqualsWithDelta(500000, $ters['111']['saldo'], 1);
        $this->assertEqualsWithDelta(300000, $ters['222']['saldo'], 1);
        // La suma por cuenta cuadra con el saldo de la línea.
        $this->assertEqualsWithDelta($lineaMo['saldo'], $ters->sum('saldo'), 1);

        // La cuenta no laboral NO se abre por tercero.
        $lineaMat = collect($b['lineas'])->firstWhere('cuenta_14', '14350105');
        $this->assertEmpty($lineaMat['terceros']);
    }

    // ─────────── Guardar / validación ───────────

    #[Test]
    public function guarda_la_asignacion_por_tercero_a_varias_obras(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 300000],
                ['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertEqualsWithDelta(300000, ManoObraAsignacion::where('obra_destino', 'OB1')->sum('monto'), 1);
        $this->assertEqualsWithDelta(200000, ManoObraAsignacion::where('obra_destino', 'OB2')->sum('monto'), 1);
        $this->assertSame('MTO00099', ManoObraAsignacion::first()->bolsa_un);
        $this->assertSame('111', ManoObraAsignacion::first()->tercero);
    }

    #[Test]
    public function no_deja_asignar_mas_del_saldo_del_tercero(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 600000]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ManoObraAsignacion::count());
    }

    // ─────────── Precargar ───────────

    #[Test]
    public function precarga_el_mapa_del_mes_anterior_con_el_saldo_actual(): void
    {
        $this->base();
        foreach ([['OB1', 300000], ['OB2', 100000]] as [$obra, $monto]) {
            ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111',
                'tercero' => '111', 'obra_destino' => $obra, 'monto' => $monto, 'mes' => 4, 'anio' => 2026, 'origen' => 'manual']);
        }
        $this->rf('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.precargar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
        ])->assertRedirect()->assertSessionHas('success');

        $may = ManoObraAsignacion::where('mes', 5)->where('anio', 2026)->get()
            ->groupBy('obra_destino')->map(fn ($g) => $g->sum('monto'));
        $this->assertEqualsWithDelta(375000, $may['OB1'], 1);
        $this->assertEqualsWithDelta(125000, $may['OB2'], 1);
    }

    // ─────────── Resumen ───────────

    #[Test]
    public function el_resumen_por_obra_cuadra_con_lo_asignado(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);
        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 300000],
                ['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect();

        $resp = $this->actingAs($this->op('ver'))->get(route('operativo.mano-obra.resumen', ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026]));
        $this->assertEqualsWithDelta(500000, $resp->viewData('total'), 1);
        $porObra = collect($resp->viewData('resumen'))->keyBy('obra');
        $this->assertEqualsWithDelta(300000, $porObra['OB1']['total'], 1);
        $this->assertEqualsWithDelta(200000, $porObra['OB2']['total'], 1);
    }

    // ─────────── Aplicar 14→61 ───────────

    #[Test]
    public function aplicar_crea_la_partida_doble_y_baja_el_saldo(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 500000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026, 'documento' => 9,
        ])->assertRedirect()->assertSessionHas('success');

        $plano = PlanoAplicado::where('tipo', 'mo_distribucion')->sole();
        $this->assertSame('MTO00099', $plano->bolsa_un);

        $cr = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '14200530')->sole();
        $this->assertSame('MTO00099', $cr->codigo_proyecto);
        $this->assertSame('111', $cr->tercero_dcto);
        $this->assertEqualsWithDelta(500000, $cr->valor_credito, 1);

        $db = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '73950505')->sole();
        $this->assertSame('OB1', $db->codigo_proyecto);
        $this->assertEqualsWithDelta(500000, $db->valor_debito, 1);

        $neto = RegistroFinanciero::where('codigo_proyecto', 'MTO00099')->where('cuenta_contable', '14200530')->sum('estado_er');
        $this->assertEqualsWithDelta(0, $neto, 1);
    }

    #[Test]
    public function reaplicar_reemplaza_no_acumula(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 500000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026])->assertRedirect();
        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026])->assertRedirect();

        $this->assertSame(1, PlanoAplicado::where('tipo', 'mo_distribucion')->count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'distribucion_plano')->count());
    }

    #[Test]
    public function un_usuario_solo_lectura_no_puede_guardar(): void
    {
        $this->base();
        $this->rf('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op('ver'))->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111', 'obra' => 'OB1', 'monto' => 100000]],
        ])->assertForbidden();
    }
}
