<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Distribución de mano de obra por persona → obra: saldos por tercero (MO por homologación),
 * asignación con validación, precarga del mes anterior, resumen por obra y aplicación 14→61.
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

    /** Línea de MO (cuenta 14) de una bolsa con un tercero. estado_er negativo = pendiente. */
    private function mo(string $bolsa, string $c14, string $doc, float $monto, int $mes = 5, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $bolsa, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $doc, 'razon_social' => 'T '.$doc,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio, 'origen' => 'biable',
        ]);
    }

    private function setup_base(): void
    {
        // La bolsa MTO00099 (mantenimiento) ya viene sembrada por la migración de un_bolsas.
        $this->homolog('14200530', '73950505', 'MOI');   // mano de obra
        $this->homolog('14350105', '61350105', 'EQU-MAT-SUM'); // NO es mano de obra
        FichaProyecto::create(['codigo_proyecto' => 'OB1', 'nombre_obra' => 'Obra 1', 'activa' => true]);
        FichaProyecto::create(['codigo_proyecto' => 'OB2', 'nombre_obra' => 'Obra 2', 'activa' => true]);
    }

    // ─────────── Saldos por tercero (solo MO) ───────────

    #[Test]
    public function lista_el_saldo_de_mano_de_obra_por_tercero(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $this->mo('MTO00099', '14200530', '222', 300000);
        $this->mo('MTO00099', '14350105', '111', 900000);   // NO es MO → no debe salir

        $saldos = collect($this->actingAs($this->op('ver'))
            ->get(route('operativo.mano-obra.index', ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026]))
            ->viewData('saldos'))->keyBy('tercero');

        $this->assertCount(2, $saldos);
        $this->assertEqualsWithDelta(500000, $saldos['111']['saldo'], 1);
        $this->assertEqualsWithDelta(300000, $saldos['222']['saldo'], 1);
    }

    // ─────────── Guardar con validación ───────────

    #[Test]
    public function guarda_las_asignaciones_repartidas_a_varias_obras(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['tercero' => '111', 'obra' => 'OB1', 'monto' => 300000],
                ['tercero' => '111', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, ManoObraAsignacion::count());
        $this->assertEqualsWithDelta(300000, ManoObraAsignacion::where('obra_destino', 'OB1')->sum('monto'), 1);
        $this->assertEqualsWithDelta(200000, ManoObraAsignacion::where('obra_destino', 'OB2')->sum('monto'), 1);
        $this->assertSame('14200530', ManoObraAsignacion::first()->cuenta_14);
    }

    #[Test]
    public function no_deja_asignar_mas_del_saldo(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['tercero' => '111', 'obra' => 'OB1', 'monto' => 600000]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ManoObraAsignacion::count());
    }

    // ─────────── Precargar del mes anterior ───────────

    #[Test]
    public function precarga_el_mapa_del_mes_anterior_con_el_saldo_actual(): void
    {
        $this->setup_base();
        // Mes anterior (abril): 111 repartió 300k a OB1 y 100k a OB2 (total 400k).
        foreach ([['OB1', 300000], ['OB2', 100000]] as [$obra, $monto]) {
            ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'tercero' => '111',
                'obra_destino' => $obra, 'monto' => $monto, 'mes' => 4, 'anio' => 2026, 'origen' => 'manual']);
        }
        // Mayo: saldo actual de 111 = 500k.
        $this->mo('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op())->post(route('operativo.mano-obra.precargar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
        ])->assertRedirect()->assertSessionHas('success');

        // Reparte 500k en la proporción 300:100 → 375k y 125k.
        $may = ManoObraAsignacion::where('mes', 5)->where('anio', 2026)->get()->keyBy('obra_destino');
        $this->assertEqualsWithDelta(375000, $may['OB1']->monto, 1);
        $this->assertEqualsWithDelta(125000, $may['OB2']->monto, 1);
        $this->assertSame('precargado', $may['OB1']->origen);
    }

    // ─────────── Resumen por obra ───────────

    #[Test]
    public function el_resumen_por_obra_cuadra_con_lo_asignado(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['tercero' => '111', 'obra' => 'OB1', 'monto' => 300000],
                ['tercero' => '111', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect();

        $resp = $this->actingAs($this->op('ver'))->get(route('operativo.mano-obra.resumen', ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026]));
        $this->assertEqualsWithDelta(500000, $resp->viewData('total'), 1);
        $porObra = collect($resp->viewData('resumen'))->keyBy('obra');
        $this->assertEqualsWithDelta(300000, $porObra['OB1']['total'], 1);
        $this->assertEqualsWithDelta(200000, $porObra['OB2']['total'], 1);
    }

    // ─────────── Aplicar 14→61 ───────────

    #[Test]
    public function aplicar_crea_la_partida_doble_y_baja_el_saldo_de_la_bolsa(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['tercero' => '111', 'obra' => 'OB1', 'monto' => 500000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026, 'documento' => 9,
        ])->assertRedirect()->assertSessionHas('success');

        $plano = PlanoAplicado::sole();
        $this->assertSame('mo_distribucion', $plano->tipo);
        $this->assertSame('MTO00099', $plano->bolsa_un);
        $this->assertSame(2, $plano->n_lineas);

        // CR 14 en la bolsa (conserva tercero) → estado_er positivo que baja el pendiente.
        $cr = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '14200530')->sole();
        $this->assertSame('MTO00099', $cr->codigo_proyecto);
        $this->assertSame('111', $cr->tercero_dcto);
        $this->assertEqualsWithDelta(500000, $cr->valor_credito, 1);
        $this->assertEqualsWithDelta(500000, $cr->estado_er, 1);

        // DB 61 en la obra destino, mismo tercero.
        $db = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '73950505')->sole();
        $this->assertSame('OB1', $db->codigo_proyecto);
        $this->assertEqualsWithDelta(500000, $db->valor_debito, 1);

        // El saldo de MO de 111 en la bolsa quedó en cero (−500k biable + 500k aplicado).
        $neto = RegistroFinanciero::where('codigo_proyecto', 'MTO00099')->where('cuenta_contable', '14200530')->sum('estado_er');
        $this->assertEqualsWithDelta(0, $neto, 1);
    }

    #[Test]
    public function reaplicar_reemplaza_no_acumula(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['tercero' => '111', 'obra' => 'OB1', 'monto' => 500000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026])->assertRedirect();
        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026])->assertRedirect();

        $this->assertSame(1, PlanoAplicado::count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'distribucion_plano')->count());
    }

    #[Test]
    public function un_usuario_solo_lectura_no_puede_guardar(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);

        $this->actingAs($this->op('ver'))->post(route('operativo.mano-obra.guardar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['tercero' => '111', 'obra' => 'OB1', 'monto' => 100000]],
        ])->assertForbidden();
    }
}
