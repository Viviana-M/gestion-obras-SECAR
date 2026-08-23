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
        $this->rf('C-900', '14350105', 500000, 6, 2026, 'Materiales');
        // C-800 cerrada pero cuadrada (neto 0) → NO sale.
        $this->cerrar('C-800');
        $this->rf('C-800', '14350105', 0.0, 6, 2026);

        $resp = $this->actingAs($this->contable())->get(route('contable.plano-reversion.index'));

        $resp->assertStatus(200);
        $resp->assertSee('saldos contrarios', false);
        $resp->assertSee('C-900', false);
        $resp->assertSee('14350105', false);
        $resp->assertSee('Materiales', false);
        $resp->assertDontSee('C-800', false);

        $filas = $resp->viewData('filas');
        $this->assertCount(1, $filas);
        $this->assertSame('C-900', $filas[0]['proyecto']);
        $this->assertEqualsWithDelta(500000, $filas[0]['saldo'], 0.5);
        $this->assertEqualsWithDelta(500000, $resp->viewData('total'), 0.5);
    }

    #[Test]
    public function descarga_el_plano_en_excel(): void
    {
        $this->cerrar('C-900');
        $this->rf('C-900', '14350105', 500000);

        $resp = $this->actingAs($this->contable())->get(route('contable.plano-reversion.excel'));

        $resp->assertOk();
        $this->assertStringContainsString('spreadsheetml', $resp->headers->get('content-type'));
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_entrar(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true,
            'permisos_modulos' => ['comercial' => 'ver']]);

        $this->actingAs($sin)->get(route('contable.plano-reversion.index'))->assertForbidden();
    }
}
