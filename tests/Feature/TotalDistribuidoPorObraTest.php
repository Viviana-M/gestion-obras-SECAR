<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\ManoObraAsignacion;
use App\Models\Provision;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * El "total distribuido" por OT = propio (14→6) + bolsa + provisión (14→26) + mano de obra, con la
 * MISMA definición en los tres lugares (tarjeta, Excel por obra y resumen). Provisión y MO salen
 * de la BD (lo guardado).
 */
class TotalDistribuidoPorObraTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function rf(string $codigo, string $cm, float $er, int $mes, int $anio, string $cc): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $codigo, 'nombre_proyecto' => 'Proy '.$codigo,
            'cuenta_contable' => $cc, 'cuenta_mayor' => $cm,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    private function provision(string $cod, float $monto): void
    {
        Provision::create([
            'codigo_proyecto' => $cod, 'departamento' => 'mantenimiento',
            'cuenta_14' => '14200530', 'cuenta_26' => '26050604', 'monto' => $monto,
            'descripcion' => 'Servicio X', 'mes' => 7, 'anio' => 2026, 'estado' => 'activa',
        ]);
    }

    private function manoObra(string $cod, float $monto): void
    {
        ManoObraAsignacion::create([
            'bolsa_un' => 'MTO00099', 'cuenta_14' => '14200530', 'persona' => '111', 'tercero' => '111',
            'tercero_nombre' => 'Juan Perez', 'obra_destino' => $cod, 'monto' => $monto,
            'mes' => 7, 'anio' => 2026, 'origen' => 'manual',
        ]);
    }

    #[Test]
    public function el_resumen_suma_provision_y_mano_de_obra_al_costo(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -1000, 6, 2026, '14200105');
        $this->rf('MTO00099', 'Costos por aplicar', -1000, 6, 2026, '14200530'); // bolsa
        $this->provision('C-700', 1000);
        $this->manoObra('C-700', 2000);

        $resp = $this->actingAs($this->operador())->post(route('operativo.distribucion.resumen'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-700' => ['14200105' => 100]],
            'asignacion_bolsa' => ['C-700' => ['n1' => ['bolsa' => 'mantenimiento', 'monto' => 400]]],
        ]);

        $resp->assertStatus(200);
        // Costos = aplicado(100) + bolsa(400) + provisión(1000) + MO(2000) = 3500.
        $resp->assertSee('$3.500', false);
    }

    #[Test]
    public function el_excel_por_obra_trae_el_total_distribuido_con_provision_y_mo(): void
    {
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        $this->provision('C-700', 1000);
        $this->manoObra('C-700', 2000);

        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);
        $base = ['distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'C-700',
            'cuenta_14' => '14200105', 'cuenta_61' => '61300105', 'categoria' => 'MAT', 'nombre' => 'c',
            'es_provision' => false, 'estado' => 'ok', 'user_id' => $this->operador()->id];
        AplicacionCosto::create($base + ['monto_aplicar' => 600, 'origen_bolsa' => null]);       // propio
        AplicacionCosto::create($base + ['monto_aplicar' => 400, 'origen_bolsa' => 'mantenimiento']); // bolsa

        $resp = $this->actingAs($this->operador())->get(route('operativo.distribucion.reporte-obras', $dist));
        $resp->assertOk();

        $ss  = IOFactory::load($resp->getFile()->getPathname());
        $sh  = $ss->getActiveSheet();

        // Encabezados nuevos (columnas O, P, Q).
        $this->assertSame('Provisión del mes (14→26)', $sh->getCell('O1')->getValue());
        $this->assertSame('Mano de obra distribuida', $sh->getCell('P1')->getValue());
        $this->assertSame('TOTAL distribuido en la OT', $sh->getCell('Q1')->getValue());

        // Fila de la obra (row 2): provisión 1000, MO 2000, TOTAL = 600+400+2000+1000 = 4000.
        $this->assertEquals(1000, (float) $sh->getCell('O2')->getValue());
        $this->assertEquals(2000, (float) $sh->getCell('P2')->getValue());
        $this->assertEquals(4000, (float) $sh->getCell('Q2')->getValue());

        // Fila TOTAL (row 3) también suma el total distribuido.
        $this->assertSame('TOTAL', $sh->getCell('A3')->getValue());
        $this->assertEquals(4000, (float) $sh->getCell('Q3')->getValue());
    }
}
