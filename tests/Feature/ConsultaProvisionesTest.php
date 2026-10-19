<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consulta de Provisiones (cuenta 26) por tercero, solo lectura: saldo Libro 1 (débito − crédito)
 * por tercero, con filtros (departamento, período, buscador), total, desglose por proyecto y Excel.
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

    /** Fila de cuenta 26 (provisión) para un tercero en un proyecto/U.N. */
    private function prov(string $cod, string $nit, string $razon, float $debito, float $credito, int $mes = 7, int $anio = 2026, ?string $fechaDocto = null): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => '26050501', 'cuenta_mayor' => 'Provisiones',
            'tercero_dcto' => $nit, 'razon_social' => $razon,
            'valor_debito' => $debito, 'valor_credito' => $credito, 'fecha_docto' => $fechaDocto,
            'estado_er' => $debito - $credito, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function seedProvisiones(): void
    {
        // Tercero A (mantenimiento, prefijo C): -500.000 en C-700 y +100.000 en C-701 → -400.000.
        $this->prov('C-700', '900100', 'Proveedor A', 0, 500000);
        $this->prov('C-701', '900100', 'Proveedor A', 100000, 0);
        // Tercero B (instalaciones, prefijo O): -300.000.
        $this->prov('O0001', '900200', 'Proveedor B', 0, 300000);
    }

    #[Test]
    public function muestra_el_saldo_de_provision_por_tercero_con_total(): void
    {
        $this->seedProvisiones();

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $filas = collect($resp->viewData('filas'))->keyBy('nit');
        $this->assertEqualsWithDelta(-400000, $filas['900100']['saldo'], 0.5); // Libro 1 = déb − créd
        $this->assertEqualsWithDelta(-300000, $filas['900200']['saldo'], 0.5);
        $this->assertEqualsWithDelta(-700000, $resp->viewData('total'), 0.5);

        $resp->assertSee('Proveedor A', false);
        $resp->assertSee('900200', false);
    }

    #[Test]
    public function el_filtro_por_departamento_acota_los_terceros(): void
    {
        $this->seedProvisiones();

        // Admin elige mantenimiento: solo los proyectos con prefijo de mantenimiento (C-700/C-701).
        $admin = User::factory()->create(['rol' => 'admin', 'activo' => true]);
        $resp = $this->actingAs($admin)
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']));
        $resp->assertOk();

        $nits = collect($resp->viewData('filas'))->pluck('nit')->all();
        $this->assertContains('900100', $nits);
        $this->assertNotContains('900200', $nits);                 // instalaciones queda fuera
        $this->assertEqualsWithDelta(-400000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function el_buscador_filtra_por_nit_o_razon(): void
    {
        $this->seedProvisiones();

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026, 'q' => 'Proveedor B']));
        $resp->assertOk();

        $nits = collect($resp->viewData('filas'))->pluck('nit')->all();
        $this->assertSame(['900200'], $nits);
    }

    #[Test]
    public function un_tercero_con_varios_proyectos_trae_su_desglose(): void
    {
        $this->seedProvisiones();

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $a = collect($resp->viewData('filas'))->firstWhere('nit', '900100');
        $this->assertCount(2, $a['proyectos']);                    // C-700 y C-701
        $this->assertEqualsWithDelta(500000, abs($a['proyectos'][0]['saldo']), 0.5); // mayor |saldo| primero
    }

    #[Test]
    public function oculta_terceros_cuyo_saldo_queda_en_cero(): void
    {
        // Tercero que netea a 0 (débito = crédito): no debe aparecer.
        $this->prov('C-700', '900300', 'Proveedor Cero', 200000, 200000);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $this->assertNotContains('900300', collect($resp->viewData('filas'))->pluck('nit')->all());
    }

    #[Test]
    public function sin_cuenta_26_la_consulta_sale_vacia(): void
    {
        // Solo hay cuenta 14, no 26.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Proy', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'valor_debito' => 0, 'valor_credito' => 0,
            'estado_er' => -100, 'mes' => 7, 'anio' => 2026,
        ]);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk()
            ->assertSee('No hay provisiones', false);
        $this->assertCount(0, $resp->viewData('filas'));
    }

    #[Test]
    public function descarga_el_excel_con_las_mismas_columnas(): void
    {
        Excel::fake();
        $this->seedProvisiones();

        $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones.excel', ['mes' => 7, 'anio' => 2026]))
            ->assertOk();

        Excel::assertDownloaded('Provisiones_todos_202607.xlsx', function ($export) {
            $plano = json_encode($export->array(), JSON_UNESCAPED_UNICODE);
            return str_contains($plano, 'Nit')
                && str_contains($plano, 'Razón social')
                && str_contains($plano, 'Saldo provisión')
                && str_contains($plano, 'Proveedor A')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function el_credito_de_secar_consume_los_debitos_en_fifo_dentro_de_la_llave(): void
    {
        // Misma (U.N., cuenta): P (más antiguo) 1.000.000, Q (más reciente) 500.000, y SECAR −800.000.
        // El reverso de SECAR consume primero el débito más antiguo (P): P→200.000, Q intacto, SECAR→0.
        $this->prov('C-900', '900111', 'Prov P', 1000000, 0, 7, 2026, '2026-05-10');
        $this->prov('C-900', '900222', 'Prov Q', 500000, 0, 7, 2026, '2026-06-20');
        $this->prov('C-900', '890319324', 'SECAR SAS', 0, 800000, 7, 2026, '2026-06-30');

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $filas = collect($resp->viewData('filas'))->keyBy('nit');
        $this->assertEqualsWithDelta(200000, $filas['900111']['saldo'], 0.5); // P consumido por SECAR
        $this->assertEqualsWithDelta(500000, $filas['900222']['saldo'], 0.5); // Q intacto
        $this->assertArrayNotHasKey('890319324', $filas->all());               // SECAR queda en $0
        $this->assertEqualsWithDelta(700000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function la_mano_de_obra_se_consume_antes_que_los_otros_terceros(): void
    {
        // La MO (directa/apoyo) entra al cruce y se consume PRIMERO, aunque sea más reciente.
        \App\Models\TerceroManoObra::create(['cedula' => '555', 'nombre' => 'Obrero', 'departamento' => 'mantenimiento', 'activo' => true]);

        // No-MO más antiguo (300.000), MO más reciente (300.000) y SECAR −300.000 en la misma llave.
        $this->prov('C-910', '900333', 'Otros Costos SAS', 300000, 0, 7, 2026, '2026-04-01');
        $this->prov('C-910', '555', 'Obrero', 300000, 0, 7, 2026, '2026-05-01');
        $this->prov('C-910', '890319324', 'SECAR SAS', 0, 300000, 7, 2026, '2026-06-01');

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.consulta-provisiones', ['mes' => 7, 'anio' => 2026]));
        $resp->assertOk();

        $filas = collect($resp->viewData('filas'))->keyBy('nit');
        // La MO se consumió primero (queda en $0, no aparece); sobrevive el tercero de otros costos.
        $this->assertArrayNotHasKey('555', $filas->all());
        $this->assertEqualsWithDelta(300000, $filas['900333']['saldo'], 0.5);
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
