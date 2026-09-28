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
 * Mano de obra DIRECTA integrada en el grid de bolsas: costo completo por persona (maestro Mano de
 * obra directa) usando el mismo cruce por cédula del plano de MO de apoyo (salario cuenta 14 +
 * seguridad social de la autoliquidación), asignación por persona → obra, subtotales, resumen y
 * plano 14→61 preservando persona + obra destino.
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
    private function rf(string $bolsa, string $c14, string $doc, string $nom, float $monto, int $mes = 5, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $bolsa, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $doc, 'razon_social' => $nom,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio, 'origen' => 'biable',
        ]);
    }

    /**
     * Escenario base: Juan (maestro Mano de obra directa) con salario 500k en la cuenta 14200530 de
     * MTO00099 y 100k de seguridad social en la autoliquidación (fondo 800100). Además materiales
     * 200k (no MO). Costo completo de Juan = 600k. Total bolsa = 800k.
     */
    private function base(): void
    {
        $this->homolog('14200530', '73950505', 'MOI');          // MO (está en CUENTAS_MO)
        $this->homolog('14350105', '61350105', 'EQU-MAT-SUM');  // materiales (resto)
        TerceroManoObra::create(['cedula' => '111', 'nombre' => 'Juan Perez', 'departamento' => 'mantenimiento', 'activo' => true]);

        $this->rf('MTO00099', '14200530', '111', 'Juan Perez', 500000);  // salario de Juan
        $this->rf('MTO00099', '14200530', '800100', 'EPS SURA', 100000); // seguridad social (fondo) en la 14
        $this->rf('MTO00099', '14350105', '900', 'Ferreteria', 200000);  // materiales (resto)

        AutoliquidacionAporte::create([
            'cedula' => '800100', 'razon_social' => 'EPS SURA', 'empleado' => '111', 'empleado_nombre' => 'Juan Perez',
            'id_cuenta' => '14200530', 'aporte_empresa' => 100000, 'aporte_empleado' => 0, 'mes' => 5, 'anio' => 2026,
        ]);

        FichaProyecto::create(['codigo_proyecto' => 'OB1', 'nombre_obra' => 'Obra 1', 'activa' => true]);
        FichaProyecto::create(['codigo_proyecto' => 'OB2', 'nombre_obra' => 'Obra 2', 'activa' => true]);
    }

    private function bolsaMto($resp)
    {
        return collect($resp->viewData('bolsas'))->firstWhere('codigo', 'mantenimiento');
    }

    // ─────────── Grid: costo por persona y subtotales ───────────

    #[Test]
    public function el_grid_abre_por_persona_del_maestro_con_su_costo_completo(): void
    {
        $this->base();

        $b = $this->bolsaMto($this->actingAs($this->op('ver'))
            ->get('/operativo/distribucion?mes=5&anio=2026&departamento=mantenimiento'));

        $this->assertNotNull($b);
        // Costo completo de Juan = salario 500k + seguridad social 100k = 600k.
        $personas = collect($b['mo_personas'])->keyBy('cedula');
        $this->assertCount(1, $personas);
        $this->assertEqualsWithDelta(600000, $personas['111']['total'], 1);
        $this->assertCount(2, $personas['111']['buckets']); // salario + SS

        // Subtotales: MO directa a distribuir (Y) + resto (X) = Total.
        $this->assertEqualsWithDelta(600000, $b['total_mo'], 1);
        $this->assertEqualsWithDelta(200000, $b['total_sinmo'], 1);
        $this->assertEqualsWithDelta($b['total'], $b['total_mo'] + $b['total_sinmo'], 1);
    }

    #[Test]
    public function una_persona_fuera_del_maestro_no_entra(): void
    {
        $this->base();
        // Otro tercero con MO en la bolsa, pero NO está en el maestro: no debe aparecer.
        $this->rf('MTO00099', '14200530', '222', 'Pedro Ajeno', 300000);

        $b = $this->bolsaMto($this->actingAs($this->op('ver'))
            ->get('/operativo/distribucion?mes=5&anio=2026&departamento=mantenimiento'));

        $personas = collect($b['mo_personas'])->pluck('cedula')->all();
        $this->assertSame(['111'], $personas);
        $this->assertEqualsWithDelta(600000, $b['total_mo'], 1); // solo Juan
    }

    // ─────────── Guardar / validación ───────────

    #[Test]
    public function guarda_la_asignacion_por_persona_a_varias_obras(): void
    {
        $this->base();

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 400000],
                ['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        // Se reparte por obra y por bucket (salario + SS), preservando persona.
        $this->assertEqualsWithDelta(400000, ManoObraAsignacion::where('obra_destino', 'OB1')->sum('monto'), 1);
        $this->assertEqualsWithDelta(200000, ManoObraAsignacion::where('obra_destino', 'OB2')->sum('monto'), 1);
        $this->assertSame('111', ManoObraAsignacion::first()->persona);
        // Cada obra se explota en dos filas (salario + SS).
        $this->assertSame(2, ManoObraAsignacion::where('obra_destino', 'OB1')->count());
    }

    #[Test]
    public function no_deja_asignar_mas_del_costo_de_la_persona(): void
    {
        $this->base();

        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 700000]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, ManoObraAsignacion::count());
    }

    // ─────────── Precargar ───────────

    #[Test]
    public function precarga_las_proporciones_del_mes_anterior_con_el_costo_actual(): void
    {
        $this->base();
        foreach ([['OB1', 300000], ['OB2', 100000]] as [$obra, $monto]) {
            ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111',
                'tercero' => '111', 'obra_destino' => $obra, 'monto' => $monto, 'mes' => 4, 'anio' => 2026, 'origen' => 'manual']);
        }

        $this->actingAs($this->op())->post(route('operativo.mano-obra.precargar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
        ])->assertRedirect()->assertSessionHas('success');

        // Costo actual de Juan (600k) repartido en 75/25 (proporción del mes anterior).
        $may = ManoObraAsignacion::where('mes', 5)->where('anio', 2026)->get()
            ->groupBy('obra_destino')->map(fn ($g) => $g->sum('monto'));
        $this->assertEqualsWithDelta(450000, $may['OB1'], 1);
        $this->assertEqualsWithDelta(150000, $may['OB2'], 1);
    }

    // ─────────── Resumen ───────────

    #[Test]
    public function el_resumen_por_obra_cuadra_por_persona(): void
    {
        $this->base();
        $this->actingAs($this->op())->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [
                ['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 400000],
                ['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB2', 'monto' => 200000],
            ],
        ])->assertRedirect();

        $resp = $this->actingAs($this->op('ver'))->get(route('operativo.mano-obra.resumen', ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026]));
        $this->assertEqualsWithDelta(600000, $resp->viewData('total'), 1);
        $porObra = collect($resp->viewData('resumen'))->keyBy('obra');
        $this->assertEqualsWithDelta(400000, $porObra['OB1']['total'], 1);
        // Desglose por persona (agrega salario + SS en una sola línea de Juan).
        $this->assertCount(1, $porObra['OB1']['detalle']);
        $this->assertSame('111', $porObra['OB1']['detalle'][0]['tercero']);
        $this->assertEqualsWithDelta(400000, $porObra['OB1']['detalle'][0]['monto'], 1);
    }

    // ─────────── Aplicar 14→61 ───────────

    #[Test]
    public function aplicar_preserva_persona_y_obra_y_baja_el_saldo(): void
    {
        $this->base();
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 600000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026, 'documento' => 9,
        ])->assertRedirect()->assertSessionHas('success');

        $plano = PlanoAplicado::where('tipo', 'mo_distribucion')->sole();
        $this->assertSame('MTO00099', $plano->bolsa_un);

        // CR la 14 en la bolsa (con el tercero del ERP: persona en salario, fondo en SS).
        $cr = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '14200530')->get();
        $this->assertEqualsWithDelta(600000, $cr->sum('valor_credito'), 1);
        $this->assertSame(['MTO00099'], $cr->pluck('codigo_proyecto')->unique()->values()->all());
        $this->assertEqualsContains(['111', '800100'], $cr->pluck('tercero_dcto')->all());

        // DB la 61 homologada en la obra destino, siempre con la PERSONA.
        $db = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '73950505')->get();
        $this->assertEqualsWithDelta(600000, $db->sum('valor_debito'), 1);
        $this->assertSame(['OB1'], $db->pluck('codigo_proyecto')->unique()->values()->all());
        $this->assertSame(['111'], $db->pluck('tercero_dcto')->unique()->values()->all());

        // El saldo de la 14 en la bolsa quedó en cero (se aplicó el costo completo).
        $neto = RegistroFinanciero::where('codigo_proyecto', 'MTO00099')->where('cuenta_contable', '14200530')->sum('estado_er');
        $this->assertEqualsWithDelta(0, $neto, 1);
    }

    #[Test]
    public function reaplicar_reemplaza_no_acumula(): void
    {
        $this->base();
        $c = $this->op();
        $this->actingAs($c)->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 600000]],
        ])->assertRedirect();

        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026])->assertRedirect();
        $this->actingAs($c)->post(route('operativo.mano-obra.aplicar'), ['departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026])->assertRedirect();

        $this->assertSame(1, PlanoAplicado::where('tipo', 'mo_distribucion')->count());
        $this->assertSame(4, RegistroFinanciero::where('origen', 'distribucion_plano')->count()); // 2 CR + 2 DB
    }

    #[Test]
    public function un_usuario_solo_lectura_no_puede_guardar(): void
    {
        $this->base();

        $this->actingAs($this->op('ver'))->post(route('operativo.mano-obra.guardar'), [
            'departamento' => 'mantenimiento', 'mes' => 5, 'anio' => 2026,
            'asignaciones' => [['cedula' => '111', 'nombre' => 'Juan Perez', 'obra' => 'OB1', 'monto' => 100000]],
        ])->assertForbidden();
    }

    /** Helper: los valores esperados están todos presentes (en cualquier orden). */
    private function assertEqualsContains(array $esperados, array $reales): void
    {
        foreach ($esperados as $e) $this->assertContains($e, $reales);
    }
}
