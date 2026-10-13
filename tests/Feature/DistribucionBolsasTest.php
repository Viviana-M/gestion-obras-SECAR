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
        $resp->assertSee('Saldo de la bolsa', false);              // número grande = neto
        $resp->assertSee('Disponible por distribuir', false);
        $resp->assertSee('id="bolsa-box-mantenimiento"', false);   // bolsa grande por departamento
        $resp->assertSee('id="bolsa-neto-mantenimiento"', false);  // el número grande es el saldo neto
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
    public function una_orden_abierta_sin_ingreso_recibe_costo_de_bolsa_sin_autorizacion(): void
    {
        // C-900 es una orden ABIERTA sin ingreso en el mes (solo saldo en cuenta 14),
        // p. ej. mano de obra del supervisor. Debe poder recibir costo desde la bolsa
        // SIN autorización (antes se bloqueaba); se reclasifica 14→14 hacia la OT.
        $this->rf('C-900', 'Costos por aplicar', -100, 6, 2026, '14350105');
        $this->bolsa('MTO00099', 1000, 6, 2026);

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'estado_obra'      => ['C-900' => 'abierta'],
            'asignacion_bolsa' => ['C-900' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ])->assertRedirect();

        $linea = AplicacionCosto::where('codigo_proyecto', 'C-900')->where('origen_bolsa', 'MTO00099')->first();
        $this->assertNotNull($linea, 'La orden sin ingreso debe recibir el costo de la bolsa sin autorización.');
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
        $resp->assertSee('Queda mes sig.', false);               // encabezado de la columna (tabla plana)
        $resp->assertSee('id="ftQueda-mantenimiento"', false);   // total de la columna (fila de totales)
        $resp->assertSee('$5.000.000', false);                   // 20M − 15M = 5M queda
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
    public function el_detalle_trae_filtros_alerta_de_periodo_anterior_y_conciliacion(): void
    {
        // Cerrando septiembre 2026. Una línea del mes (sept) y otra de un mes ANTERIOR (agosto),
        // más una cuenta que neta negativo (reversado de más) para la conciliación del footer.
        $rf = function (string $cc, string $t, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $cc,
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => 'Tercero '.$t,
                'tipo_documento' => 'FSE', 'documento' => substr($cc, -2), 'fecha_docto' => $fecha,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'mes' => (int) substr($fecha, 5, 2), 'anio' => 2026,
            ]);
        };
        $rf('14200530', '111', 1000000, 0, '2026-09-10'); // mes de cierre → normal
        $rf('14200531', '222', 400000, 0, '2026-08-05');  // mes anterior → ANTERIOR
        $rf('14200599', '999', 0, 300000, '2026-09-30');  // cuenta neta negativa → reversado de más

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=9&anio=2026&departamento=mantenimiento&vista=todo');

        $resp->assertStatus(200);
        $html = $resp->getContent();

        // 1) Fila de filtros por columna + botón limpiar + encabezado/filtros sticky (clase bd-filtros).
        $this->assertStringContainsString('bd-filtros', $html);
        $this->assertStringContainsString('limpiarFiltrosBolsa(', $html);
        $this->assertStringContainsString('filtrarBolsaDet(', $html);

        // 2) Alerta de periodo anterior: indicador "Cerrando", leyenda, fila marcada y etiqueta ANTERIOR.
        $this->assertStringContainsString('Cerrando: Septiembre 2026', $html);
        $this->assertStringContainsString('bd-anterior', $html);
        $this->assertStringContainsString('ANTERIOR', $html);

        // 3) Conciliación en el footer: Disponible − Reversado = Saldo de la bolsa (neto).
        $this->assertStringContainsString('= Saldo de la bolsa (neto)', $html);
        $this->assertStringContainsString('(−) Reversado de más / por revisar', $html);

        // El servicio cumple la invariante: neto = disponible − reversado.
        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 9);
        $mant = collect($svc->bolsasGrandes('mantenimiento', $periodo, 2026, 9, 'todo'))->first();
        $this->assertEqualsWithDelta(1400000, $mant['total'], 1);          // disponible = 1M + 400k
        $this->assertEqualsWithDelta(300000, $mant['total_reversado'], 1); // reversado
        $this->assertEqualsWithDelta(1100000, $mant['neto'], 1);           // 1.4M − 0.3M
        $this->assertEqualsWithDelta($mant['total'] - $mant['total_reversado'], $mant['neto'], 1);
    }

    #[Test]
    public function el_panel_de_bolsas_se_muestra_aunque_no_haya_obras(): void
    {
        $this->bolsa('MTO00001', 500, 6, 2026, '14200530');

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Disponible por distribuir', false);
        $resp->assertSee('id="bolsa-box-mantenimiento"', false);
    }

    #[Test]
    public function la_vista_mes_muestra_el_neto_del_mes_y_todo_el_acumulado(): void
    {
        // Movimiento en agosto (−10M) y en septiembre (−15M) en la misma bolsa/cuenta.
        $this->bolsa('INS00099', 10000000, 8, 2026, '14200530');
        $this->bolsa('INS00099', 15000000, 9, 2026, '14200530');

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 9);

        // "Solo el movimiento del mes" (septiembre) → solo 15M.
        $mesVista = collect($svc->bolsasGrandes('instalaciones', $periodo, 2026, 9, 'mes'))->first();
        $this->assertEqualsWithDelta(15000000, $mesVista['total'], 1);

        // "Todo el inventario pendiente" (acumulado a septiembre) → 25M.
        $todo = collect($svc->bolsasGrandes('instalaciones', $periodo, 2026, 9, 'todo'))->first();
        $this->assertEqualsWithDelta(25000000, $todo['total'], 1);
    }

    /** Movimiento de cuenta 14 de una UN de bolsa con un TERCERO concreto (costo por repartir va negativo). */
    private function bolsaTercero(string $un, string $cc, string $tercero, string $razon, float $monto, int $mes, int $anio): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $un, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $cc,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $tercero, 'razon_social' => $razon,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function el_total_de_la_bolsa_suma_todos_los_terceros_desglosados_por_cuenta(): void
    {
        // Instalaciones en septiembre: varios terceros en la misma cuenta 14 de una UN.
        // El total NO debe colapsar a un solo tercero (MAX): debe sumar TODOS = SUM(débito − crédito).
        $this->bolsaTercero('INS00099', '14200530', '111', 'JUAN PEREZ',    9000000, 9, 2026);
        $this->bolsaTercero('INS00099', '14200530', '222', 'MARIA GOMEZ',   4760000, 9, 2026);
        $this->bolsaTercero('INS00099', '14200530', '333', 'PEDRO LOPEZ',   2000000, 9, 2026);

        $svc     = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 9);

        // El saldo de la cuenta = suma de los tres terceros = 15,76M.
        $saldos = $svc->saldosBolsasPorCuenta(['INS00099'], $periodo, 2026, 9, 'mes');
        $linea  = collect($saldos['INS00099'])->firstWhere('cuenta_14', '14200530');
        $this->assertEqualsWithDelta(15760000, $linea['pendiente'], 1);

        // Una fila por cada tercero con su saldo, ordenadas de mayor a menor.
        $this->assertCount(3, $linea['terceros']);
        $this->assertSame(['111', '222', '333'], array_column($linea['terceros'], 'doc'));
        $this->assertEqualsWithDelta(15760000, array_sum(array_column($linea['terceros'], 'saldo')), 1);

        // El total de la bolsa grande también suma todas las líneas.
        $bolsa = collect($svc->bolsasGrandes('instalaciones', $periodo, 2026, 9, 'mes'))->first();
        $this->assertEqualsWithDelta(15760000, $bolsa['total'], 1);
        // La línea de la bolsa trae su desglose por tercero para pintar una fila por cada uno.
        $l = collect($bolsa['lineas'])->firstWhere('cuenta_14', '14200530');
        $this->assertCount(3, $l['terceros']);
    }

    #[Test]
    public function el_detalle_oculta_las_lineas_que_quedan_en_cero_tras_cruzar_secar(): void
    {
        // Cuenta 14209999: ABC +500k y SECAR −500k. El cruce FIFO deja ambas en $0 (ya cruzadas).
        $this->bolsaTercero('MTO00099', '14209999', '111', 'ABC SAS', 500000, 7, 2026); // estado_er −500k → +500k
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14209999',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '890319324', 'razon_social' => 'SECAR',
            'estado_er' => 500000, 'valor_debito' => 0, 'valor_credito' => 500000, 'mes' => 7, 'anio' => 2026,
        ]);
        // Cuenta con saldo real.
        $this->bolsaTercero('MTO00099', '14200530', '222', 'AIR EZ SAS', 300000, 7, 2026);

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        // Las líneas en $0 (ABC y SECAR, cruzadas) se marcan como ocultas (clase bz-row) con "cruzado".
        $this->assertStringContainsString('bz-row', $html);
        $this->assertStringContainsString('cruzado', $html);
        // Banner de ocultos + contador de líneas con saldo (solo 1 con saldo real).
        $this->assertStringContainsString('Se ocultaron', $html);
        $this->assertStringContainsString('Líneas con saldo:', $html);
        // El interruptor para auditar las $0 existe.
        $this->assertStringContainsString('Mostrar lo ya cruzado', $html);
        // La cuenta con saldo real sí aparece.
        $this->assertStringContainsString('14200530', $html);
    }

    #[Test]
    public function el_cruce_fifo_de_secar_atribuye_el_neto_al_tercero_correcto(): void
    {
        // Cuenta 14200563: Jaramillo +260k (más antiguo), ABC +7.007.956 (más reciente),
        // SECAR −6.647.620. FIFO: SECAR consume Jaramillo completo y 6.387.620 de ABC →
        // ABC queda en 620.336; Jaramillo y SECAR en $0. El neto de la cuenta no cambia.
        $rf = function (string $t, string $razon, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'INS00001', 'nombre_proyecto' => 'Capacitación', 'cuenta_contable' => '14200563',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => $razon,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 8, 'anio' => 2026,
            ]);
        };
        $rf('1130627016', 'Jaramillo Carlos', 260000, 0, '2026-06-10');
        $rf('901510086',  'ABC Formación',   7007956, 0, '2026-08-18');
        $rf('890319324',  'SECAR',           0, 6647620, '2026-09-30');

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 8);
        $det = $svc->detalleBolsaTerceros(['INS00001'], $periodo, 2026, 8, 'todo');
        $por = collect($det['INS00001'])->keyBy('tercero_dcto');

        $this->assertEqualsWithDelta(620336, $por['901510086']['saldo'], 1);  // ABC remanente
        $this->assertEqualsWithDelta(0, $por['1130627016']['saldo'], 1);      // Jaramillo consumido
        $this->assertEqualsWithDelta(0, $por['890319324']['saldo'], 1);       // SECAR cruzado
        $this->assertTrue($por['1130627016']['cero']);
        $this->assertTrue($por['890319324']['cero']);
        // El neto total de la cuenta se conserva (260k + 7.007.956 − 6.647.620 = 620.336).
        $this->assertEqualsWithDelta(620336, collect($det['INS00001'])->sum('saldo'), 1);
    }

    #[Test]
    public function el_cruce_fifo_cruza_negativos_de_cualquier_tercero_no_solo_secar(): void
    {
        // Cuenta 14200563 de INS00002: dos entradas positivas (Prov A más antiguo, Prov B más reciente)
        // y una NOTA CRÉDITO negativa de un tercero REAL (no SECAR). El pool negativo consume los
        // positivos por antigüedad: A completo y el resto de B. El neto de la cuenta no cambia.
        $rf = function (string $t, string $razon, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'INS00002', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14200563',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => $razon,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 8, 'anio' => 2026,
            ]);
        };
        $rf('111', 'Proveedor A',   300000, 0, '2026-06-10');   // positivo, más antiguo
        $rf('222', 'Proveedor B',   500000, 0, '2026-08-20');   // positivo, más reciente
        $rf('333', 'Nota Credito X', 0, 400000, '2026-09-30');  // NEGATIVO, tercero real (no SECAR)

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 8);
        $det = $svc->detalleBolsaTerceros(['INS00002'], $periodo, 2026, 8, 'todo');
        $por = collect($det['INS00002'])->keyBy('tercero_dcto');

        $this->assertEqualsWithDelta(0, $por['111']['saldo'], 1);          // A consumido completo
        $this->assertTrue($por['111']['cero']);
        $this->assertEqualsWithDelta(400000, $por['222']['saldo'], 1);     // B queda con 500k − 100k
        $this->assertEqualsWithDelta(0, $por['333']['saldo'], 1);          // la nota crédito se cruza
        $this->assertTrue($por['333']['cero']);
        $this->assertFalse($por['333']['alerta']);                         // hay positivos suficientes
        // Neto conservado: 300k + 500k − 400k = 400k.
        $this->assertEqualsWithDelta(400000, collect($det['INS00002'])->sum('saldo'), 1);
    }

    #[Test]
    public function si_la_cuenta_neta_negativo_el_sobrante_es_alerta_de_solo_lectura(): void
    {
        // Cuenta 14200599 de INS00003: una entrada +200k y una nota crédito −500k. Los positivos no
        // alcanzan; el sobrante (−300k) se marca como ALERTA (reversado de más), no como saldo a distribuir.
        $rf = function (string $t, string $razon, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'INS00003', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14200599',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => $razon,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 8, 'anio' => 2026,
            ]);
        };
        $rf('111', 'Proveedor A',    200000, 0, '2026-06-10');
        $rf('999', 'Nota Credito Y', 0, 500000, '2026-09-30');

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 8);
        $det = $svc->detalleBolsaTerceros(['INS00003'], $periodo, 2026, 8, 'todo');
        $por = collect($det['INS00003'])->keyBy('tercero_dcto');

        $this->assertEqualsWithDelta(0, $por['111']['saldo'], 1);
        $this->assertTrue($por['111']['cero']);
        $this->assertEqualsWithDelta(-300000, $por['999']['saldo'], 1);    // sobrante negativo
        $this->assertTrue($por['999']['alerta']);
        $this->assertFalse($por['999']['cero']);
        $this->assertEqualsWithDelta(0, $por['999']['monto_distribuir'], 1); // no se distribuye
        // El neto de la cuenta se conserva (200k − 500k = −300k).
        $this->assertEqualsWithDelta(-300000, collect($det['INS00003'])->sum('saldo'), 1);
    }

    #[Test]
    public function tras_el_cruce_sobrevive_el_documento_reciente_con_su_remanente_no_el_agregado(): void
    {
        // Cuenta 14200563 de INS00006, mismo tercero: dos documentos positivos (uno antiguo, uno
        // reciente) y una nota crédito que consume. El FIFO consume el MÁS ANTIGUO primero: FSE-1 cae a
        // $0 y FSE-2 (reciente) sobrevive con su remanente. La fila debe mostrar la fecha/documento del
        // que sobrevive (FSE-2, reciente), con su remanente — NO la fecha del antiguo ni el saldo sumado.
        $rf = function (string $t, string $tipo, string $doc, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'INS00006', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14200563',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => 'Tercero '.$t,
                'tipo_documento' => $tipo, 'documento' => $doc,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 8, 'anio' => 2026,
            ]);
        };
        $rf('111', 'FSE', '1', 300000, 0, '2026-06-10');   // documento antiguo (+300k)
        $rf('111', 'FSE', '2', 500000, 0, '2026-08-20');   // documento reciente (+500k)
        $rf('222', 'NC',  '9', 0, 400000, '2026-09-30');   // nota crédito (−400k) consume FIFO

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 8);
        $det = collect($svc->detalleBolsaTerceros(['INS00006'], $periodo, 2026, 8, 'todo')['INS00006']);

        // El documento reciente sobrevive con su remanente (500k − 100k = 400k) y su propia fecha.
        $reciente = $det->firstWhere('documento', 'FSE-00000002');
        $this->assertNotNull($reciente);
        $this->assertEqualsWithDelta(400000, $reciente['saldo'], 1);
        $this->assertSame('2026-08-20', $reciente['fecha']);
        $this->assertFalse($reciente['cero']);

        // El documento antiguo queda consumido en $0 (oculto como cruzado), con su propia fecha.
        $antiguo = $det->firstWhere('documento', 'FSE-00000001');
        $this->assertNotNull($antiguo);
        $this->assertEqualsWithDelta(0, $antiguo['saldo'], 1);
        $this->assertSame('2026-06-10', $antiguo['fecha']);
        $this->assertTrue($antiguo['cero']);

        // El neto de la cuenta se conserva (300k + 500k − 400k = 400k), todo en el documento superviviente.
        $this->assertEqualsWithDelta(400000, $det->sum('saldo'), 1);
    }

    #[Test]
    public function el_cruce_no_pasa_entre_distintas_un_aunque_compartan_la_cuenta(): void
    {
        // Misma cuenta 14200563 en DOS U.N. distintas. INS00004 neta negativo (reversado de más) y
        // INS00005 tiene un positivo sano. La llave del cruce es (U.N., cuenta): el sobrante negativo de
        // INS00004 NO debe comerse el positivo de INS00005.
        $rf = function (string $un, string $t, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => $un, 'nombre_proyecto' => 'Obra '.$un, 'cuenta_contable' => '14200563',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => 'Tercero '.$t,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 8, 'anio' => 2026,
            ]);
        };
        $rf('INS00004', '111', 200000, 0, '2026-06-10');  // UN1: positivo +200k
        $rf('INS00004', '999', 0, 500000, '2026-09-30');  // UN1: nota crédito −500k → neto −300k
        $rf('INS00005', '222', 400000, 0, '2026-07-15');  // UN2: positivo +400k (otra U.N.)

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 8);
        $det = $svc->detalleBolsaTerceros(['INS00004', 'INS00005'], $periodo, 2026, 8, 'todo');

        // UN1: su positivo se consume y el sobrante queda como alerta (−300k), aislado en su U.N.
        $un1 = collect($det['INS00004'])->keyBy('tercero_dcto');
        $this->assertEqualsWithDelta(0, $un1['111']['saldo'], 1);
        $this->assertTrue($un1['111']['cero']);
        $this->assertEqualsWithDelta(-300000, $un1['999']['saldo'], 1);
        $this->assertTrue($un1['999']['alerta']);

        // UN2: su positivo queda INTACTO (el negativo de la otra U.N. no lo tocó).
        $un2 = collect($det['INS00005'])->keyBy('tercero_dcto');
        $this->assertEqualsWithDelta(400000, $un2['222']['saldo'], 1);
        $this->assertFalse($un2['222']['cero']);
        $this->assertFalse($un2['222']['alerta']);
    }

    #[Test]
    public function el_detalle_muestra_el_reversado_de_mas_como_alerta_en_pantalla(): void
    {
        // MTO00099 (mantenimiento): entrada +200k y nota crédito −500k en una cuenta → neto −300k.
        $rf = function (string $t, float $deb, float $cred, string $fecha) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14207777',
                'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $t, 'razon_social' => 'Tercero '.$t,
                'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred,
                'fecha_docto' => $fecha, 'mes' => 7, 'anio' => 2026,
            ]);
        };
        $rf('111', 200000, 0, '2026-06-10');
        $rf('999', 0, 500000, '2026-09-30');

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertOk()->getContent();

        $this->assertStringContainsString('bz-alerta', $html);
        $this->assertStringContainsString('reversado de más', $html);
    }

    #[Test]
    public function la_bolsa_de_otros_costos_solo_considera_cuentas_1420(): void
    {
        // En la bolsa MTO00099 conviven una 1420 (otros costos) y una 1455 (inventario en tránsito).
        // La bolsa / disponible / detalle / cruce deben considerar SOLO la 1420; la 1455 se excluye
        // (sigue cargada para inventario, pero fuera de la bolsa de otros costos).
        $this->bolsaTercero('MTO00099', '14200530', '111', 'MO SAS',        1000000, 6, 2026); // 1420 → bolsa
        $this->bolsaTercero('MTO00099', '14552599', '222', 'MATERIALES AA', 4000000, 6, 2026); // 1455 → inventario

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);

        // Disponible de la tarjeta: solo la 1420 (1M); la 1455 (4M) NO entra.
        $mant = collect($svc->bolsasGrandes('mantenimiento', $periodo, 2026, 7))->first();
        $this->assertEqualsWithDelta(1000000, $mant['total'], 0.5);
        $cuentas = collect($mant['lineas'])->pluck('cuenta_14')->all();
        $this->assertContains('14200530', $cuentas);
        $this->assertNotContains('14552599', $cuentas);

        // Detalle por cuenta: tampoco incluye la 1455.
        $det = $svc->detalleBolsaTerceros(['MTO00099'], $periodo, 2026, 7, 'todo');
        $cuentasDet = collect($det['MTO00099'] ?? [])->pluck('cuenta_14')->unique()->values()->all();
        $this->assertSame(['14200530'], $cuentasDet);

        // Pero la 1455 SÍ sigue cargada en la base (solo se excluye del cálculo de la bolsa).
        $this->assertSame(1, RegistroFinanciero::where('cuenta_contable', '14552599')->count());
    }

    #[Test]
    public function la_bolsa_distribuye_solo_los_netos_positivos_y_reporta_aparte_los_negativos(): void
    {
        $this->bolsa('MTO00099', 41000000, 6, 2026, '14200530');                    // +41M por repartir (distribuible)
        $this->rf('MTO00099', 'Costos por aplicar', 500000, 6, 2026, '14200536');     // −500K neto (reversado de más)

        $svc = new DistribucionService();
        $periodo = \App\Models\Homologacion::periodo(2026, 7);
        $mant = collect($svc->bolsasGrandes('mantenimiento', $periodo, 2026, 7))->first();

        // "Disponible por distribuir" = solo el neto POSITIVO (41M); el negativo NO se resta.
        $this->assertEqualsWithDelta(41000000, $mant['total'], 0.5);
        // El neto negativo se reporta aparte como "reversado de más / por revisar" (en positivo).
        $this->assertEqualsWithDelta(500000, $mant['total_reversado'], 0.5);

        $lineas = collect($mant['lineas'])->keyBy('cuenta_14');
        $this->assertTrue($lineas->has('14200530'));
        $this->assertTrue($lineas->has('14200536'));                    // el contrario SÍ aparece (marcado reversado)
        // La cuenta positiva se distribuye completa; la negativa no (pendiente 0, marcada reversado).
        $this->assertEqualsWithDelta(41000000, $lineas['14200530']['pendiente'], 0.5);
        $this->assertFalse($lineas['14200530']['reversado']);
        $this->assertEqualsWithDelta(0, $lineas['14200536']['pendiente'], 0.5);
        $this->assertTrue($lineas['14200536']['reversado']);
        $this->assertEqualsWithDelta(-500000, $lineas['14200536']['saldo'], 0.5); // conserva su signo real

        // El disponible a distribuir (suma de pendientes) coincide con el total distribuible.
        $this->assertEqualsWithDelta(41000000, collect($mant['lineas'])->sum('pendiente'), 0.5);
        // El neto contable se conserva en 'saldo' (41M − 0.5M = 40.5M).
        $this->assertEqualsWithDelta(40500000, collect($mant['lineas'])->sum('saldo'), 0.5);

        // El total de la tarjeta COINCIDE con el total distribuible del detalle (líneas no-alerta).
        $det = $svc->detalleBolsaTerceros(['MTO00099'], $periodo, 2026, 7, 'todo');
        $distribuibleDetalle = collect($det['MTO00099'] ?? [])->reject(fn ($l) => $l['alerta'])->sum('saldo');
        $this->assertEqualsWithDelta($mant['total'], $distribuibleDetalle, 0.5);

        // Jerarquía de la tarjeta: Saldo neto = Disponible − Reversado (reconcilia con contabilidad).
        $this->assertEqualsWithDelta(40500000, $mant['neto'], 0.5);                    // 41M − 0.5M
        $this->assertEqualsWithDelta($mant['total'] - $mant['total_reversado'], $mant['neto'], 0.5);
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
    public function el_plano_de_bolsa_acredita_la_14_con_el_tercero_real_no_con_secar(): void
    {
        // Saldo en la cuenta 14 de la bolsa (MTO00099): proveedor real 901510086 por 500k
        // (entrada = estado_er negativo), y un reverso de SECAR (estado_er positivo) que se consume
        // FIFO. La reversión del plano debe salir a nombre del proveedor real, no de SECAR.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14250101',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '901510086', 'razon_social' => 'ABC SAS',
            'estado_er' => -500000, 'valor_debito' => 500000, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);

        $dist = Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false]);
        AplicacionCosto::create([
            'distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'C-CERR',
            'cuenta_14' => '14250101', 'origen_bolsa' => 'MTO00099', 'cuenta_61' => '61250101',
            'categoria' => 'EQU-MAT-SUM', 'nombre' => 'Materiales', 'monto_aplicar' => 500000, 'es_provision' => false, 'estado' => 'borrador',
        ]);
        $lineas = AplicacionCosto::where('distribucion_id', $dist->id)->get();

        $ctrl = new \App\Http\Controllers\Contable\PlanoContableController();
        $reparto = new \App\Services\RepartoFifoTerceros(['MTO00099', 'C-CERR']); // incluye la UN de la bolsa
        $mov = $ctrl->construirMovimientos($lineas, $reparto, 99, '30020105', ['C-CERR' => true]);

        // CR cuenta 14 en la bolsa, a nombre del proveedor REAL (no SECAR 890319324).
        $cr = collect($mov)->first(fn ($m) => $m['unidad'] === 'MTO00099' && $m['credito'] > 0);
        $this->assertSame('14250101', $cr['cuenta']);
        $this->assertSame('901510086', $cr['tercero']);
        $this->assertNotSame('890319324', $cr['tercero']);
        // DB cuenta 61 en la obra destino, mismo tercero real.
        $db = collect($mov)->first(fn ($m) => $m['unidad'] === 'C-CERR' && $m['debito'] > 0);
        $this->assertSame('61250101', $db['cuenta']);
        $this->assertSame('901510086', $db['tercero']);
        // Cuadra.
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
