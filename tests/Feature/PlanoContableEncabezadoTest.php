<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\Homologacion;
use App\Models\ObraEstado;
use App\Models\Provision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El encabezado del Plano contable (versiones enviadas) debe incluir la PROVISIÓN en el total, para
 * cuadrar con el cierre y con el costo (61) del plano que realmente se descarga/aplica.
 */
class PlanoContableEncabezadoTest extends TestCase
{
    use RefreshDatabase;

    private function contadora(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    #[Test]
    public function el_total_del_encabezado_incluye_la_provision(): void
    {
        Homologacion::create(['cuenta_14' => '14200530', 'cuenta_61' => '61350105',
            'nombre' => 'Servicio', 'estructura' => 'MAT', 'vigente_desde' => 200001]);

        $uid = $this->contadora()->id;
        ObraEstado::create(['codigo_proyecto' => 'MO4501', 'estado' => 'parcial', 'user_id' => $uid]);

        $d = Distribucion::create(['mes' => 6, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'tipo' => 'obras', 'version' => 1, 'estado' => 'enviado', 'edicion_habilitada' => false,
            'reemplazada' => false, 'enviado_por' => $uid]);

        // Inventario + bolsa (14→61) = 1.000.000.
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 6, 'anio' => 2026,
            'codigo_proyecto' => 'MO4501', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 1000000, 'es_provision' => false,
            'estado' => 'enviado', 'user_id' => $uid]);

        // Provisión registrada ESTE mes y homologable (entra al plano) = 800.000.
        Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 800000,
            'descripcion' => 'X', 'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $fila = collect($this->actingAs($this->contadora())
            ->get(route('contable.plano-contable', ['mes' => 6, 'anio' => 2026]))->viewData('data'))
            ->firstWhere('departamento', 'mantenimiento');

        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(1000000, $fila['aplicado'], 1);   // inventario + bolsa
        $this->assertEqualsWithDelta(800000, $fila['provision'], 1);   // provisión que entra al plano
        $this->assertEqualsWithDelta(1800000, $fila['total'], 1);      // total = cuadra con el cierre
    }

    #[Test]
    public function una_provision_sin_homologar_no_suma_al_encabezado(): void
    {
        // Sin Homologacion para la 14 de la provisión → no entra al plano ni al total del encabezado.
        $uid = $this->contadora()->id;
        ObraEstado::create(['codigo_proyecto' => 'MO4501', 'estado' => 'parcial', 'user_id' => $uid]);
        $d = Distribucion::create(['mes' => 6, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'tipo' => 'obras', 'version' => 1, 'estado' => 'enviado', 'edicion_habilitada' => false,
            'reemplazada' => false, 'enviado_por' => $uid]);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 6, 'anio' => 2026,
            'codigo_proyecto' => 'MO4501', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 1000000, 'es_provision' => false,
            'estado' => 'enviado', 'user_id' => $uid]);
        Provision::create(['codigo_proyecto' => 'MO4501', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14209999', 'cuenta_26' => '26050604', 'monto' => 800000,
            'descripcion' => 'X', 'mes' => 6, 'anio' => 2026, 'estado' => 'activa']);

        $fila = collect($this->actingAs($this->contadora())
            ->get(route('contable.plano-contable', ['mes' => 6, 'anio' => 2026]))->viewData('data'))
            ->firstWhere('departamento', 'mantenimiento');

        $this->assertEqualsWithDelta(0, $fila['provision'], 1);
        $this->assertEqualsWithDelta(1000000, $fila['total'], 1); // solo inventario+bolsa
    }
}
