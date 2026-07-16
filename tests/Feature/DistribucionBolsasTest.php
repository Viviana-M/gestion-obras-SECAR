<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\BolsaAsignacion;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bolsas de área integradas en la pantalla de Distribución de costos:
 * panel de origen (bolsa), asignación bolsa→obra, tope por disponible y persistencia.
 */
class DistribucionBolsasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cuentaMayor, float $er, int $mes, int $anio, string $cc = '000000'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cuentaMayor,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /**
     * Obra C-700 (mantenimiento) con ingreso y un pendiente propio de cuenta 14, más
     * la bolsa MTO00099 con +1000 por repartir (saldo positivo = costo por distribuir).
     */
    private function seedBase(): void
    {
        $this->rf('C-700', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -100, 6, 2026, '14350105'); // pendiente propio
        $this->rf('MTO00099', 'Costos por aplicar', 1000, 6, 2026, '14200530'); // bolsa: por repartir
    }

    #[Test]
    public function el_panel_de_bolsas_muestra_el_disponible_y_el_control_de_asignacion(): void
    {
        $this->seedBase();

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertStatus(200);
        $resp->assertSee('BOLSAS DE ÁREA', false);
        $resp->assertSee('id="bolsa-box-MTO00099"', false);
        $resp->assertSee('id="bolsa-disp-MTO00099"', false);
        $resp->assertSee('Asignar desde bolsa de área', false);
        $resp->assertSee('id="asignbolsa-cta-C-700"', false);
        // La bolsa no aparece como una obra en la lista.
        $resp->assertDontSee('id="card-MTO00099"', false);
    }

    #[Test]
    public function asignar_desde_bolsa_persiste_y_refleja_el_costo_en_el_proyecto(): void
    {
        $this->seedBase();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ])->assertRedirect();

        // Asignación persistida.
        $this->assertDatabaseHas('bolsa_asignaciones', [
            'bolsa_codigo' => 'MTO00099', 'codigo_proyecto' => 'C-700', 'monto' => 400.00,
        ]);
        // Reflejada como costo del proyecto (línea con origen_bolsa) para el plano/resumen.
        $linea = AplicacionCosto::where('codigo_proyecto', 'C-700')->where('origen_bolsa', 'MTO00099')->first();
        $this->assertNotNull($linea);
        $this->assertSame('14200530', $linea->cuenta_14);
        $this->assertEqualsWithDelta(400, (float) $linea->monto_aplicar, 0.5);
    }

    #[Test]
    public function no_permite_asignar_mas_que_el_saldo_de_la_bolsa(): void
    {
        $this->seedBase(); // bolsa disponible = 1000

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 3000]]],
        ])->assertRedirect();

        // Se recorta al disponible (1000), nunca más.
        $this->assertEqualsWithDelta(1000, (float) BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->sum('monto'), 0.5);
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function dos_obras_consumen_la_misma_bolsa_sin_exceder_el_total(): void
    {
        $this->seedBase(); // bolsa 1000
        $this->rf('C-800', 'Ingreso', 5000, 7, 2026, '41350100');
        $this->rf('C-800', 'Costos por aplicar', -50, 6, 2026, '14350105');

        // A pide 600, B pide 700 => 1300 > 1000. El total no puede pasar de 1000.
        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => [
                'C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 600]],
                'C-800' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 700]],
            ],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(1000, (float) BolsaAsignacion::where('bolsa_codigo', 'MTO00099')->sum('monto'), 0.5);
        // El plano no acredita la cuenta 14 de la bolsa por más de su saldo.
        $this->assertEqualsWithDelta(1000, (float) AplicacionCosto::where('origen_bolsa', 'MTO00099')->sum('monto_aplicar'), 0.5);
    }

    #[Test]
    public function al_recargar_el_borrador_baja_el_disponible_de_la_bolsa(): void
    {
        $this->seedBase();

        $this->actingAs($this->operador())->post(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'MTO00099', 'monto' => 400]]],
        ])->assertRedirect();

        $dist = Distribucion::first();
        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?dist='.$dist->id);

        $resp->assertStatus(200);
        // Disponible = 1000 - 400 = 600.
        $resp->assertSee('$600', false);
        // El chip guardado se vuelve a pintar.
        $resp->assertSee('Desde <b>MTO00099</b>', false);
    }

    #[Test]
    public function el_servicio_agrupa_los_componentes_de_la_bolsa(): void
    {
        $svc = new DistribucionService();
        $comp = $svc->componentesDe([
            ['estructura' => 'MOI', 'pendiente' => 100],
            ['estructura' => 'MOFIJAOPER', 'pendiente' => 50],
            ['estructura' => 'MOE', 'pendiente' => 30],
            ['estructura' => 'EQU-MAT-SUM', 'pendiente' => 20],
        ]);

        $this->assertEqualsWithDelta(150, $comp['mo_directa']['monto'], 0.001);
        $this->assertEqualsWithDelta(30, $comp['terceros']['monto'], 0.001);
        $this->assertEqualsWithDelta(20, $comp['otros']['monto'], 0.001);
    }
}
