<?php

namespace Tests\Feature;

use App\Models\CierrePeriodo;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Punto 1: la Distribución es de solo lectura hasta que Contabilidad abre el cierre
 * del mes. Con el cierre abierto, Operaciones puede editar; al cerrar, vuelve a solo lectura.
 */
class CierrePeriodoTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function obraConSaldo(): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 9000000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);
    }

    #[Test]
    public function con_el_cierre_cerrado_la_distribucion_es_solo_lectura(): void
    {
        $this->obraConSaldo();
        // Sin registro de cierre → cerrado (mes en curso).
        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('Mes en curso — solo lectura', false);
    }

    #[Test]
    public function el_servidor_rechaza_guardar_si_el_cierre_no_esta_abierto(): void
    {
        $this->obraConSaldo();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14350105' => 500]],
        ])->assertRedirect()->assertSessionHas('error');

        // No se creó ninguna distribución.
        $this->assertDatabaseCount('distribuciones', 0);
    }

    #[Test]
    public function contabilidad_abre_el_cierre_y_operaciones_puede_editar(): void
    {
        $this->obraConSaldo();

        // Contabilidad abre el cierre de julio 2026.
        $this->actingAs($this->contable())->post(route('contable.cierre.toggle'), [
            'mes' => 7, 'anio' => 2026, 'accion' => 'abrir',
        ])->assertRedirect();
        $this->assertTrue(CierrePeriodo::estaAbierto(7, 2026));

        // La pantalla muestra "Cierre abierto — puedes editar".
        $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento')
            ->assertSee('Cierre abierto — puedes editar', false);

        // Y ahora guardar SÍ funciona.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['MO4501' => ['14350105' => 500]],
        ])->assertRedirect();
        $this->assertDatabaseCount('distribuciones', 1);
    }

    #[Test]
    public function al_cerrar_de_nuevo_vuelve_a_solo_lectura(): void
    {
        CierrePeriodo::create(['mes' => 7, 'anio' => 2026, 'abierto' => true]);

        $this->actingAs($this->contable())->post(route('contable.cierre.toggle'), [
            'mes' => 7, 'anio' => 2026, 'accion' => 'cerrar',
        ])->assertRedirect();

        $this->assertFalse(CierrePeriodo::estaAbierto(7, 2026));
    }

    #[Test]
    public function el_calendario_permite_filtrar_por_ano_desde_2022(): void
    {
        CierrePeriodo::create(['mes' => 3, 'anio' => 2022, 'abierto' => true]);

        $resp = $this->actingAs($this->contable('ver'))
            ->get(route('contable.cierre.index', ['anio' => 2022]));

        $resp->assertStatus(200);
        // El filtro ofrece 2022 (hay datos desde entonces) y muestra sus 12 meses.
        $resp->assertSee('<option value="2022"', false);
        $resp->assertSee('Enero', false);
        $resp->assertSee('Diciembre', false);
        // Marzo 2022 aparece como abierto.
        $resp->assertSee('🔓 Abierto', false);
    }

    #[Test]
    public function un_usuario_sin_permiso_de_contabilidad_no_puede_abrir_el_cierre(): void
    {
        $this->actingAs($this->operador())->post(route('contable.cierre.toggle'), [
            'mes' => 7, 'anio' => 2026, 'accion' => 'abrir',
        ])->assertForbidden();

        $this->assertFalse(CierrePeriodo::estaAbierto(7, 2026));
    }
}
