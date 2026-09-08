<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\Homologacion;
use App\Models\ManoObraDirecta;
use App\Models\MontoDistribuirMoEspecial;
use App\Models\RedistribucionMoEspecial;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Services\DistribucionService;
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
    private function ssBolsa(string $empleado, string $un, float $aporte, int $mes = 4, int $anio = 2026, string $fondo = '800100'): void
    {
        AutoliquidacionAporte::create([
            'id_cuenta' => '14200530', 'cedula' => $fondo, 'razon_social' => 'NUEVA EPS',
            'empleado' => $empleado, 'empleado_nombre' => 'ARLEY',
            'un_codigo' => $un, 'cuenta_contable' => '14200530', 'concepto_pila' => 'EPS',
            'aporte_empresa' => $aporte, 'aporte_empleado' => 0, 'real_descontado' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    #[Test]
    public function el_costo_por_persona_suma_mo_directa_y_seguridad_social(): void
    {
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);

        // MO directa (salario) de ARLEY en la bolsa MTO00099 (tercero = su cédula) = 600.000.
        $this->moBolsa('MTO00099', '14200530', '111', 600000);
        // Seguridad social: está en la cuenta 14 a nombre del fondo (tercero = 800100) = 400.000...
        $this->moBolsa('MTO00099', '14200530', '800100', 400000);
        // ...y la autoliquidación atribuye ese fondo a ARLEY (empleado 111).
        $this->ssBolsa('111', 'MTO00099', 400000);
        // Ruido: MO de otra persona (no Grupo B) no debe contar.
        $this->moBolsa('MTO00099', '14200530', '999', 250000);

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);

        $this->assertEqualsWithDelta(600000, $costo['111']['directo'], 0.5);
        $this->assertEqualsWithDelta(400000, $costo['111']['ss'], 0.5);
        $this->assertEqualsWithDelta(1000000, $costo['111']['total'], 0.5); // total a retirar
    }

    #[Test]
    public function solo_toma_la_mo_de_las_bolsas_configuradas(): void
    {
        // El salario se toma SOLO de las UN marcadas como bolsa (MTO00099/INS00099); la MO de la
        // persona en UN de obra (OB*, C*…) NO se reclasifica en este módulo.
        $this->homologarMO('14200506');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);

        $this->moBolsa('MTO00099', '14200506', '111', 500000); // en bolsa → cuenta
        $this->moBolsa('OB008657', '14200506', '111', 300000); // fuera de bolsa → se ignora

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);
        $this->assertEqualsWithDelta(500000, $costo['111']['directo'], 0.5); // solo la de la bolsa
    }

    #[Test]
    public function la_ss_se_toma_de_la_autoliquidacion_en_la_un_del_salario(): void
    {
        // El valor de la SS se toma DIRECTO de la autoliquidación (aporte empresa por persona/fondo)
        // y se ubica en la UN donde la persona tiene su salario.
        $this->homologarMO('14200506');
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);

        $this->moBolsa('MTO00099', '14200506', '111', 600000);  // salario de ARLEY en la bolsa
        $this->ssBolsa('111', 'ADM00099', 400000);              // SS en la autoliq (la UN de la autoliq no importa)

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);

        $this->assertEqualsWithDelta(600000, $costo['111']['directo'], 0.5);
        $this->assertEqualsWithDelta(400000, $costo['111']['ss'], 0.5); // valor directo de la autoliquidación

        // La SS queda en la UN del salario (MTO00099) y con el fondo como tercero.
        $ss = collect($costo['111']['buckets'])->firstWhere('tipo', 'ss');
        $this->assertSame('MTO00099', $ss['un']);
        $this->assertSame('800100', $ss['tercero']);
    }

    #[Test]
    public function reporta_descuadre_entre_la_cuenta_14_del_fondo_y_la_autoliquidacion(): void
    {
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '800100', 900000); // cuenta 14 del fondo = 900.000
        $this->ssBolsa('111', 'MTO00099', 1000000);               // autoliquidación = 1.000.000

        $desc = app(RedistribucionMoEspecialService::class)->descuadresFondos(4, 2026);

        $this->assertCount(1, $desc);
        $this->assertEqualsWithDelta(900000, $desc[0]['cuenta14'], 0.5);
        $this->assertEqualsWithDelta(1000000, $desc[0]['autoliq'], 0.5);
        $this->assertEqualsWithDelta(-100000, $desc[0]['diferencia'], 0.5);
    }

    #[Test]
    public function cruza_por_nombre_cuando_el_financiero_no_trae_la_cedula(): void
    {
        // Como en producción: la bolsa identifica al tercero por NOMBRE en la razón social
        // (sin cédula), y el orden/acentos de las palabras difieren del maestro. Debe cruzar.
        $this->homologarMO('14200506');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'Arley Valencia Villabona', 'activo' => true]);

        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200506',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '', 'razon_social' => 'VALENCIA VILLABONA ARLEY',
            'estado_er' => -36821878, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        // Otra persona en la misma bolsa NO debe sumarle a Arley.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200527',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '', 'razon_social' => 'TRUJILLO MORALES JOHAN E',
            'estado_er' => -2655290, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(6, 2026);
        $this->assertEqualsWithDelta(36821878, $costo['111']['directo'], 0.5);

        // Y el tercero no registrado (JOHAN) aparece en el diagnóstico de "sin cruzar".
        $sin = app(RedistribucionMoEspecialService::class)->tercerosSinCruzar(6, 2026);
        $this->assertContains('TRUJILLO MORALES JOHAN E', array_column($sin, 'nombre'));
        $this->assertNotContains('VALENCIA VILLABONA ARLEY', array_column($sin, 'nombre'));
    }

    #[Test]
    public function solo_cuentan_las_cuentas_de_mano_de_obra_definidas(): void
    {
        // La MO son solo las cuentas de la lista fija; una cuenta 14 fuera de la lista no cuenta.
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
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
        ManoObraDirecta::create(['cedula' => '12345678', 'nombre' => 'ARLEY GONZALEZ', 'activo' => true]);

        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '12.345.678', 'razon_social' => 'NUEVA EPS',
            'estado_er' => -700000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        // Un tercero distinto (otra cédula y otro nombre) NO debe contar.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '99999', 'razon_social' => 'OTRO PROVEEDOR',
            'estado_er' => -250000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        // Seguridad social en la cuenta 14 a nombre del fondo (NIT 800100) = 300.000.
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '800100', 'razon_social' => 'NUEVA EPS',
            'estado_er' => -300000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 6, 'anio' => 2026,
        ]);
        // La autoliquidación (fondo + empleado + aporte) atribuye esos 300.000 a ARLEY.
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
    public function el_resumen_muestra_la_mo_reclasificada_por_un(): void
    {
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '111', 600000);    // ARLEY directo (tercero = su cédula)
        $this->moBolsa('MTO00099', '14200530', '999', 500000);    // MO de otro (se queda en la 14)

        $r = app(RedistribucionMoEspecialService::class)->resumenBolsas(4, 2026);
        $porUn = collect($r['filas'])->keyBy('un');

        // MTO00099: crudo = 600k(ARLEY)+500k(otro) = 1.100.000; reclasificado 600k; queda 500k.
        $this->assertEqualsWithDelta(1100000, $porUn['MTO00099']['crudo'], 0.5);
        $this->assertEqualsWithDelta(600000, $porUn['MTO00099']['reclasificado'], 0.5);
        $this->assertEqualsWithDelta(500000, $porUn['MTO00099']['queda'], 0.5);
        $this->assertEqualsWithDelta(600000, $r['total_reclasificado'], 0.5);
    }

    #[Test]
    public function toma_solo_la_mo_del_mes_no_el_acumulado(): void
    {
        // Debe ser la cuenta 14 DEL MES (movimiento del período), no el acumulado: así una
        // reclasificación de un mes anterior (posiblemente con terceros distintos) no genera
        // saldos irreales en el mes actual.
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '111', 600000, 4, 2026); // MO de abril
        $this->moBolsa('MTO00099', '14200530', '111', 500000, 5, 2026); // MO de mayo

        $svc = app(RedistribucionMoEspecialService::class);
        // Cada mes toma solo su propio movimiento (no se acumula).
        $this->assertEqualsWithDelta(600000, $svc->costoPorPersona(4, 2026)['111']['total'], 0.5);
        $this->assertEqualsWithDelta(500000, $svc->costoPorPersona(5, 2026)['111']['total'], 0.5);
    }

    #[Test]
    public function el_plano_reclasifica_14_a_61_por_la_un_del_cierre(): void
    {
        // ARLEY con MO ya distribuida por el cierre en DOS UN. El plano reclasifica su MO completa
        // 14→61 en la MISMA UN de cada línea: CR 14 (conserva tercero) / DB 61 (a nombre de ARLEY).
        $this->homologarMO('14200530'); // 14200530 → cuenta_61 = 73950505
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200530', '111', 700000); // salario ARLEY en MTO00099
        $this->moBolsa('INS00099', '14200530', '111', 300000); // salario ARLEY en INS00099

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.redistribucion-mo.plano', ['mes' => 4, 'anio' => 2026, 'documento' => 55]));
        $resp->assertOk();

        $ss  = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->getFile()->getPathname());
        $mov = $ss->getSheetByName('Movimientocontable');
        // Columnas: C=cuenta, E=UN, H=débito, I=crédito.
        $deb = 0; $cred = 0; $credEn14 = 0; $debEn61 = 0; $porUn = [];
        foreach (range(2, $mov->getHighestRow()) as $row) {
            $cta = (string) $mov->getCell('C'.$row)->getValue();
            $un  = (string) $mov->getCell('E'.$row)->getValue();
            $h   = (float) $mov->getCell('H'.$row)->getValue();
            $i   = (float) $mov->getCell('I'.$row)->getValue();
            $deb += $h; $cred += $i;
            if ($cta === '14200530') $credEn14 += $i; // sale de la 14 (crédito)
            if ($cta === '73950505') $debEn61 += $h;  // entra a su 61 (débito)
            $porUn[$un] = ($porUn[$un] ?? 0) + $h;     // débito por UN
        }
        $this->assertEqualsWithDelta($deb, $cred, 0.5);       // asiento cuadrado
        $this->assertEqualsWithDelta(1000000, $credEn14, 0.5); // toda la MO sale de la cuenta 14
        $this->assertEqualsWithDelta(1000000, $debEn61, 0.5);  // y entra a su 61
        // Se respeta la UN de cada línea (no se mezcla): 700k en MTO00099 y 300k en INS00099.
        $this->assertEqualsWithDelta(700000, $porUn['MTO00099'] ?? 0, 0.5);
        $this->assertEqualsWithDelta(300000, $porUn['INS00099'] ?? 0, 0.5);
    }

    #[Test]
    public function el_plano_conserva_el_fondo_de_ss_en_ambas_patas(): void
    {
        // La SS (tomada de la autoliquidación) va con el fondo/EPS como tercero en AMBAS patas:
        // crédito a la 14 y débito a la 61, en la UN del salario de la persona.
        $this->homologarMO('14200506');
        $this->homologarMO('14200530');
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200506', '111', 600000); // salario de ARLEY (define la UN)
        $this->ssBolsa('111', 'ADM00099', 400000);             // SS del fondo 800100 (cuenta 14 = 14200530)

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.redistribucion-mo.plano', ['mes' => 4, 'anio' => 2026, 'documento' => 7]));
        $resp->assertOk();

        $ss  = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->getFile()->getPathname());
        $mov = $ss->getSheetByName('Movimientocontable');
        // Columnas: C=cuenta, D=tercero, H=débito, I=crédito.
        $credFondo14 = 0; $debFondo61 = 0;
        foreach (range(2, $mov->getHighestRow()) as $row) {
            $cta  = (string) $mov->getCell('C'.$row)->getValue();
            $terc = (string) $mov->getCell('D'.$row)->getValue();
            $h    = (float) $mov->getCell('H'.$row)->getValue();
            $i    = (float) $mov->getCell('I'.$row)->getValue();
            if ($terc === '800100' && $cta === '14200530') $credFondo14 += $i; // crédito a la 14 del fondo
            if ($terc === '800100' && $cta === '73950505') $debFondo61 += $h;  // débito a la 61 del fondo
        }
        $this->assertEqualsWithDelta(400000, $credFondo14, 0.5);
        $this->assertEqualsWithDelta(400000, $debFondo61, 0.5);
    }

    #[Test]
    public function el_plano_pone_centro_de_costo_por_un_en_la_cuenta_6(): void
    {
        // Homologación a una cuenta 61 (inicia en 6).
        Homologacion::create(['cuenta_14' => '14200506', 'cuenta_61' => '61050101', 'nombre' => 'MO',
            'estructura' => 'MOI', 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $this->moBolsa('MTO00099', '14200506', '111', 600000);

        $resp = $this->actingAs($this->contable())
            ->get(route('contable.redistribucion-mo.plano', ['mes' => 4, 'anio' => 2026, 'documento' => 9]));
        $resp->assertOk();

        $mov = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->getFile()->getPathname())->getSheetByName('Movimientocontable');
        $vio61 = false; $vio14 = false;
        foreach (range(2, $mov->getHighestRow()) as $r) {
            $cta = (string) $mov->getCell('C'.$r)->getValue();
            $cc  = (string) $mov->getCell('F'.$r)->getValue();
            $this->assertEqualsWithDelta(0, (float) $mov->getCell('J'.$r)->getValue(), 0.5); // base gravable = 0
            if (str_starts_with($cta, '6')) {
                $vio61 = true;
                $this->assertSame('30020105', $cc); // centro de costo de MTO00099 en la cuenta 61
                $this->assertEqualsWithDelta(600000, (float) $mov->getCell('H'.$r)->getValue(), 0.5); // débito (número normal)
                // El crédito, aunque sea 0, NO queda vacío.
                $this->assertNotNull($mov->getCell('I'.$r)->getValue());
                $this->assertEqualsWithDelta(0, (float) $mov->getCell('I'.$r)->getValue(), 0.5);
            }
            if (str_starts_with($cta, '14')) {
                $vio14 = true;
                $this->assertSame('', $cc); // la cuenta 14 NO lleva centro de costo
                $this->assertEqualsWithDelta(600000, (float) $mov->getCell('I'.$r)->getValue(), 0.5); // crédito (número normal)
                // El débito, aunque sea 0, NO queda vacío.
                $this->assertNotNull($mov->getCell('H'.$r)->getValue());
                $this->assertEqualsWithDelta(0, (float) $mov->getCell('H'.$r)->getValue(), 0.5);
            }
        }
        $this->assertTrue($vio61 && $vio14);
    }

    #[Test]
    public function la_lista_de_personas_viene_del_maestro_de_administracion(): void
    {
        // La lista la administra Administración → Mano de obra directa (mano_obra_directa).
        $this->homologarMO('14200506');
        ManoObraDirecta::create(['cedula' => '12345678', 'nombre' => 'VALENCIA VILLABONA ARLEY', 'activo' => true]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200506',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '', 'razon_social' => 'VALENCIA VILLABONA ARLEY',
            'estado_er' => -3874907, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 4, 'anio' => 2026,
        ]);

        $costo = app(RedistribucionMoEspecialService::class)->costoPorPersona(4, 2026);
        $this->assertEqualsWithDelta(3874907, $costo['12345678']['directo'], 0.5);
    }

    #[Test]
    public function retira_la_mo_de_los_terceros_registrados_de_las_bolsas_de_operaciones(): void
    {
        $this->homologarMO('14200506');
        $this->moBolsa('MTO00099', '14200506', '111', 600000, 4, 2026); // MO de ARLEY (abril)
        $this->moBolsa('MTO00099', '14200506', '999', 400000, 4, 2026); // MO de otro (abril)

        $svc     = app(DistribucionService::class);
        $periodo = Homologacion::periodo(2026, 4);

        // Sin registrar a ARLEY: la bolsa muestra la MO completa (600k + 400k).
        $saldos = $svc->saldosBolsasPorCuenta(['MTO00099'], $periodo, 2026, 4);
        $linea  = collect($saldos['MTO00099'])->firstWhere('cuenta_14', '14200506');
        $this->assertEqualsWithDelta(1000000, $linea['pendiente'], 0.5);

        // Al registrar a ARLEY, su MO del mes se retira de la bolsa: queda solo la del otro (400k).
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'ARLEY', 'activo' => true]);
        $saldos = $svc->saldosBolsasPorCuenta(['MTO00099'], $periodo, 2026, 4);
        $linea  = collect($saldos['MTO00099'])->firstWhere('cuenta_14', '14200506');
        $this->assertEqualsWithDelta(400000, $linea['pendiente'], 0.5);
    }

    #[Test]
    public function solo_contabilidad_ve_el_modulo(): void
    {
        $sin = User::factory()->create(['rol' => 'comercial', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver']]);
        $this->actingAs($sin)->get(route('contable.redistribucion-mo.index'))->assertForbidden();

        $this->actingAs($this->contable('ver'))->get(route('contable.redistribucion-mo.index'))->assertStatus(200);
    }
}
