<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporte Excel POR OBRA de una distribución: lo aplicado y cómo quedó cada obra.
 */
class ReporteObrasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    #[Test]
    public function genera_el_excel_por_obra_con_lo_aplicado_y_como_quedo(): void
    {
        Excel::fake();

        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false,
        ]);

        // Obra con ingreso del mes y saldo en cuenta 14.
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Torre Norte',
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 5000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Torre Norte',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);

        // Lo aplicado en esta distribución.
        AplicacionCosto::create([
            'distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'MO4501',
            'cuenta_14' => '14350105', 'cuenta_61' => '73950505', 'categoria' => 'EQU-MAT-SUM',
            'nombre' => 'Materiales', 'monto_aplicar' => 600000, 'es_provision' => false, 'estado' => 'borrador',
        ]);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.reporte-obras', $dist->id));

        $resp->assertOk();
        Excel::assertDownloaded('Distribucion_por_obra_Julio_2026_Mantenimiento.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'MO4501')
                && str_contains($plano, 'Aplicado total')
                && str_contains($plano, 'Margen del mes ($)')
                && str_contains($plano, 'TOTAL');
        });
    }

    #[Test]
    public function un_usuario_sin_operacion_no_puede_descargar_el_reporte(): void
    {
        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false,
        ]);
        $sinOp = User::factory()->create(['rol' => 'contadora', 'activo' => true,
            'permisos_modulos' => ['contabilidad' => 'ver']]);

        $this->actingAs($sinOp)
            ->get(route('operativo.distribucion.reporte-obras', $dist->id))
            ->assertForbidden();
    }
}
