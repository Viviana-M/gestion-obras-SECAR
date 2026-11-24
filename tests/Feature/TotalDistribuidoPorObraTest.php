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

        // Encabezados nuevos, con "Costos MO" ANTES del margen y el "Ajuste contable" separado.
        $this->assertSame('Inventario en tránsito aplicado al costo', $sh->getCell('G1')->getValue());
        $this->assertSame('Otros Costos', $sh->getCell('H1')->getValue());
        $this->assertSame('Costos MO', $sh->getCell('I1')->getValue());
        $this->assertSame('Provisión', $sh->getCell('J1')->getValue());
        $this->assertSame('TOTAL distribuido en la OT', $sh->getCell('K1')->getValue());
        $this->assertSame('Ajuste contable (reverso)', $sh->getCell('L1')->getValue());
        $this->assertSame('Costo total del mes', $sh->getCell('M1')->getValue());
        $this->assertSame('Margen del mes ($)', $sh->getCell('N1')->getValue());
        $this->assertSame('Margen del mes (%)', $sh->getCell('O1')->getValue());
        $this->assertSame('Saldo Inventario en Tránsito', $sh->getCell('Q1')->getValue());

        // Fila de la obra (row 2): propio 600, bolsa 400, MO 2000, provisión 1000.
        $this->assertEquals(600,  (float) $sh->getCell('G2')->getValue());
        $this->assertEquals(400,  (float) $sh->getCell('H2')->getValue());
        $this->assertEquals(2000, (float) $sh->getCell('I2')->getValue());
        $this->assertEquals(1000, (float) $sh->getCell('J2')->getValue());
        // TOTAL distribuido = 600+400+2000+1000 = 4000; ajuste 0; Costo total = base(0)+4000+0 = 4000.
        $this->assertEquals(4000, (float) $sh->getCell('K2')->getValue());
        $this->assertEquals(0,    (float) $sh->getCell('L2')->getValue());
        $this->assertEquals(4000, (float) $sh->getCell('M2')->getValue());
        // Margen $ = 5.000.000 − 4000 = 4.996.000 (MO y provisión YA bajan el margen).
        $this->assertEquals(4996000, (float) $sh->getCell('N2')->getValue());

        // El margen % va pintado con el semáforo (99,9% → verde para mantenimiento, umbral 30).
        $fill = $sh->getStyle('O2')->getFill();
        $this->assertSame('16A34A', $fill->getStartColor()->getRGB());

        // Fila TOTAL también suma el total distribuido.
        $this->assertSame('TOTAL', $sh->getCell('A3')->getValue());
        $this->assertEquals(4000, (float) $sh->getCell('K3')->getValue());
    }

    #[Test]
    public function el_resumen_separa_distribuido_de_ajustes_de_contabilidad(): void
    {
        // C-700: distribuido (aplicar 1000) + un reverso en la 6 (ajuste contable 301.502).
        $this->rf('C-700', 'Ingreso', 5000000, 7, 2026, '41350100');
        $this->rf('C-700', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        RegistroFinanciero::create(['codigo_proyecto' => 'C-700', 'nombre_proyecto' => 'Proy C-700',
            'cuenta_contable' => '61300105', 'cuenta_mayor' => 'Costos aplicados', 'estado_er' => -301502,
            'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026, 'origen' => 'reverso_plano']);

        $resp = $this->actingAs($this->operador())->post(route('operativo.distribucion.resumen'), [
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'descargar' => 1,
            'aplicar' => ['C-700' => ['14200105' => 1000]],
        ]);
        $resp->assertOk();

        $ss = IOFactory::load($resp->getFile()->getPathname());
        $plano = json_encode($ss->getActiveSheet()->toArray());

        // Dos categorías diferenciadas en el costo del resumen.
        $this->assertStringContainsString('Distribuido por operaciones', $plano);
        $this->assertStringContainsString('Ajustes de contabilidad (reversos)', $plano);
        // El ajuste (301.502) y el total del costo (1.000 + 301.502 = 302.502) salen en el Excel.
        $this->assertStringContainsString('301.502', $plano);
        $this->assertStringContainsString('302.502', $plano);
    }

    #[Test]
    public function incluye_obras_con_ingreso_o_costo_del_mes_sin_distribucion(): void
    {
        // OC008666 (instalaciones): ingreso del mes + un reverso en la 6, pero distribución 0.
        $this->rf('OC008666', 'Ingreso', 10260554, 7, 2026, '41350100');
        RegistroFinanciero::create([ // reverso en cta 6 (ajuste contable, origen reverso_plano)
            'codigo_proyecto' => 'OC008666', 'nombre_proyecto' => 'Proy OC008666', 'cuenta_contable' => '61300105',
            'cuenta_mayor' => 'Costos aplicados', 'estado_er' => -301502, 'valor_debito' => 0,
            'valor_credito' => 0, 'mes' => 7, 'anio' => 2026, 'origen' => 'reverso_plano',
        ]);

        // Otra obra de instalaciones con algo distribuido, para que la distribución exista.
        $this->rf('O-100', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'instalaciones', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);
        AplicacionCosto::create(['distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026,
            'codigo_proyecto' => 'O-100', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 600, 'es_provision' => false,
            'estado' => 'ok', 'user_id' => $this->operador()->id]);

        $resp = $this->actingAs($this->operador())->get(route('operativo.distribucion.reporte-obras', $dist));
        $resp->assertOk();

        $ss = IOFactory::load($resp->getFile()->getPathname());
        $filas = $ss->getActiveSheet()->toArray(null, true, false); // valores crudos (sin formato de miles)
        $row = collect($filas)->firstWhere(0, 'OC008666');

        $this->assertNotNull($row); // aparece aunque no se distribuyó
        $this->assertEquals(10260554, (float) $row[4]);  // E Ingreso del mes
        $this->assertEquals(0,        (float) $row[5]);  // F Costo base cta 6 = 0 (el reverso NO es base)
        $this->assertEquals(0,        (float) $row[10]); // K TOTAL distribuido en la OT = 0
        $this->assertEquals(301502,   (float) $row[11]); // L Ajuste contable (reverso)
        $this->assertEquals(301502,   (float) $row[12]); // M Costo total del mes = 0 + 0 + 301.502
        $this->assertEquals(9959052,  (float) $row[13]); // N Margen = 10.260.554 − 301.502
    }

    #[Test]
    public function incluye_las_ot_cerradas_aunque_distribuyan_cero(): void
    {
        // OT cerrada del departamento SIN ninguna aplicación/provisión/MO.
        \App\Models\ObraEstado::create(['codigo_proyecto' => 'C-999', 'estado' => 'cerrada',
            'user_id' => $this->operador()->id]);

        // Otra obra con algo distribuido, para que la distribución exista.
        $this->rf('C-700', 'Costos por aplicar', -5000, 6, 2026, '14200105');
        $dist = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
        ]);
        AplicacionCosto::create(['distribucion_id' => $dist->id, 'mes' => 7, 'anio' => 2026,
            'codigo_proyecto' => 'C-700', 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 600, 'es_provision' => false,
            'estado' => 'ok', 'user_id' => $this->operador()->id]);

        $resp = $this->actingAs($this->operador())->get(route('operativo.distribucion.reporte-obras', $dist));
        $resp->assertOk();

        $ss = IOFactory::load($resp->getFile()->getPathname());
        $filas = $ss->getActiveSheet()->toArray();
        $codigos = array_column($filas, 0);

        $this->assertContains('C-999', $codigos); // la cerrada aparece aunque distribuya 0
        $rowCerrada = collect($filas)->firstWhere(0, 'C-999');
        $this->assertSame('Cerrada', $rowCerrada[3]);     // columna Estado
        $this->assertEquals(0, (float) $rowCerrada[10]);  // TOTAL distribuido en la OT = 0
    }
}
