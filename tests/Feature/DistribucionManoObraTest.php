<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\ManoObraAsignacion;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\TerceroManoObra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Distribución de mano de obra DIRECTA por persona → obra: solo el maestro TerceroManoObra, valor =
 * salario de la bolsa + SS de PILA, asignación con validación, precarga, resumen y plano 14→61.
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

    /** Línea de MO (cuenta 14) de una bolsa con un tercero (documento = cédula de la persona). */
    private function mo(string $bolsa, string $c14, string $doc, float $monto, int $mes = 5, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $bolsa, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $doc, 'razon_social' => 'T '.$doc,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio, 'origen' => 'biable',
        ]);
    }

    /** Aporte PILA (seguridad social) de un empleado, con el fondo como tercero. */
    private function pila(string $empleado, string $fondo, string $un, float $aporte, int $mes = 5, int $anio = 2026): void
    {
        AutoliquidacionAporte::create([
            'id_cuenta' => '14200530', 'cedula' => $fondo, 'razon_social' => 'NUEVA EPS',
            'empleado' => $empleado, 'empleado_nombre' => 'EMP '.$empleado,
            'un_codigo' => $un, 'cuenta_contable' => '14200530', 'concepto_pila' => 'EPS',
            'aporte_empresa' => $aporte, 'aporte_empleado' => 0, 'real_descontado' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function setup_base(): void
    {
        // Bolsa MTO00099 (mantenimiento) ya viene sembrada. Maestro de mano de obra directa:
        TerceroManoObra::create(['cedula' => '111', 'nombre' => 'PERSONA UNO', 'departamento' => 'mantenimiento', 'activo' => true]);
        TerceroManoObra::create(['cedula' => '222', 'nombre' => 'PERSONA DOS', 'departamento' => 'mantenimiento', 'activo' => true]);
        // 14200530 es cuenta de MO (lista fija del servicio de apoyo) y necesita su homologación 61.
        $this->homolog('14200530', '73950505', 'MOI');
        FichaProyecto::create(['codigo_proyecto' => 'OB1', 'nombre_obra' => 'Obra 1', 'activa' => true]);
        FichaProyecto::create(['codigo_proyecto' => 'OB2', 'nombre_obra' => 'Obra 2', 'activa' => true]);
    }

    // ─────────── Saldos por persona: solo maestro directa ───────────

    #[Test]
    public function lista_solo_las_personas_del_maestro_directa(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $this->mo('MTO00099', '14200530', '222', 300000);
        $this->mo('MTO00099', '14200530', '999', 900000);   // NO está en el maestro → no aparece

        $saldos = collect($this->actingAs($this->op('ver'))
            ->get(route('operativo.mano-obra.index', ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026]))
            ->viewData('saldos'))->keyBy('tercero');

        $this->assertCount(2, $saldos);
        $this->assertEqualsWithDelta(500000, $saldos['111']['saldo'], 1);
        $this->assertEqualsWithDelta(300000, $saldos['222']['saldo'], 1);
        $this->assertArrayNotHasKey('999', $saldos->all());
    }

    #[Test]
    public function el_valor_por_persona_suma_salario_y_seguridad_social_de_pila(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);       // salario
        $this->pila('111', '800100', 'MTO00099', 100000);        // SS atribuida por PILA

        $saldos = collect($this->actingAs($this->op('ver'))
            ->get(route('operativo.mano-obra.index', ['bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026]))
            ->viewData('saldos'))->keyBy('tercero');

        $this->assertEqualsWithDelta(600000, $saldos['111']['saldo'], 1);   // 500k + 100k
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

        $this->assertEqualsWithDelta(300000, ManoObraAsignacion::where('obra_destino', 'OB1')->sum('monto'), 1);
        $this->assertEqualsWithDelta(200000, ManoObraAsignacion::where('obra_destino', 'OB2')->sum('monto'), 1);
        $this->assertSame('14200530', ManoObraAsignacion::first()->cuenta_14);
        $this->assertSame('111', ManoObraAsignacion::first()->persona);
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
        foreach ([['OB1', 300000], ['OB2', 100000]] as [$obra, $monto]) {
            ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111',
                'tercero' => '111', 'obra_destino' => $obra, 'monto' => $monto, 'mes' => 4, 'anio' => 2026, 'origen' => 'manual']);
        }
        $this->mo('MTO00099', '14200530', '111', 500000);   // saldo actual mayo

        $this->actingAs($this->op())->post(route('operativo.mano-obra.precargar'), [
            'bolsa' => 'MTO00099', 'mes' => 5, 'anio' => 2026,
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

    #[Test]
    public function la_pantalla_de_distribucion_expone_la_mo_por_tercero(): void
    {
        $this->setup_base();
        $this->mo('MTO00099', '14200530', '111', 500000);
        $this->mo('MTO00099', '14200530', '222', 300000);

        $resp = $this->actingAs($this->op())
            ->get('/operativo/distribucion?mes=5&anio=2026&departamento=mantenimiento&mo_bolsa=MTO00099');
        $resp->assertStatus(200);

        $moSaldos = collect($resp->viewData('moSaldos'))->keyBy('tercero');
        $this->assertCount(2, $moSaldos);
        $this->assertEqualsWithDelta(500000, $moSaldos['111']['saldo'], 1);
        $this->assertEqualsWithDelta(300000, $moSaldos['222']['saldo'], 1);
        $resp->assertSee('Mano de obra directa por persona', false);
    }
}
