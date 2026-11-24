<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\ManoObraAsignacion;
use App\Models\Provision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Mis distribuciones": el total grande de la fila = aplicado (propio+bolsa) + MO + provisión,
 * con la provisión y la MO tomadas de su fuente real (Provision::activasEn / ManoObraAsignacion),
 * NO del flag obsoleto es_provision. Así cuadra con el Excel por obra.
 */
class ConsultasTotalDistribuidoTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    #[Test]
    public function el_total_de_la_fila_incluye_provision_y_mano_de_obra(): void
    {
        $op = $this->operador();
        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);

        // Aplicado = propio (600) + bolsa (400) = 1000 (AplicacionCosto, es_provision = 0).
        $base = ['distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'C-700',
            'cuenta_14' => '14200105', 'cuenta_61' => '61300105', 'categoria' => 'MAT', 'nombre' => 'c',
            'es_provision' => false, 'estado' => 'ok', 'user_id' => $op->id];
        AplicacionCosto::create($base + ['monto_aplicar' => 600, 'origen_bolsa' => null]);
        AplicacionCosto::create($base + ['monto_aplicar' => 400, 'origen_bolsa' => 'mantenimiento']);

        // Provisión (tabla provisiones) 1000 y MO (ManoObraAsignacion) 2000.
        Provision::create(['codigo_proyecto' => 'C-700', 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => 1000,
            'descripcion' => 'X', 'mes' => 7, 'anio' => 2026, 'estado' => 'activa']);
        ManoObraAsignacion::create(['bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111',
            'tercero' => '111', 'tercero_nombre' => 'Juan', 'obra_destino' => 'C-700', 'monto' => 2000,
            'mes' => 7, 'anio' => 2026, 'origen' => 'manual']);

        $fila = collect($this->actingAs($op)->get(route('operativo.distribucion.consultas'))->viewData('filas'))
            ->firstWhere('departamento', 'mantenimiento');

        $this->assertNotNull($fila);
        $o = $fila['obras'];
        $this->assertEqualsWithDelta(1000, $o['aplicado'], 0.5);   // propio + bolsa
        $this->assertEqualsWithDelta(1000, $o['provision'], 0.5);  // desde Provision::activasEn
        $this->assertEqualsWithDelta(2000, $o['mo'], 0.5);         // desde ManoObraAsignacion
        // Total grande = 1000 + 2000 + 1000 = 4000 (cuadra con el Excel por obra).
        $this->assertEqualsWithDelta(4000, $o['total'], 0.5);
    }
}
