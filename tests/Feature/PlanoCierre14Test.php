<?php

namespace Tests\Feature;

use App\Http\Controllers\Contable\PlanoCierre14Controller;
use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Plano de reclasificación de saldos de cuenta 14 al costo (cierre de proyectos inactivos):
 * CR 14 / DB 61 homologada, mismo tercero, centro de costos por departamento, cuadrado a $0,
 * en el formato de 4 hojas de SIESA. Filtrable por departamento/proyecto/monto.
 */
class PlanoCierre14Test extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function homolog(string $c14, string $c61): void
    {
        Homologacion::create(['cuenta_14' => $c14, 'cuenta_61' => $c61, 'nombre' => 'Cuenta '.$c14,
            'estructura' => 'EQU-MAT-SUM', 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
    }

    /** Saldo de cuenta 14 por proyecto/cuenta/tercero en Libro 1 (débito − crédito). */
    private function rf(string $cod, string $c14, string $tercero, float $deb, float $cred, int $mes = 8, int $anio = 2024): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod, 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $tercero, 'razon_social' => 'T '.$tercero,
            'estado_er' => -($deb - $cred), 'valor_debito' => $deb, 'valor_credito' => $cred, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function svc(): PlanoCierre14Controller
    {
        return new PlanoCierre14Controller();
    }

    #[Test]
    public function reclasifica_el_saldo_positivo_cr_14_db_61_con_tercero_y_centro_de_costos(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09100', 'nombre_obra' => 'Inactiva mant', 'activa' => false]);
        // Saldo pendiente con centavos: débito 1.287.000,50 − crédito 0.
        $this->rf('MOB09100', '14350105', '900', 1287000.50, 0);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null);
        $movs = collect($r['movimientos']);

        $this->assertCount(2, $movs);
        $cr = $movs->firstWhere('cuenta', '14350105');
        $db = $movs->firstWhere('cuenta', '61350105');

        // Crédito a la 14 (sin centro de costos), con el tercero del saldo.
        $this->assertEqualsWithDelta(1287000.50, $cr['credito'], 0.001);
        $this->assertEqualsWithDelta(0, $cr['debito'], 0.001);
        $this->assertNull($cr['centro_costos']);
        $this->assertSame('900', $cr['tercero']);
        $this->assertSame('CCC', $cr['tipo_doc']);

        // Débito a la 61 homologada, con centro de costos de mantenimiento y el mismo tercero.
        $this->assertEqualsWithDelta(1287000.50, $db['debito'], 0.001);
        $this->assertEqualsWithDelta(0, $db['credito'], 0.001);
        $this->assertSame('30020105', $db['centro_costos']);
        $this->assertSame('900', $db['tercero']);
        $this->assertSame('MOB09100', $db['unidad']);

        // Cuadra a $0 (débitos = créditos), con centavos.
        $this->assertEqualsWithDelta($r['total_debito'], $r['total_credito'], 0.001);
        $this->assertEqualsWithDelta(1287000.50, $r['total_debito'], 0.001);
    }

    #[Test]
    public function el_saldo_a_favor_contrario_invierte_la_partida(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09101', 'nombre_obra' => 'A favor', 'activa' => false]);
        // Saldo a favor: crédito 500.000 − débito 0 = −500.000.
        $this->rf('MOB09101', '14350105', '900', 0, 500000);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null);
        $movs = collect($r['movimientos']);

        $cr14 = $movs->firstWhere('cuenta', '14350105');
        $db61 = $movs->firstWhere('cuenta', '61350105');
        // Se invierte: DB 14 / CR 61, para dejar la 14 en $0.
        $this->assertEqualsWithDelta(500000, $cr14['debito'], 0.001);
        $this->assertEqualsWithDelta(500000, $db61['credito'], 0.001);
        $this->assertEqualsWithDelta($r['total_debito'], $r['total_credito'], 0.001);
    }

    #[Test]
    public function netea_por_proyecto_cuenta_y_tercero_antes_de_reclasificar(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09102', 'nombre_obra' => 'Neteo', 'activa' => false]);
        // Mismo tercero/cuenta en dos meses: 30.000 + 20.000 = 50.000 (una sola línea por par).
        $this->rf('MOB09102', '14350105', '900', 30000, 0, 7, 2024);
        $this->rf('MOB09102', '14350105', '900', 20000, 0, 8, 2024);
        // Otro tercero en la misma cuenta: línea aparte.
        $this->rf('MOB09102', '14350105', '901', 10000, 0, 8, 2024);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null);
        $movs = collect($r['movimientos']);

        // 2 terceros × 2 patas = 4 líneas.
        $this->assertCount(4, $movs);
        $cr900 = $movs->first(fn ($m) => $m['cuenta'] === '14350105' && $m['tercero'] === '900');
        $this->assertEqualsWithDelta(50000, $cr900['credito'], 0.001);
        $this->assertEqualsWithDelta(60000, $r['total_debito'], 0.001); // 50k + 10k
    }

    #[Test]
    public function omite_las_cuentas_14_sin_homologar(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'MOB09103', 'nombre_obra' => 'Sin homol', 'activa' => false]);
        $this->rf('MOB09103', '14990099', '900', 100000, 0); // no hay Homologacion para esta cuenta

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null);
        $this->assertSame(1, $r['sin_homologar']);
        $this->assertEmpty($r['movimientos']);
    }

    #[Test]
    public function filtra_por_monto_minimo_del_saldo_por_proyecto(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09104', 'nombre_obra' => 'Chica', 'activa' => false]);
        FichaProyecto::create(['codigo_proyecto' => 'MOB09105', 'nombre_obra' => 'Grande', 'activa' => false]);
        $this->rf('MOB09104', '14350105', '900', 50000, 0);
        $this->rf('MOB09105', '14350105', '900', 200000, 0);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null, [], 100000);
        $unidades = collect($r['movimientos'])->pluck('unidad')->unique()->values()->all();
        $this->assertSame(['MOB09105'], $unidades);
    }

    #[Test]
    public function reclasifica_solo_los_proyectos_seleccionados(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09106', 'nombre_obra' => 'A', 'activa' => false]);
        FichaProyecto::create(['codigo_proyecto' => 'MOB09107', 'nombre_obra' => 'B', 'activa' => false]);
        $this->rf('MOB09106', '14350105', '900', 40000, 0);
        $this->rf('MOB09107', '14350105', '900', 70000, 0);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null, ['MOB09106']);
        $this->assertSame(['MOB09106'], collect($r['movimientos'])->pluck('unidad')->unique()->values()->all());
    }

    #[Test]
    public function sin_seleccion_toma_solo_los_proyectos_inactivos_con_saldo(): void
    {
        $this->homolog('14350105', '61350105');
        // Inactiva: entra. Activa: NO (sin selección explícita, solo inactivos).
        FichaProyecto::create(['codigo_proyecto' => 'MOB09108', 'nombre_obra' => 'Inactiva', 'activa' => false]);
        FichaProyecto::create(['codigo_proyecto' => 'MOB09109', 'nombre_obra' => 'Activa', 'activa' => true]);
        $this->rf('MOB09108', '14350105', '900', 40000, 0);
        $this->rf('MOB09109', '14350105', '900', 70000, 0);

        $r = $this->svc()->construirMovimientos(8, 2024, $this->contable(), null);
        $this->assertSame(['MOB09108'], collect($r['movimientos'])->pluck('unidad')->unique()->values()->all());
    }

    #[Test]
    public function la_descarga_entrega_el_excel_para_contabilidad(): void
    {
        $this->homolog('14350105', '61350105');
        FichaProyecto::create(['codigo_proyecto' => 'MOB09110', 'nombre_obra' => 'Inactiva', 'activa' => false]);
        $this->rf('MOB09110', '14350105', '900', 123456.78, 0);

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.plano-cierre-14.descargar', ['mes' => 8, 'anio' => 2024, 'documento' => 55]));
        $resp->assertOk();
        $resp->assertHeader('content-disposition');
        $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
    }

    #[Test]
    public function sin_nada_que_reclasificar_vuelve_con_error(): void
    {
        $this->actingAs($this->contable())
            ->get(route('contable.plano-cierre-14.descargar', ['mes' => 8, 'anio' => 2024]))
            ->assertRedirect();
        $this->assertNotNull(session('error'));
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_descargar(): void
    {
        $this->actingAs($this->operador())
            ->get(route('contable.plano-cierre-14.descargar', ['mes' => 8, 'anio' => 2024]))
            ->assertForbidden();
    }
}
