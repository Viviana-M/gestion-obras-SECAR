<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consulta de Provisiones (cuenta 26) POR OBRA, solo lectura: saldo real por obra tras el mismo cruce
 * FIFO de la cuenta 14, con filtros (departamento, período, buscar obra), total, desglose por
 * cuenta/tercero y Excel.
 */
class ConsultaProvisionesTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver'],
        ]);
    }

    /** Fila de cuenta 26 (provisión) para un tercero en una obra/U.N. */
    private function prov(string $cod, string $nit, string $razon, float $debito, float $credito, int $mes = 7, int $anio = 2026, ?string $fechaDocto = null, string $cuenta = '26050501'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => $cuenta, 'cuenta_mayor' => 'Provisiones',
            'tercero_dcto' => $nit, 'razon_social' => $razon,
            'valor_debito' => $debito, 'valor_credito' => $credito, 'fecha_docto' => $fechaDocto,
            'estado_er' => $debito - $credito, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function seedProvisiones(): void
    {
        // C-700 (mantenimiento): un tercero con crédito → saldo -500.000.
        $this->prov('C-700', '900100', 'Proveedor A', 0, 500000);
        // C-701 (mantenimiento): un tercero con débito → saldo +100.000.
        $this->prov('C-701', '900100', 'Proveedor A', 100000, 0);
        // O0001 (instalaciones): crédito → saldo -300.000.
        $this->prov('O0001', '900200', 'Proveedor B', 0, 300000);
    }

    #[Test]
    public function muestra_el_saldo_de_provision_por_obra_con_total(): void
    {
        $this->seedProvisiones();

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $filas = collect($resp->viewData('filas'))->keyBy('codigo');
        $this->assertEqualsWithDelta(-500000, $filas['C-700']['saldo'], 0.5);
        $this->assertEqualsWithDelta(100000, $filas['C-701']['saldo'], 0.5);
        $this->assertEqualsWithDelta(-300000, $filas['O0001']['saldo'], 0.5);
        $this->assertEqualsWithDelta(-700000, $resp->viewData('total'), 0.5);

        $resp->assertSee('C-700', false);
    }

    #[Test]
    public function el_filtro_por_departamento_acota_las_obras(): void
    {
        $this->seedProvisiones();

        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);
        $resp = $this->actingAs($admin)
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']));
        $resp->assertOk();

        $cods = collect($resp->viewData('filas'))->pluck('codigo')->all();
        sort($cods);
        $this->assertSame(['C-700', 'C-701'], $cods);              // instalaciones (O0001) queda fuera
        $this->assertEqualsWithDelta(-400000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function el_buscador_filtra_por_obra(): void
    {
        $this->seedProvisiones();

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026, 'q' => 'C-701']));
        $resp->assertOk();

        $this->assertSame(['C-701'], collect($resp->viewData('filas'))->pluck('codigo')->all());
    }

    #[Test]
    public function al_expandir_una_obra_trae_el_detalle_por_cuenta_tercero(): void
    {
        // C-702 con dos terceros (sin negativos): ambos sobreviven y forman el desglose.
        $this->prov('C-702', '900111', 'Proveedor X', 400000, 0, 7, 2026, null, '26050501');
        $this->prov('C-702', '900222', 'Proveedor Y', 200000, 0, 7, 2026, null, '26050502');

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $obra = collect($resp->viewData('filas'))->firstWhere('codigo', 'C-702');
        $this->assertEqualsWithDelta(600000, $obra['saldo'], 0.5);
        $this->assertCount(2, $obra['detalle']);
        $this->assertEqualsWithDelta(400000, abs($obra['detalle'][0]['saldo']), 0.5); // mayor |saldo| primero
        $this->assertSame('26050501', $obra['detalle'][0]['cuenta']);
    }

    #[Test]
    public function oculta_obras_cuyo_saldo_queda_en_cero(): void
    {
        $this->prov('C-999', '900300', 'Proveedor Cero', 200000, 200000);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $this->assertNotContains('C-999', collect($resp->viewData('filas'))->pluck('codigo')->all());
    }

    #[Test]
    public function sin_cuenta_26_la_consulta_sale_vacia(): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Proy', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'valor_debito' => 0, 'valor_credito' => 0,
            'estado_er' => -100, 'mes' => 7, 'anio' => 2026,
        ]);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk()->assertSee('No hay provisiones', false);
        $this->assertCount(0, $resp->viewData('filas'));
    }

    #[Test]
    public function descarga_el_excel_por_obra(): void
    {
        Excel::fake();
        $this->seedProvisiones();

        $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones.excel', ['mes' => 7, 'anio' => 2026]))
            ->assertOk();

        Excel::assertDownloaded('Provisiones_todos_202607.xlsx', function ($export) {
            $plano = json_encode($export->array(), JSON_UNESCAPED_UNICODE);
            return str_contains($plano, 'Obra')
                && str_contains($plano, 'Saldo provisión')
                && str_contains($plano, 'C-700')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function el_credito_de_secar_consume_los_debitos_en_fifo_dentro_de_la_llave(): void
    {
        // Misma obra+cuenta: P (más antiguo) 1.000.000, Q (más reciente) 500.000, y SECAR −800.000.
        // SECAR consume primero el débito más antiguo (P): P→200.000, Q intacto → la obra queda en 700.000.
        $this->prov('C-900', '900111', 'Prov P', 1000000, 0, 7, 2026, '2026-05-10');
        $this->prov('C-900', '900222', 'Prov Q', 500000, 0, 7, 2026, '2026-06-20');
        $this->prov('C-900', '890319324', 'SECAR SAS', 0, 800000, 7, 2026, '2026-06-30');

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $obra = collect($resp->viewData('filas'))->firstWhere('codigo', 'C-900');
        $this->assertEqualsWithDelta(700000, $obra['saldo'], 0.5);
        $det = collect($obra['detalle'])->keyBy('nit');
        $this->assertEqualsWithDelta(200000, $det['900111']['saldo'], 0.5);  // P consumido por SECAR
        $this->assertEqualsWithDelta(500000, $det['900222']['saldo'], 0.5);  // Q intacto
        $this->assertArrayNotHasKey('890319324', $det->all());               // SECAR queda en $0, no se lista
    }

    #[Test]
    public function la_mano_de_obra_se_consume_antes_que_los_otros_terceros(): void
    {
        \App\Models\TerceroManoObra::create(['cedula' => '555', 'nombre' => 'Obrero', 'departamento' => 'mantenimiento', 'activo' => true]);

        // No-MO más antiguo (300.000), MO más reciente (300.000) y SECAR −300.000 en la misma llave.
        $this->prov('C-910', '900333', 'Otros Costos SAS', 300000, 0, 7, 2026, '2026-04-01');
        $this->prov('C-910', '555', 'Obrero', 300000, 0, 7, 2026, '2026-05-01');
        $this->prov('C-910', '890319324', 'SECAR SAS', 0, 300000, 7, 2026, '2026-06-01');

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $obra = collect($resp->viewData('filas'))->firstWhere('codigo', 'C-910');
        $this->assertEqualsWithDelta(300000, $obra['saldo'], 0.5);
        $det = collect($obra['detalle'])->keyBy('nit');
        $this->assertArrayNotHasKey('555', $det->all());                     // MO consumida primero
        $this->assertEqualsWithDelta(300000, $det['900333']['saldo'], 0.5);  // sobrevive otros costos
    }

    #[Test]
    public function un_usuario_sin_operacion_no_entra(): void
    {
        $sin = User::factory()->create([
            'rol' => 'comercial', 'activo' => true, 'permisos_modulos' => ['comercial' => 'ver'],
        ]);
        $this->actingAs($sin)->get(route('operativo.consulta-provisiones'))->assertForbidden();
    }
}
