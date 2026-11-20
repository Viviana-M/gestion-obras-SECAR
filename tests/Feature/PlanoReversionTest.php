<?php

namespace Tests\Feature;

use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Módulo de Contabilidad: plano de cuentas 14 con saldos contrarios (reversión).
 * Vista previa de las cuentas 14 con saldo del lado equivocado + descarga del plano.
 */
class PlanoReversionTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'ver'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function cerrar(string $cod): void
    {
        ProyectoCerrado::create([
            'codigo_proyecto' => $cod, 'tipo_cierre' => 'total', 'user_id' => User::factory()->create()->id,
        ]);
    }

    private function rf(string $cod, string $cuenta, float $er, int $mes = 6, int $anio = 2026, string $desc = 'Inventario'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => 'Costos por aplicar', 'descripcion' => $desc, 'estado_er' => $er,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function la_pagina_lista_las_cuentas_14_con_saldo_contrario(): void
    {
        // C-900 cerrada con saldo POSITIVO en la 14 (reversado de más) → sale en la lista.
        $this->cerrar('C-900');
        $this->rf('C-900', '14200105', 500000, 6, 2026, 'Materiales');
        // C-800 cerrada pero cuadrada (neto 0) → NO sale.
        $this->cerrar('C-800');
        $this->rf('C-800', '14200105', 0.0, 6, 2026);

        $resp = $this->actingAs($this->contable())->get(route('contable.plano-reversion.index'));

        $resp->assertStatus(200);
        $resp->assertSee('saldos contrarios', false);
        $resp->assertSee('C-900', false);
        $resp->assertSee('14200105', false);
        $resp->assertSee('Materiales', false);
        $resp->assertDontSee('C-800', false);

        $filas = $resp->viewData('filas');
        $this->assertCount(1, $filas);
        $this->assertSame('C-900', $filas[0]['proyecto']);
        $this->assertEqualsWithDelta(500000, $filas[0]['saldo'], 0.5);
        $this->assertEqualsWithDelta(500000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function excluye_el_1455_y_las_obras_con_1420_neto_cero(): void
    {
        // O0008566 cerrada: 1420 neta ≈ 0 (subcuentas opuestas) + un 1455 enorme (inventario).
        $this->cerrar('O0008566');
        $this->rf('O0008566', '14205505', 170148, 6, 2026, 'A');
        $this->rf('O0008566', '14209595', -162308, 6, 2026, 'B');
        $this->rf('O0008566', '14200569', -2702, 6, 2026);
        $this->rf('O0008566', '14208002', -5138, 6, 2026);
        $this->rf('O0008566', '14552599', -50749742, 6, 2026, 'Inventario'); // 1455: NO debe entrar
        // Obra cerrada con 1420 neto reversado (positivo) sí entra.
        $this->cerrar('C-900');
        $this->rf('C-900', '14200105', 500000, 6, 2026, 'Reversado');

        $resp  = $this->actingAs($this->contable())->get(route('contable.plano-reversion.index'));
        $filas = collect($resp->viewData('filas'));

        $this->assertContains('C-900', $filas->pluck('proyecto')->all());
        $this->assertNotContains('O0008566', $filas->pluck('proyecto')->all()); // neto ≈ 0 → no reverso
        $this->assertTrue($filas->every(fn ($f) => ! str_starts_with($f['cuenta'], '1455'))); // sin 1455
    }

    #[Test]
    public function el_plano_tiene_las_mismas_columnas_que_el_cierre_contable(): void
    {
        $this->cerrar('C-900');
        $this->rf('C-900', '14200105', 500000);

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.plano-reversion.excel', ['documento' => 77]));
        $resp->assertOk();
        $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));

        // El archivo trae las 4 hojas SIESA y las 12 columnas del detalle (igual que el cierre).
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->getFile()->getPathname());
        $this->assertSame(
            ['Documentocontable', 'Movimientocontable', 'MovimientoCxC', 'MovimientoCxP'],
            $ss->getSheetNames()
        );
        $mov = $ss->getSheetByName('Movimientocontable');
        $this->assertSame('Tipo de documento', $mov->getCell('A1')->getValue());
        $this->assertSame('Auxiliar de cuenta contable', $mov->getCell('C1')->getValue());
        $this->assertSame('Unidad de negocio', $mov->getCell('E1')->getValue());
        // Primera línea del asiento: la cuenta 14 en la unidad C-900 con el documento 77.
        $this->assertSame('14200105', (string) $mov->getCell('C2')->getValue());
        $this->assertSame('C-900', (string) $mov->getCell('E2')->getValue());
        $this->assertSame('77', (string) $mov->getCell('B2')->getValue());
    }


    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_entrar(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true,
            'permisos_modulos' => ['comercial' => 'ver']]);

        $this->actingAs($sin)->get(route('contable.plano-reversion.index'))->assertForbidden();
    }
}
