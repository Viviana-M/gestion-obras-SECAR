<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\Homologacion;
use App\Models\ManoObraEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\RedistribucionMoEspecialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RedistribucionMoEspecialTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    /** Homologa una cuenta 14 como mano de obra (estructura MOI). */
    private function homologarMO(string $c14): void
    {
        Homologacion::create([
            'cuenta_14' => $c14, 'cuenta_61' => '73950505', 'nombre' => 'Mano de obra',
            'estructura' => 'MOI', 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1,
        ]);
    }

    /** Línea de MO de una bolsa (cuenta 14) con un tercero. estado_er negativo = por repartir. */
    private function moBolsa(string $un, string $c14, string $tercero, float $monto, int $mes = 4, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $un, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $c14,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $tercero, 'razon_social' => 'T '.$tercero,
            'estado_er' => -abs($monto), 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** Aporte de seguridad social del empleado (tercero = fondo) en la autoliquidación. */
    private function ssBolsa(string $empleado, string $un, float $aporte, int $mes = 4, int $anio = 2026): void
    {
        AutoliquidacionAporte::create([
            'cedula' => '800100', 'razon_social' => 'NUEVA EPS', 'empleado' => $empleado, 'empleado_nombre' => 'ARLEY',
            'un_codigo' => $un, 'cuenta_contable' => '14200530', 'concepto_pila' => 'EPS',
            'aporte_empresa' => $aporte, 'aporte_empleado' => 0, 'real_descontado' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function el_costo_por_persona_suma_mo_directa_y_seguridad_social(): void
    {
        $this->homologarMO('14200530');
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);

        // MO directa de ARLEY en la bolsa MTO00099 (tercero = su cédula) = 600.000.
        $this->moBolsa('MTO00099', '14200530', '111', 600000);
        // Seguridad social de ARLEY (empleado 111) cruzada de la autoliquidación = 400.000.
        $this->ssBolsa('111', 'MTO00099', 400000);
        // Ruido: MO de otra persona (no Grupo B) no debe contar.
        $this->moBolsa('MTO00099', '14200530', '999', 250000);

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);

        $this->assertEqualsWithDelta(600000, $costo['111']['directo'], 0.5);
        $this->assertEqualsWithDelta(400000, $costo['111']['ss'], 0.5);
        $this->assertEqualsWithDelta(1000000, $costo['111']['total'], 0.5); // total a retirar
    }

    #[Test]
    public function solo_cuentan_las_cuentas_de_mano_de_obra_definidas(): void
    {
        // La MO son solo las cuentas de la lista fija; una cuenta 14 fuera de la lista no cuenta.
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200506', '111', 600000);  // cuenta MO válida
        $this->moBolsa('MTO00099', '14209999', '111', 900000);  // cuenta 14 NO es MO → se ignora

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);

        $this->assertEqualsWithDelta(600000, $costo['111']['directo'], 0.5);
    }

    #[Test]
    public function cruza_por_cedula_normalizando_el_formato(): void
    {
        // El cruce es SOLO por cédula. El documento del financiero/autoliquidación puede venir
        // con puntos/espacios ("12.345.678") y la del maestro sin ellos ("12345678"): debe cruzar.
        $this->homologarMO('14200530');
        ManoObraEspecial::create(['cedula' => '12345678', 'nombre' => 'ARLEY GONZALEZ', 'activo' => true]);

        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '12.345.678', 'razon_social' => 'NUEVA EPS',
            'estado_er' => -700000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        // Un tercero distinto (nombre parecido) NO debe contar: solo cruza la cédula.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '99999', 'razon_social' => 'ARLEY GONZALEZ',
            'estado_er' => -250000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        AutoliquidacionAporte::create([
            'cedula' => '800100', 'razon_social' => 'NUEVA EPS', 'empleado' => '12 345 678', 'empleado_nombre' => 'ARLEY G.',
            'un_codigo' => 'MTO00099', 'cuenta_contable' => '14200530', 'concepto_pila' => 'EPS',
            'aporte_empresa' => 300000, 'aporte_empleado' => 0, 'real_descontado' => 0, 'mes' => 6, 'anio' => 2026,
        ]);

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(6, 2026);

        $this->assertEqualsWithDelta(700000, $costo['12345678']['directo'], 0.5);
        $this->assertEqualsWithDelta(300000, $costo['12345678']['ss'], 0.5);
        $this->assertEqualsWithDelta(1000000, $costo['12345678']['total'], 0.5);
    }

    #[Test]
    public function retira_de_las_bolsas_y_redistribuye_por_porcentaje(): void
    {
        $this->homologarMO('14200530');
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '111', 600000);    // ARLEY directo (tercero = su cédula)
        $this->moBolsa('MTO00099', '14200530', '800100', 400000); // SS de ARLEY en la bolsa (tercero = fondo)
        $this->ssBolsa('111', 'MTO00099', 400000);                // atribución de esa SS a ARLEY (autoliquidación) → total 1.000.000
        $this->moBolsa('MTO00099', '14200530', '999', 500000);    // MO de otro (se queda)

        // % del período: 60% MTO00099 / 40% INS00099.
        RedistribucionMoEspecial::insert([
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'MTO00099', 'porcentaje' => 60],
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'INS00099', 'porcentaje' => 40],
        ]);

        $r = app(RedistribucionMoEspecialService::class)->resumenBolsas(4, 2026);
        $porUn = collect($r['filas'])->keyBy('un');

        // MTO00099: crudo = 600k(ARLEY)+400k(SS)+500k(otro) = 1.500.000; retirado 1.000.000; neto 500.000.
        $this->assertEqualsWithDelta(1500000, $porUn['MTO00099']['crudo'], 0.5);
        $this->assertEqualsWithDelta(1000000, $porUn['MTO00099']['retirado'], 0.5);
        $this->assertEqualsWithDelta(500000, $porUn['MTO00099']['neto'], 0.5);
        // Redistribución: 60% a MTO00099 = 600.000 ; 40% a INS00099 = 400.000.
        $this->assertEqualsWithDelta(600000, $porUn['MTO00099']['redistribuido'], 0.5);
        $this->assertEqualsWithDelta(400000, $porUn['INS00099']['redistribuido'], 0.5);
        // Final MTO00099 = 500.000 + 600.000 = 1.100.000 ; INS00099 = 0 + 400.000 = 400.000.
        $this->assertEqualsWithDelta(1100000, $porUn['MTO00099']['final'], 0.5);
        $this->assertEqualsWithDelta(400000, $porUn['INS00099']['final'], 0.5);

        // Global: el total final = total crudo (el dinero queda en bolsas, solo se movió).
        $this->assertEqualsWithDelta($r['total_crudo'], array_sum(array_column($r['filas'], 'final')), 0.5);
        $this->assertEqualsWithDelta(1000000, $r['total_retirado'], 0.5);
        $this->assertEqualsWithDelta(1000000, $r['total_redistribuido'], 0.5);
    }

    #[Test]
    public function los_porcentajes_son_por_periodo(): void
    {
        $this->homologarMO('14200530');
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        // Costo en abril y en mayo (mismo monto para comparar).
        $this->moBolsa('MTO00099', '14200530', '111', 1000000, 4, 2026);
        $this->moBolsa('MTO00099', '14200530', '111', 1000000, 5, 2026);
        // Abril 60/40 ; Mayo 30/70.
        RedistribucionMoEspecial::insert([
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'MTO00099', 'porcentaje' => 60],
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'INS00099', 'porcentaje' => 40],
            ['cedula' => '111', 'mes' => 5, 'anio' => 2026, 'un_codigo' => 'MTO00099', 'porcentaje' => 30],
            ['cedula' => '111', 'mes' => 5, 'anio' => 2026, 'un_codigo' => 'INS00099', 'porcentaje' => 70],
        ]);

        $svc = app(RedistribucionMoEspecialService::class);
        $abr = collect($svc->resumenBolsas(4, 2026)['filas'])->keyBy('un');
        $may = collect($svc->resumenBolsas(5, 2026)['filas'])->keyBy('un');

        $this->assertEqualsWithDelta(400000, $abr['INS00099']['redistribuido'], 0.5); // 40%
        $this->assertEqualsWithDelta(700000, $may['INS00099']['redistribuido'], 0.5); // 70% (respeta el nuevo período)
    }

    #[Test]
    public function guardar_porcentajes_exige_que_sumen_100(): void
    {
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);

        $this->actingAs($this->contable())->post(route('contable.redistribucion-mo.porcentajes'), [
            'mes' => 4, 'anio' => 2026,
            'pct' => ['111' => [['un' => 'MTO00099', 'pct' => 60], ['un' => 'INS00099', 'pct' => 30]]], // suma 90
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, RedistribucionMoEspecial::count());

        // Con 100% sí guarda.
        $this->actingAs($this->contable())->post(route('contable.redistribucion-mo.porcentajes'), [
            'mes' => 4, 'anio' => 2026,
            'pct' => ['111' => [['un' => 'MTO00099', 'pct' => 60], ['un' => 'INS00099', 'pct' => 40]]],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, RedistribucionMoEspecial::where('cedula', '111')->count());
    }

    #[Test]
    public function el_plano_redistribuye_14_a_14_y_cuadra(): void
    {
        $this->homologarMO('14200530');
        ManoObraEspecial::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '111', 1000000);
        RedistribucionMoEspecial::insert([
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'MTO00099', 'porcentaje' => 60],
            ['cedula' => '111', 'mes' => 4, 'anio' => 2026, 'un_codigo' => 'INS00099', 'porcentaje' => 40],
        ]);

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.redistribucion-mo.plano', ['mes' => 4, 'anio' => 2026, 'documento' => 55]));
        $resp->assertOk();

        $ss  = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->getFile()->getPathname());
        $mov = $ss->getSheetByName('Movimientocontable');
        // Suma débitos == créditos (asiento cuadrado).
        $deb = 0; $cred = 0;
        foreach (range(2, $mov->getHighestRow()) as $row) {
            $deb  += (float) $mov->getCell('H'.$row)->getValue();
            $cred += (float) $mov->getCell('I'.$row)->getValue();
        }
        $this->assertEqualsWithDelta($deb, $cred, 0.5);
        $this->assertEqualsWithDelta(1000000, $deb, 0.5); // se movió 1.000.000
    }

    #[Test]
    public function solo_contabilidad_ve_el_modulo(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver']]);
        $this->actingAs($sin)->get(route('contable.redistribucion-mo.index'))->assertForbidden();

        $this->actingAs($this->contable('ver'))->get(route('contable.redistribucion-mo.index'))->assertStatus(200);
    }
}
