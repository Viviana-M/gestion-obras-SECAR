<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\BolsaAsignacion;
use App\Models\BolsaMonto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

/**
 * Punto 2: DOS bolsas grandes (Mantenimiento e Instalaciones), cada una consolidando
 * sus UN, con "monto a distribuir" editable por cuenta en el cierre. El disponible =
 * suma de esos montos, y es lo que se consume al asignar a los proyectos.
 * (Las UN MTO00099, MTO0000x, INS0000x vienen sembradas por la migración de un_bolsas.)
 */
class DistribucionBolsasTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** Movimiento de cuenta 14 de una UN de bolsa: el costo por repartir va NEGATIVO. */
    private function bolsa(string $unCodigo, float $monto, int $mes, int $anio, string $cc = '14200530'): void
    {
        $this->rf($unCodigo, 'Costos por aplicar', -abs($monto), $mes, $anio, $cc);
    }

    /** Obra C-700 (mantenimiento) con ingreso + pendiente propio, y la UN MTO00099 con 1000 por repartir. */
    private function seedBase(): void
    {
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');
        $this->bolsa('MTO00099', 1000, 6, 2026);
    }

    #[Test]
    public function el_panel_muestra_dos_bolsas_grandes_por_departamento(): void
    {
        $this->seedBase();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('BOLSAS DE ÁREA', false);
        $resp->assertSee('id="bolsa-box-mantenimiento"', false);   // bolsa grande por departamento
        $resp->assertSee('id="bolsa-disp-mantenimiento"', false);
        $resp->assertSee('Ver detalle por cuenta', false);
        $resp->assertSee('MTO00099', false);                        // la UN aparece en el detalle
        $resp->assertSee('Asignar desde bolsa de área', false);
        $resp->assertSee('id="asignbolsa-cta-C-700"', false);
        $resp->assertDontSee('id="card-MTO00099"', false);          // la bolsa no es una obra
    }

    #[Test]
    public function asignar_desde_la_bolsa_grande_persiste_y_acredita_la_un_real(): void
    {
        $this->seedBase();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ])->assertRedirect();

        // La asignación se registra contra la bolsa grande (departamento).
        $this->assertDatabaseHas('bolsa_asignaciones', [
            'bolsa_codigo' => 'mantenimiento', 'codigo_proyecto' => 'C-700', 'monto' => 400.00,
        ]);
        // Pero el plano acredita la cuenta 14 de la UN REAL de origen (MTO00099).
        $linea = AplicacionCosto::where('codigo_proyecto', 'C-700')->where('origen_bolsa', 'MTO00099')->first();
        $this->assertNotNull($linea);
        $this->assertSame('14200530', $linea->cuenta_14);
        $this->assertEqualsWithDelta(400, (float) $linea->monto_aplicar, 0.5);
    }

    #[Test]
    public function no_permite_asignar_mas_que_el_disponible_de_la_bolsa(): void
    {
        $this->seedBase(); // disponible por defecto = 1000

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 3000]]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(1000, (float) BolsaAsignacion::where('bolsa_codigo', 'mantenimiento')->sum('monto'), 0.5);
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function el_monto_a_distribuir_editado_es_el_disponible_de_la_bolsa(): void
    {
        // UN con 20M de saldo; en el cierre se decide distribuir solo 10M.
        $this->rf('C-700', 'Ingreso', 50000000, 7, 2026, '41350100');
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');
        $op = $this->operador();

        // Editar el "a distribuir" a 10M.
        $this->actingAs($op)->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026,
            'monto' => ['MTO00099|14200530' => 10000000],
            'obs'   => ['MTO00099|14200530' => 'Solo la mitad este mes'],
        ])->assertRedirect();

        $this->assertDatabaseHas('bolsa_montos', [
            'un_codigo' => 'MTO00099', 'cuenta_14' => '14200530',
            'monto_distribuir' => 10000000.00, 'observaciones' => 'Solo la mitad este mes',
        ]);

        // El panel muestra disponible 10M (no 20M).
        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertSee('$10.000.000', false);

        // Y el servidor no deja asignar más que ese disponible editado (10M).
        $this->actingAs($op)->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 18000000]]],
        ])->assertRedirect();
        $this->assertEqualsWithDelta(10000000, (float) BolsaAsignacion::where('bolsa_codigo', 'mantenimiento')->sum('monto'), 0.5);
    }

    #[Test]
    public function la_columna_queda_mes_siguiente_muestra_saldo_menos_a_distribuir(): void
    {
        // UN con 20M de saldo; se decide distribuir 15M → quedan 5M para el mes siguiente.
        $this->rf('C-700', 'Ingreso', 50000000, 7, 2026, '41350100');
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');
        $op = $this->operador();

        $this->actingAs($op)->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026,
            'monto' => ['MTO00099|14200530' => 15000000],
        ])->assertRedirect();

        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertStatus(200);
        $resp->assertSee('Queda mes siguiente', false);        // encabezado de la nueva columna
        $resp->assertSee('id="queda-tot-mantenimiento"', false); // total de la columna
        $resp->assertSee('$5.000.000', false);                  // 20M − 15M = 5M queda
    }

    #[Test]
    public function acepta_el_monto_a_distribuir_con_formato_de_dinero(): void
    {
        // El input llega con puntos de miles ("1.500.000"); el servidor lo guarda como 1500000.
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');

        $this->actingAs($this->operador())->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026,
            'monto' => ['MTO00099|14200530' => '1.500.000'],
        ])->assertRedirect();

        $this->assertDatabaseHas('bolsa_montos', [
            'un_codigo' => 'MTO00099', 'cuenta_14' => '14200530', 'monto_distribuir' => 1500000.00,
        ]);
    }

    #[Test]
    public function guardar_montos_de_bolsa_crea_la_distribucion_de_otros_costos(): void
    {
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');

        $this->actingAs($this->operador())->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'monto' => ['MTO00099|14200530' => 12000000],
            'obs'   => ['MTO00099|14200530' => 'Solo parte este mes'],
        ])->assertRedirect();

        // Se guardó como distribución de "otros costos" (áreas) para Mis distribuciones.
        $this->assertDatabaseHas('distribuciones', [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'areas', 'estado' => 'borrador',
        ]);

        // La consulta de áreas muestra la cuenta, el monto a cargar y la observación.
        $dist = \App\Models\Distribucion::where('tipo', 'areas')->first();
        $resp = $this->actingAs($this->operador())->get(route('operativo.distribucion.areas-consultar', $dist->id));
        $resp->assertOk();
        $resp->assertSee('14200530', false);
        $resp->assertSee('Solo parte este mes', false);
        $resp->assertSee('$12.000.000', false);

        // En "Mis distribuciones" la fila de otros costos ofrece "Editar montos".
        $this->actingAs($this->operador())->get(route('operativo.distribucion.consultas'))
            ->assertOk()->assertSee('Editar montos', false);
    }

    #[Test]
    public function el_supervisor_puede_reeditar_los_montos_de_otros_costos(): void
    {
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');
        $op = $this->operador();

        // La asistente guarda 12M...
        $this->actingAs($op)->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'monto' => ['MTO00099|14200530' => 12000000], 'obs' => ['MTO00099|14200530' => 'inicial'],
        ])->assertRedirect();

        // ...el supervisor revisa y reedita a 8M (mientras el cierre está abierto).
        $this->actingAs($op)->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'monto' => ['MTO00099|14200530' => 8000000], 'obs' => ['MTO00099|14200530' => 'ajustado'],
        ])->assertRedirect();

        // Se actualiza (no se duplica): un solo BolsaMonto y una sola distribución de áreas.
        $this->assertSame(1, BolsaMonto::where('un_codigo', 'MTO00099')->where('cuenta_14', '14200530')->count());
        $this->assertDatabaseHas('bolsa_montos', [
            'un_codigo' => 'MTO00099', 'cuenta_14' => '14200530',
            'monto_distribuir' => 8000000.00, 'observaciones' => 'ajustado',
        ]);
        $this->assertSame(1, \App\Models\Distribucion::where('tipo', 'areas')->count());
    }

    #[Test]
    public function no_se_pueden_editar_los_montos_si_el_cierre_no_esta_abierto(): void
    {
        // Cerramos el período que el trait abrió.
        \App\Models\CierrePeriodo::where('mes', 7)->where('anio', 2026)->update(['abierto' => false]);
        $this->bolsa('MTO00099', 20000000, 6, 2026, '14200530');

        $this->actingAs($this->operador())->post(route('operativo.distribucion.bolsa-montos'), [
            'mes' => 7, 'anio' => 2026, 'monto' => ['MTO00099|14200530' => 10000000],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, BolsaMonto::count());
    }

    #[Test]
    public function consume_las_cuentas_mas_antiguas_primero_a_traves_de_la_un(): void
    {
        // MTO00099 con dos cuentas de distintos períodos: 300 en 2026-04 y 500 en 2026-06.
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');
        $this->bolsa('MTO00099', 300, 4, 2026, '14200530'); // antigua
        $this->bolsa('MTO00099', 500, 6, 2026, '14200536'); // nueva

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(300, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->where('cuenta_14', '14200530')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(100, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->where('cuenta_14', '14200536')->sum('monto_aplicar'), 0.5);

        // Trazabilidad con UN + cuenta + período de origen.
        $asig = BolsaAsignacion::where('bolsa_codigo', 'mantenimiento')->where('codigo_proyecto', 'C-700')->first();
        $det  = collect($asig->detalle);
        $linea = $det->firstWhere('cuenta_14', '14200530');
        $this->assertSame('MTO00099', $linea['un_codigo']);
        $this->assertSame(202604, (int) $linea['periodo']);
    }

    #[Test]
    public function el_disponible_es_el_acumulado_al_mes_filtrado(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');
        $this->bolsa('MTO00099', 300, 6, 2026, '14200530'); // dentro del corte (jul)
        $this->bolsa('MTO00099', 900, 8, 2026, '14200530'); // posterior a jul

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertStatus(200);
        $resp->assertSee('id="bolsa-box-mantenimiento"', false);
        $resp->assertSee('$300', false);
        $resp->assertDontSee('$1.200', false);
    }

    #[Test]
    public function la_bolsa_grande_consolida_todas_las_un_del_departamento(): void
    {
        $this->bolsa('MTO00001', 300, 5, 2026, '14200530');
        $this->bolsa('MTO00001', 200, 6, 2026, '14200536');
        $this->bolsa('MTO00002', 400, 6, 2026, '14200530');
        $this->bolsa('MTO00003', 900, 8, 2026, '14200530'); // posterior → no cuenta a jul
        $this->bolsa('INS00001', 700, 6, 2026, '14200530'); // instalaciones

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);

        // Sin filtro: dos bolsas grandes.
        $todas = collect($svc->bolsasGrandes(null, $periodo, 2026, 7))->keyBy('codigo');
        $this->assertTrue($todas->has('mantenimiento'));
        $this->assertTrue($todas->has('instalaciones'));
        // Mantenimiento = MTO00001(500) + MTO00002(400) = 900 (MTO00003 es de agosto → fuera).
        $this->assertEqualsWithDelta(900, $todas['mantenimiento']['total'], 0.5);
        $this->assertEqualsWithDelta(700, $todas['instalaciones']['total'], 0.5);
        // Por defecto el "a distribuir" = saldo completo.
        $this->assertEqualsWithDelta(900, $todas['mantenimiento']['a_distribuir'], 0.5);

        // Filtro por departamento: solo esa bolsa grande, con sus líneas por UN+cuenta.
        $mant = collect($svc->bolsasGrandes('mantenimiento', $periodo, 2026, 7));
        $this->assertCount(1, $mant);
        $uns = collect($mant->first()['lineas'])->pluck('un_codigo')->unique()->values()->all();
        $this->assertContains('MTO00001', $uns);
        $this->assertContains('MTO00002', $uns);
        $this->assertNotContains('INS00001', $uns);
    }

    #[Test]
    public function el_panel_de_bolsas_se_muestra_aunque_no_haya_obras(): void
    {
        $this->bolsa('MTO00001', 500, 6, 2026, '14200530');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('BOLSAS DE ÁREA', false);
        $resp->assertSee('id="bolsa-box-mantenimiento"', false);
    }

    #[Test]
    public function el_por_repartir_de_la_bolsa_es_el_lado_negativo_no_el_positivo(): void
    {
        $this->bolsa('MTO00099', 41000000, 6, 2026, '14200530');                    // -41M por repartir
        $this->rf('MTO00099', 'Costos por aplicar', 500000, 6, 2026, '14200536');     // +500K reversado

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);
        $mant = collect($svc->bolsasGrandes('mantenimiento', $periodo, 2026, 7))->first();

        $this->assertEqualsWithDelta(41000000, $mant['total'], 0.5);
        $cuentas = collect($mant['lineas'])->pluck('cuenta_14')->all();
        $this->assertContains('14200530', $cuentas);
        $this->assertNotContains('14200536', $cuentas); // el reversado no cuenta
    }

    #[Test]
    public function obra_cerrada_genera_14_a_61_y_obra_abierta_genera_14_a_14_reclasificando_un(): void
    {
        // El plano usa origen_bolsa = UN real, sin cambios respecto al modelo anterior.
        $dist = Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false]);
        foreach ([['C-CERR', 500], ['C-ABIE', 400]] as [$cod, $monto]) {
            AplicacionCosto::create([
                'distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => $cod,
                'cuenta_14' => '14200530', 'origen_bolsa' => 'MTO00099', 'cuenta_61' => '61200530',
                'categoria' => 'MOI', 'nombre' => 'MO', 'monto_aplicar' => $monto, 'es_provision' => false, 'estado' => 'borrador',
            ]);
        }
        $lineas = AplicacionCosto::where('distribucion_id', $dist->id)->orderBy('codigo_proyecto')->get();

        $ctrl = new \App\Http\Controllers\Contable\PlanoContableController();
        $reparto = new \App\Services\RepartoFifoTerceros([]);
        $mov = $ctrl->construirMovimientos($lineas, $reparto, 99, '30020105', ['C-CERR' => true]);

        $debCerr = collect($mov)->first(fn ($m) => $m['unidad'] === 'C-CERR' && $m['debito'] > 0);
        $this->assertSame('61200530', $debCerr['cuenta']);
        $creCerr = collect($mov)->first(fn ($m) => $m['unidad'] === 'MTO00099' && $m['credito'] > 0 && abs($m['credito'] - 500) < 0.5);
        $this->assertSame('14200530', $creCerr['cuenta']);

        $debAbie = collect($mov)->first(fn ($m) => $m['unidad'] === 'C-ABIE' && $m['debito'] > 0);
        $this->assertSame('14200530', $debAbie['cuenta']);
        $this->assertNull($debAbie['centro_costos']);
        $creAbie = collect($mov)->first(fn ($m) => $m['unidad'] === 'MTO00099' && $m['credito'] > 0 && abs($m['credito'] - 400) < 0.5);
        $this->assertSame('14200530', $creAbie['cuenta']);

        $this->assertEqualsWithDelta(array_sum(array_column($mov, 'debito')), array_sum(array_column($mov, 'credito')), 0.5);
    }

    #[Test]
    public function el_servicio_agrupa_los_componentes_de_la_bolsa(): void
    {
        $svc = new DistribucionService();
        $comp = $svc->componentesDe([
            ['estructura' => 'MOI', 'pendiente' => 100],
            ['estructura' => 'MOFIJAOPER', 'pendiente' => 50],
            ['estructura' => 'MOE', 'pendiente' => 30],
            ['estructura' => 'EQU-MAT-SUM', 'pendiente' => 20],
        ]);

        $this->assertEqualsWithDelta(150, $comp['mo_directa']['monto'], 0.001);
        $this->assertEqualsWithDelta(30, $comp['terceros']['monto'], 0.001);
        $this->assertEqualsWithDelta(20, $comp['otros']['monto'], 0.001);
    }
}
