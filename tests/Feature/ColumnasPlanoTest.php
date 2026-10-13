<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Todos los módulos que cargan archivos planos muestran, de forma unificada, las columnas
 * que debe traer el archivo (componente <x-columnas-plano>).
 */
class ColumnasPlanoTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    #[Test]
    public function cierre_de_mes_indica_las_columnas_del_biable(): void
    {
        $resp = $this->actingAs($this->contable())->get(route('contable.carga'));
        $resp->assertStatus(200);
        $resp->assertSee('El archivo BIABLE debe traer estas columnas', false);
        $resp->assertSee('reconoce por su nombre', false);
        $resp->assertSee('Unidad de negocio', false);
        $resp->assertSee('Tercero Docto', false);
        $resp->assertSee('Razón social Docto', false);
        // Las columnas nuevas (fecha y tipo de documento) se muestran marcadas como NUEVO.
        $resp->assertSee('Fecha del documento', false);
        $resp->assertSee('Tipo de documento', false);
        $resp->assertSee('NUEVO', false);
        // Nota de que la carga debe incluir las cuentas de pasivo que ahora entran como datos.
        $resp->assertSee('28 (facturación anticipada)', false);
    }

    #[Test]
    public function movimiento_comercial_indica_las_columnas(): void
    {
        $resp = $this->actingAs($this->contable())->get(route('contable.movimiento-comercial.index'));
        $resp->assertStatus(200);
        $resp->assertSee('hoja Comercial_Mvto debe traer estas columnas', false);
        $resp->assertSee('Costo_prom_net', false);
        $resp->assertSee('Periodo', false);
    }

    #[Test]
    public function autoliquidacion_indica_las_columnas(): void
    {
        $resp = $this->actingAs($this->contable())->get(route('contable.autoliquidacion.index'));
        $resp->assertStatus(200);
        $resp->assertSee('Columnas que reconoce el módulo', false);
        $resp->assertSee('Nombre del empl', false);
    }
}
