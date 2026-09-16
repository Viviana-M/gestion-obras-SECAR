<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reporte Excel de obras con saldo abierto/parcial en la cuenta 14, con los datos de la
 * tarjeta y los márgenes pintados con el semáforo, para validar con Contabilidad/Operaciones.
 */
class ReporteSaldos14Test extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    #[Test]
    public function descarga_las_obras_con_saldo_en_la_14_con_datos_de_la_tarjeta(): void
    {
        Excel::fake();

        FichaProyecto::create(['codigo_proyecto' => 'MO4501', 'nombre_obra' => 'Torre Norte',
            'cliente' => 'Constructora XYZ', 'valor_contratado' => 5000000, 'costo_estimado' => 3000000, 'margen_ofertado' => 30]);
        // Ingreso del mes + saldo pendiente en cuenta 14 (obra parcial, con ingreso).
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Torre Norte',
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 5000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4501', 'nombre_proyecto' => 'Torre Norte',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -1000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);

        $resp = $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.reporte-saldos', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']));

        $resp->assertOk();
        Excel::assertDownloaded('Saldos_cuenta_14_Julio_2026_Mantenimiento.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'MO4501')
                && str_contains($plano, 'Torre Norte')
                && str_contains($plano, 'Constructora XYZ')
                && str_contains($plano, 'Saldo cuenta 14 (pendiente)')
                && str_contains($plano, 'Rentabilidad % MC')
                && str_contains($plano, 'MC % proyecci');
        });
    }

    #[Test]
    public function excluye_las_obras_cerradas_y_sin_saldo(): void
    {
        Excel::fake();

        // Obra SIN saldo neto en cuenta 14 (se cancela): no debe aparecer.
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4600', 'nombre_proyecto' => 'Sin saldo',
            'cuenta_contable' => '41350100', 'cuenta_mayor' => 'Ingreso', 'estado_er' => 1000000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        // Obra CON saldo en cuenta 14.
        RegistroFinanciero::create(['codigo_proyecto' => 'MO4700', 'nombre_proyecto' => 'Con saldo',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -500000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);

        $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.reporte-saldos', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertOk();

        Excel::assertDownloaded('Saldos_cuenta_14_Julio_2026_Mantenimiento.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'MO4700') && ! str_contains($plano, 'MO4600');
        });
    }

    #[Test]
    public function sin_departamento_incluye_ambas_areas(): void
    {
        Excel::fake();

        // Obra de Mantenimiento (prefijo C) y obra de Instalaciones (prefijo GI), ambas con saldo 14.
        RegistroFinanciero::create(['codigo_proyecto' => 'C-100', 'nombre_proyecto' => 'Mant',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -50000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'GI-200', 'nombre_proyecto' => 'Inst',
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => -50000,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026]);

        // Sin 'departamento' en la petición: se descargan las dos áreas y el archivo no lleva sufijo.
        $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.reporte-saldos', ['mes' => 7, 'anio' => 2026]))
            ->assertOk();

        Excel::assertDownloaded('Saldos_cuenta_14_Julio_2026.xlsx', function ($export) {
            $plano = json_encode($export->array());
            return str_contains($plano, 'C-100') && str_contains($plano, 'GI-200');
        });
    }

    #[Test]
    public function un_usuario_sin_operacion_no_puede_descargar(): void
    {
        $sinOp = User::factory()->create(['rol' => 'contadora', 'activo' => true,
            'permisos_modulos' => ['contabilidad' => 'ver']]);

        $this->actingAs($sinOp)
            ->get(route('operativo.distribucion.reporte-saldos', ['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento']))
            ->assertForbidden();
    }
}
