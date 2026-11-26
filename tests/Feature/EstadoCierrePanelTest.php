<?php

namespace Tests\Feature;

use App\Http\Controllers\Operativo\DistribucionCostosController;
use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\ManoObraAsignacion;
use App\Models\Provision;
use App\Models\RegistroFinanciero;
use App\Models\UnBolsa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Panel "Estado del cierre · en vivo": el Ingreso/Costo por departamento que alimenta el panel debe
 * coincidir EXACTAMENTE con "Ver resumen" (construirResumen) y el desglose debe sumar el costo total.
 */
class EstadoCierrePanelTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $cod, string $cm, float $er, string $cc, string $origen = 'biable'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod, 'cuenta_contable' => $cc,
            'cuenta_mayor' => $cm, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0,
            'origen' => $origen, 'mes' => 10, 'anio' => 2026,
        ]);
    }

    /** Escenario de Instalaciones (prefijo O) en oct-2026 con todas las piezas del costo. */
    private function seedInstalaciones(): void
    {
        // Ingreso del mes.
        $this->rf('O-7001', 'Ingreso', 1_000_000, '41350100');
        // Saldo 1420 para que la obra se liste (y el panel se muestre).
        $this->rf('O-7001', 'Costos por aplicar', -500_000, '14200105');
        // Costo ya en cuenta 6: base (80k) + ajuste de contabilidad / reverso (20k).
        $this->rf('O-7001', 'Costos aplicados', 80_000, '61300105', 'biable');
        $this->rf('O-7001', 'Costos aplicados', -20_000, '61300105', 'reverso_plano');

        // Distribución GUARDADA (enviada): inventario propio (300k) + otros costos de bolsa (200k).
        $d = Distribucion::create([
            'mes' => 10, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'enviado', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => 'O-7001', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'inv', 'monto_aplicar' => 300_000, 'es_provision' => false, 'estado' => 'enviado']);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => 'O-7001', 'cuenta_14' => '14200530', 'cuenta_61' => '61350105',
            'categoria' => 'MAT', 'nombre' => 'otros', 'monto_aplicar' => 200_000, 'es_provision' => false,
            'origen_bolsa' => 'IN-UN1', 'estado' => 'enviado']);

        // Provisión (50k) y mano de obra (100k) del depto.
        Provision::create(['codigo_proyecto' => 'O-7001', 'departamento' => 'instalaciones',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 50_000, 'descripcion' => 'x',
            'mes' => 10, 'anio' => 2026, 'estado' => 'activa']);
        UnBolsa::create(['codigo' => 'IN-UN1', 'departamento' => 'instalaciones', 'nombre' => 'UN IN', 'activo' => true]);
        ManoObraAsignacion::create(['bolsa_un' => 'IN-UN1', 'cuenta_14' => '14200530', 'obra_destino' => 'O-7001',
            'monto' => 100_000, 'mes' => 10, 'anio' => 2026, 'persona' => '123', 'tercero' => '123',
            'tercero_nombre' => 'Juan', 'origen' => 'manual']);
    }

    #[Test]
    public function el_ingreso_y_costo_del_panel_coinciden_con_ver_resumen(): void
    {
        $this->seedInstalaciones();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones');
        $resp->assertOk();

        $ing = $resp->viewData('ingresoPorDepto');
        $cos = $resp->viewData('costoGuardadoPorDepto');
        $det = $resp->viewData('costoDetPorDepto');

        // Ingreso = facturación del mes; Costo = base(80k) + ajuste(20k) + inv(300k) + otros(200k) + prov(50k) + MO(100k).
        $this->assertEqualsWithDelta(1_000_000, $ing['instalaciones'], 1);
        $this->assertEqualsWithDelta(750_000, $cos['instalaciones'], 1);

        // El desglose SUMA el costo total (inventario + otros + MO + provisión + base/ajustes).
        $di = $det['instalaciones'];
        $this->assertEqualsWithDelta(300_000, $di['inventario'], 1);
        $this->assertEqualsWithDelta(200_000, $di['otros'], 1);
        $this->assertEqualsWithDelta(100_000, $di['mano_obra'], 1);
        $this->assertEqualsWithDelta(50_000, $di['provision'], 1);
        $this->assertEqualsWithDelta(100_000, $di['base'], 1);   // base(80k) + ajuste(20k)
        $this->assertEqualsWithDelta($cos['instalaciones'],
            $di['inventario'] + $di['otros'] + $di['mano_obra'] + $di['provision'] + $di['base'], 1);

        // REGLA DE ORO: el costo del panel es EXACTAMENTE el costo_total de construirResumen (Ver resumen).
        $m = new ReflectionMethod(DistribucionCostosController::class, 'construirResumen');
        $m->setAccessible(true);
        $ctrl = app(DistribucionCostosController::class);
        $aplicar = ['O-7001' => ['14200105' => 300_000, '14200530' => 200_000]];
        $datos = $m->invoke($ctrl, 10, 2026, 'instalaciones', $aplicar, []);
        $this->assertEqualsWithDelta($datos['todo']['costo_total'], $cos['instalaciones'], 1);
        $this->assertEqualsWithDelta($datos['todo']['ingreso'], $ing['instalaciones'], 1);
    }

    #[Test]
    public function el_panel_se_renderiza_con_desglose_y_dice_otros_costos(): void
    {
        $this->seedInstalaciones();

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones')
            ->assertOk()->getContent();

        // Panel presente, en la columna derecha, con encabezado "en vivo" y los ids que mueve el JS.
        $this->assertStringContainsString('id="estado-cierre-panel"', $html);
        $this->assertStringContainsString('Estado del cierre', $html);
        $this->assertStringContainsString('En vivo', $html);
        $this->assertStringContainsString('id="ec-ingreso"', $html);
        $this->assertStringContainsString('id="ec-costo"', $html);
        $this->assertStringContainsString('id="ec-mc"', $html);
        $this->assertStringContainsString('id="ec-mcpct"', $html);
        // Toggle depto filtrado / Total mes.
        $this->assertStringContainsString("ecSetScope('total')", $html);
        // Desglose dice "Otros costos" (no "bolsa").
        $this->assertStringContainsString('>Otros costos<', $html);
        // Las constantes del período llegan al front para el Total mes (costo de instalaciones = 750k).
        $this->assertStringContainsString('const EC_COSTO', $html);
        $this->assertStringContainsString('750000', $html);
    }

    #[Test]
    public function el_init_engancha_los_handlers_antes_de_pintar_el_panel(): void
    {
        // Regresión: un fallo del panel al cargar no debe dejar sin handler a "Con/Sin ingreso".
        // Los enlaces de UI se registran ANTES del pintado del panel, y el panel va aislado en try/catch.
        $this->seedInstalaciones();

        $html = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=10&anio=2026&departamento=instalaciones')
            ->assertOk()->getContent();

        $handlerCon = strpos($html, "on('grupo-head-con'");
        $handlerSin = strpos($html, "on('grupo-head-sin'");
        $pintaPanel = strpos($html, 'ecSetScope(ecScope)');
        $this->assertNotFalse($handlerCon);
        $this->assertNotFalse($pintaPanel);
        // Los handlers de los grupos se enganchan ANTES de pintar el panel.
        $this->assertLessThan($pintaPanel, $handlerCon);
        $this->assertLessThan($pintaPanel, $handlerSin);

        // El pintado del panel está aislado (try/catch), igual que la llamada interna del panel.
        $this->assertStringContainsString('try { ecSetScope(ecScope); }', $html);
        $this->assertStringContainsString('try { actualizarEstadoCierre(); }', $html);
    }
}
