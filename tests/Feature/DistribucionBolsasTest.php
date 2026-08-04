<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\BolsaAsignacion;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Concerns\AbrePeriodoCierre;

/**
 * Bolsas de área integradas en la pantalla de Distribución de costos:
 * panel de origen (bolsa), asignación bolsa→obra, tope por disponible y persistencia.
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

    /** Movimiento de cuenta 14 de una BOLSA: el costo por repartir va NEGATIVO (como en proyectos). */
    private function bolsa(string $codigo, float $monto, int $mes, int $anio, string $cc = '14200530'): void
    {
        $this->rf($codigo, 'Costos por aplicar', -abs($monto), $mes, $anio, $cc);
    }

    /**
     * Obra C-700 (mantenimiento) con ingreso y un pendiente propio de cuenta 14, más
     * la bolsa MTO00099 con 1000 por repartir (estado_er negativo = costo por distribuir).
     */
    private function seedBase(): void
    {
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105'); // pendiente propio
        $this->bolsa('MTO00099', 1000, 6, 2026); // bolsa: por repartir
    }

    #[Test]
    public function el_panel_de_bolsas_muestra_el_disponible_y_el_control_de_asignacion(): void
    {
        $this->seedBase();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('BOLSAS DE ÁREA', false);
        $resp->assertSee('id="bolsa-box-MTO00099"', false);
        $resp->assertSee('id="bolsa-disp-MTO00099"', false);
        $resp->assertSee('Asignar desde bolsa de área', false);
        $resp->assertSee('id="asignbolsa-cta-C-700"', false);
        // La bolsa no aparece como una obra en la lista.
        $resp->assertDontSee('id="card-MTO00099"', false);
    }

    #[Test]
    public function asignar_desde_bolsa_persiste_y_refleja_el_costo_en_el_proyecto(): void
    {
        $this->seedBase();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ])->assertRedirect();

        // Asignación persistida.
        $this->assertDatabaseHas('bolsa_asignaciones', [
            'bolsa_codigo' => 'MTO00099', 'codigo_proyecto' => 'C-700', 'monto' => 400.00,
        ]);
        // Reflejada como costo del proyecto (línea con origen_bolsa) para el plano/resumen.
        $linea = AplicacionCosto::where('codigo_proyecto', 'C-700')->where('origen_bolsa', 'MTO00099')->first();
        $this->assertNotNull($linea);
        $this->assertSame('14200530', $linea->cuenta_14);
        $this->assertEqualsWithDelta(400, (float) $linea->monto_aplicar, 0.5);
    }

    #[Test]
    public function no_permite_asignar_mas_que_el_saldo_de_la_bolsa(): void
    {
        $this->seedBase(); // bolsa disponible = 1000

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 3000]]],
        ])->assertRedirect();

        // Se recorta al disponible (1000), nunca más.
        $this->assertEqualsWithDelta(1000, (float) BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->sum('monto'), 0.5);
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function dos_obras_consumen_la_misma_bolsa_sin_exceder_el_total(): void
    {
        $this->seedBase(); // bolsa 1000
        $this->rf('C-800', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-800', 'Costos por aplicar', -50, 6, 2026, '14350105');

        // A pide 600, B pide 700 => 1300 > 1000. El total no puede pasar de 1000.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => [
                'C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 600]],
                'C-800' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 700]],
            ],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(1000, (float) BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->sum('monto'), 0.5);
        // El plano no acredita la cuenta 14 de la bolsa por más de su saldo.
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function al_recargar_el_borrador_baja_el_disponible_de_la_bolsa(): void
    {
        $this->seedBase();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ])->assertRedirect();

        $dist = Distribucion::first();
        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?dist='.$dist->id);

        $resp->assertStatus(200);
        // Disponible = 1000 - 400 = 600.
        $resp->assertSee('$600', false);
        // El chip guardado se vuelve a pintar.
        $resp->assertSee('Desde <b>MTO00099</b>', false);
    }

    #[Test]
    public function consume_las_cuentas_14_mas_antiguas_primero_y_guarda_la_trazabilidad(): void
    {
        // Bolsa con dos cuentas de distintos períodos: 300 en 2026-04 (más antigua) y
        // 500 en 2026-06 (más nueva). Asignar 400 debe consumir primero la de abril
        // (300 completo) y 100 de la de junio.
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');
        $this->bolsa('MTO00099', 300, 4, 2026, '14200530'); // antigua
        $this->bolsa('MTO00099', 500, 6, 2026, '14200536'); // nueva

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ])->assertRedirect();

        // La cuenta antigua se consume completa; la nueva solo lo que falta.
        $this->assertEqualsWithDelta(300, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->where('cuenta_14', '14200530')->sum('monto_aplicar'), 0.5);
        $this->assertEqualsWithDelta(100, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->where('cuenta_14', '14200536')->sum('monto_aplicar'), 0.5);

        // Trazabilidad persistida con las cuentas y períodos de origen.
        $asig = BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->where('codigo_proyecto', 'C-700')->first();
        $this->assertNotNull($asig);
        $det = collect($asig->detalle);
        $this->assertSame(202604, (int) $det->firstWhere('cuenta_14', '14200530')['periodo']);
        $this->assertEqualsWithDelta(300, (float) $det->firstWhere('cuenta_14', '14200530')['monto'], 0.5);
        $this->assertEqualsWithDelta(100, (float) $det->firstWhere('cuenta_14', '14200536')['monto'], 0.5);
    }

    #[Test]
    public function obra_cerrada_genera_14_a_61_y_obra_abierta_genera_14_a_14_reclasificando_un(): void
    {
        // Dos líneas origen_bolsa iguales, una a obra cerrada y otra a obra abierta.
        // (La cuenta 61 va directa en la línea, no hace falta homologación.)
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
        // Solo C-CERR está cerrada/parcial.
        $mov = $ctrl->construirMovimientos($lineas, $reparto, 99, '30020105', ['C-CERR' => true]);

        // Obra CERRADA: débito en cuenta 61, UN = obra destino.
        $debCerr = collect($mov)->first(fn ($m) => $m['unidad'] === 'C-CERR' && $m['debito'] > 0);
        $this->assertSame('61200530', $debCerr['cuenta']);
        // Crédito de esa obra: cuenta 14 en la UN de la bolsa (origen).
        $creCerr = collect($mov)->first(fn ($m) => $m['unidad'] === 'MTO00099' && $m['credito'] > 0 && abs($m['credito'] - 500) < 0.5);
        $this->assertSame('14200530', $creCerr['cuenta']);

        // Obra ABIERTA: débito 14→14 (misma cuenta 14) en la UN de la obra destino.
        $debAbie = collect($mov)->first(fn ($m) => $m['unidad'] === 'C-ABIE' && $m['debito'] > 0);
        $this->assertSame('14200530', $debAbie['cuenta']);
        $this->assertNull($debAbie['centro_costos']); // en cuenta 14 no va centro de costos
        // Crédito de la abierta: cuenta 14 en la UN de la bolsa.
        $creAbie = collect($mov)->first(fn ($m) => $m['unidad'] === 'MTO00099' && $m['credito'] > 0 && abs($m['credito'] - 400) < 0.5);
        $this->assertSame('14200530', $creAbie['cuenta']);

        // El plano cuadra.
        $this->assertEqualsWithDelta(array_sum(array_column($mov, 'debito')), array_sum(array_column($mov, 'credito')), 0.5);
    }

    #[Test]
    public function el_disponible_es_el_acumulado_al_mes_filtrado_no_los_periodos_posteriores(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105');
        // Bolsa: 300 hasta el mes filtrado (jul) y 900 en un mes POSTERIOR (ago).
        $this->bolsa('MTO00099', 300, 6, 2026, '14200530'); // dentro del corte
        $this->bolsa('MTO00099', 900, 8, 2026, '14200530'); // posterior a jul

        // El panel del mes 7 debe mostrar solo 300 disponible (no 1.200).
        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertStatus(200);
        $resp->assertSee('id="bolsa-box-MTO00099"', false);
        $resp->assertSee('$300', false);
        $resp->assertDontSee('$1.200', false);

        // Y el servidor no deja asignar más que ese disponible acumulado (300).
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 1000]]],
        ])->assertRedirect();
        $this->assertEqualsWithDelta(300, (float) BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->sum('monto'), 0.5);
        $this->assertEqualsWithDelta(300, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function el_panel_trae_todas_las_un_activas_del_departamento_con_saldo_acumulado(): void
    {
        // Mantenimiento: dos UN con saldo hasta jul; una solo con saldo POSTERIOR (ago).
        $this->bolsa('MTO00001', 300, 5, 2026, '14200530');
        $this->bolsa('MTO00001', 200, 6, 2026, '14200536');
        $this->bolsa('MTO00002', 400, 6, 2026, '14200530');
        $this->bolsa('MTO00003', 900, 8, 2026, '14200530'); // posterior a jul
        // Instalaciones: una UN con saldo.
        $this->bolsa('INS00001', 700, 6, 2026, '14200530');

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);

        // Filtro Mantenimiento: trae TODAS las UN de mantenimiento con saldo acumulado a jul.
        $mant = collect($svc->bolsasDelDepartamento('mantenimiento', $periodo, 2026, 7))->keyBy('codigo');
        $this->assertTrue($mant->has('MTO00001'));
        $this->assertTrue($mant->has('MTO00002'));
        $this->assertFalse($mant->has('MTO00003')); // su único movimiento es en agosto → oculta
        $this->assertFalse($mant->has('INS00001')); // otro departamento
        $this->assertEqualsWithDelta(500, $mant['MTO00001']['total'], 0.5); // 300+200 acumulado a jul
        $this->assertEqualsWithDelta(400, $mant['MTO00002']['total'], 0.5);

        // Filtro "Todos" (sin departamento): UN de ambos departamentos.
        $todas = collect($svc->bolsasDelDepartamento(null, $periodo, 2026, 7))->keyBy('codigo');
        $this->assertTrue($todas->has('MTO00001'));
        $this->assertTrue($todas->has('MTO00002'));
        $this->assertTrue($todas->has('INS00001'));
        $this->assertFalse($todas->has('MTO00003'));
    }

    #[Test]
    public function el_por_repartir_de_la_bolsa_es_el_lado_negativo_no_el_positivo(): void
    {
        // El costo por aplicar se guarda NEGATIVO. Una cuenta con saldo positivo es un
        // reversado y NO cuenta como por repartir.
        $this->bolsa('MTO00099', 41000000, 6, 2026, '14200530');                       // -41M = por repartir
        $this->rf('MTO00099', 'Costos por aplicar', 500000, 6, 2026, '14200536');        // +500K reversado

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);
        $b = collect($svc->bolsasDelDepartamento('mantenimiento', $periodo, 2026, 7))->keyBy('codigo');

        $this->assertTrue($b->has('MTO00099'));
        // Toma el saldo real (~41M), no el lado positivo (500K).
        $this->assertEqualsWithDelta(41000000, $b['MTO00099']['total'], 0.5);
        $cuentas = collect($b['MTO00099']['lineas'])->pluck('cuenta_14')->all();
        $this->assertContains('14200530', $cuentas);      // negativa: por repartir
        $this->assertNotContains('14200536', $cuentas);   // positiva (reversado): no cuenta
    }

    #[Test]
    public function el_panel_de_bolsas_se_muestra_aunque_no_haya_obras(): void
    {
        // Solo bolsas con saldo, ninguna obra con cuenta 14 propia.
        $this->bolsa('MTO00001', 500, 6, 2026, '14200530');
        $this->bolsa('MTO00002', 400, 6, 2026, '14200530');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('BOLSAS DE ÁREA', false);
        $resp->assertSee('id="bolsa-box-MTO00001"', false);
        $resp->assertSee('id="bolsa-box-MTO00002"', false);
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
