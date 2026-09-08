<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoliquidacionTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true,
            'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    /**
     * xlsx con la estructura ESTÁNDAR (13 columnas); el período se toma de la columna Fecha.
     * Header: ID Cuenta, Cuenta contable, Id. Tercero Mov, Razon Social, Id. U.N. Mov, Fecha,
     * Descripción UN, Descripción Codigo PILA, Empleado, Nombre del empl, Aporte del empl,
     * Aporte empresa, Real Descontado.
     */
    private function planilla(array $filas, string $nombre = 'Autoliquidación Abril.xlsx'): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov', 'Fecha',
            'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
            'Aporte del empl', 'Aporte empresa', 'Real Descontado',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) {
            $sheet->fromArray($f, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'pila').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, $nombre,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Fila de 13 columnas en el orden estándar.
     * [ID Cuenta, Cuenta contable, cédula, Razon Social, U.N., Fecha, Descripción UN,
     *  concepto, Empleado, Nombre, Aporte empl, Aporte empresa, Real Descontado]
     */
    private function fila(string $ced, string $un, string $concepto, float $empresa,
        string $fecha = '2026-04-30', float $empleado = 0, float $real = 0): array
    {
        // ID Cuenta empieza en 14 (la que se reclasifica); las demás las obvia el importador.
        return ['14200569', 'CUENTA PUENTE EPS', $ced, 'APELLIDO NOMBRE', $un, $fecha,
            'AREA '.$un, $concepto, $ced, 'APELLIDO NOMBRE', $empleado, $empresa, $real];
    }

    #[Test]
    public function toma_el_periodo_de_la_columna_fecha_y_guarda_la_estructura_estandar(): void
    {
        $archivo = $this->planilla([
            $this->fila('111', 'ADM00099', 'Aporte EPS', 30000, '2026-04-30', 4000, 34000),
            $this->fila('222', 'OB4501', 'Aporte AFP', 25000, '2026-04-30', 3000, 28000),
            $this->fila('111', 'ADM00099', 'Aporte ARL', 12000, '2026-04-30', 0, 12000),
        ], 'Autoliquidación Abril.xlsx');

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Período tomado de la columna Fecha (2026-04-30) → abril 2026.
        $this->assertSame(3, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        // Guarda las columnas del estándar (un_descripcion, aporte_empleado, real_descontado).
        $this->assertDatabaseHas('autoliquidacion_aportes', [
            'cedula' => '222', 'un_codigo' => 'OB4501', 'concepto_pila' => 'Aporte AFP',
            'un_descripcion' => 'AREA OB4501', 'aporte_empleado' => 3000, 'aporte_empresa' => 25000,
            'real_descontado' => 28000, 'mes' => 4, 'anio' => 2026,
        ]);
        // 2 personas distintas.
        $this->assertSame(2, AutoliquidacionAporte::where('mes', 4)->distinct()->count('cedula'));
    }

    #[Test]
    public function el_resumen_de_abril_muestra_el_total_de_aporte_empresa_por_un(): void
    {
        $archivo = $this->planilla([
            $this->fila('111', 'ADM00099', 'Aporte EPS', 30000),
            $this->fila('222', 'OB4501', 'Aporte AFP', 25000),
        ], 'Autoliquidación Abril.xlsx');

        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect();

        $resp = $this->actingAs($c)->get(route('contable.autoliquidacion.index', ['mes' => 4, 'anio' => 2026]));
        $resp->assertStatus(200);
        $resp->assertSee('Por unidad de negocio');
        $resp->assertSee('ADM00099');
        $resp->assertSee('OB4501');
        $resp->assertSee('$30.000', false); // aporte empresa de la UN ADM00099
    }

    #[Test]
    public function sin_fecha_valida_avisa_y_no_carga(): void
    {
        $this->actingAs($this->contable())->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000, '')], 'plana.xlsx'),
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AutoliquidacionAporte::count());
    }

    #[Test]
    public function rechaza_un_archivo_que_no_es_la_planilla_pila(): void
    {
        // Un export de movimiento contable (Fecha en otra columna, sin "Aporte empresa") debe
        // rechazarse con un mensaje claro y no cargar nada.
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            'ID Cuenta', 'Cuenta contable', 'id. C.O. del Mov', 'Id. Tercero Mov', 'Razon Social',
            'Id. Sucursal', 'Id. Ccosto Mov', 'Id. U.N. Mov', 'Tipo Documento', 'Consecutivo Doc',
            'Nro Cuota Cruce', 'Fecha', 'Valor Debito', 'Valor Credito', 'Valor Debito 2',
        ], null, 'A1');
        $ss->getActiveSheet()->fromArray(
            ['26109505', 'CUENTA PUENTE EPS', '001', '1003152760', 'PALOMINO YEINER', '', '', 'ADM00099', '', '0', '0', '46265', '78916', '0', '78916'],
            null, 'A3'
        );
        $path = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        (new Xlsx($ss))->save($path);
        $archivo = new UploadedFile($path, 'movimiento.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contable())->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AutoliquidacionAporte::count());
    }

    #[Test]
    public function recargar_el_mismo_periodo_reemplaza_lo_anterior(): void
    {
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000, '2026-04-30')]),
        ])->assertRedirect();
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());

        // Recarga con otras filas del MISMO período (abril) => reemplaza.
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([
                $this->fila('999', 'OB4501', 'AFP', 5000, '2026-04-30'),
                $this->fila('888', 'OB4501', 'ARL', 8000, '2026-04-30'),
            ]),
        ])->assertRedirect();

        $this->assertSame(2, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        $this->assertSame(0, AutoliquidacionAporte::where('cedula', '111')->count()); // lo viejo se borró
    }

    #[Test]
    public function un_archivo_con_varias_hojas_no_duplica_los_aportes(): void
    {
        // xlsx con DOS hojas que contienen los mismos datos: solo debe importar la primera.
        $ss = new Spreadsheet();
        $hdr = ['ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov', 'Fecha',
            'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
            'Aporte del empl', 'Aporte empresa', 'Real Descontado'];
        $fila = $this->fila('111', 'ADM00099', 'EPS', 30000);
        $s1 = $ss->getActiveSheet(); $s1->setTitle('Datos'); $s1->fromArray($hdr, null, 'A1'); $s1->fromArray($fila, null, 'A2');
        $s2 = $ss->createSheet(); $s2->setTitle('Copia'); $s2->fromArray($hdr, null, 'A1'); $s2->fromArray($fila, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'pila').'.xlsx';
        (new Xlsx($ss))->save($path);
        $archivo = new UploadedFile($path, 'abril.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect();

        // Solo 1 fila (la de la primera hoja), no 2.
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        $this->assertEqualsWithDelta(30000, (float) AutoliquidacionAporte::where('mes', 4)->sum('aporte_empresa'), 0.5);
    }

    #[Test]
    public function vaciar_borra_los_aportes_del_periodo(): void
    {
        AutoliquidacionAporte::create(['cedula' => '1', 'concepto_pila' => 'EPS', 'aporte_empresa' => 100, 'mes' => 4, 'anio' => 2026]);
        AutoliquidacionAporte::create(['cedula' => '2', 'concepto_pila' => 'AFP', 'aporte_empresa' => 200, 'mes' => 4, 'anio' => 2026]);
        AutoliquidacionAporte::create(['cedula' => '3', 'concepto_pila' => 'EPS', 'aporte_empresa' => 300, 'mes' => 5, 'anio' => 2026]); // otro mes: se conserva

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.vaciar'), ['mes' => 4, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 5)->where('anio', 2026)->count()); // no toca mayo
    }

    #[Test]
    public function un_usuario_sin_permiso_de_editar_no_puede_vaciar(): void
    {
        AutoliquidacionAporte::create(['cedula' => '1', 'concepto_pila' => 'EPS', 'aporte_empresa' => 100, 'mes' => 4, 'anio' => 2026]);

        $this->actingAs($this->contable('ver'))
            ->post(route('contable.autoliquidacion.vaciar'), ['mes' => 4, 'anio' => 2026])
            ->assertForbidden();

        $this->assertSame(1, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
    }

    #[Test]
    public function un_usuario_sin_permiso_de_editar_no_puede_cargar(): void
    {
        $this->actingAs($this->contable('ver'))->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000)]),
        ])->assertForbidden();
    }

    #[Test]
    public function la_pantalla_indica_las_columnas_del_archivo_plano(): void
    {
        $resp = $this->actingAs($this->contable())
            ->get(route('contable.autoliquidacion.index'));

        $resp->assertStatus(200);
        $resp->assertSee('Columnas que reconoce el módulo', false);
        $resp->assertSee('Id. Tercero Mov', false);
        $resp->assertSee('Descripción Codigo PILA', false);
        $resp->assertSee('Real Descontado', false);
    }

    #[Test]
    public function importa_el_movimiento_contable_pila_del_erp_reconociendo_columnas_por_nombre(): void
    {
        // El exporte real del cliente es un movimiento contable de la PILA (SIESA, ~27 columnas)
        // donde el detalle por empleado va en columnas aparte (Empleado, Aporte empresa, Descripción
        // Codigo PILA) junto al fondo/EPS (Id. Tercero Mov / Razon Social) de cada línea. Además
        // trae un renglón de TOTALES al pie (sin empleado) que NO debe importarse.
        $hdr = [
            'ID Cuenta', 'Cuenta contable', 'id. C.O. del Mov', 'Id. Tercero Mov', 'Razon Social',
            'Id. Sucursal', 'Id. Ccosto Mov', 'Id. U.N. Mov', 'Tipo Documento', 'Consecutivo Doc',
            'Nro Cuota Cruce', 'Fecha', 'Valor Debito', 'Valor Credito', 'Valor Debito 2',
            'Valor Credito 2', 'Descripción Cco', 'Descripción C.O', 'Descripción UN', 'Codigo PILA',
            'Descripción Codigo PILA', 'Empleado', 'Nombre del empl', 'NDC', 'Aporte del empl',
            'Aporte empresa', 'Real Descontado',
        ];
        // Línea de aporte: fondo (Id. Tercero Mov) + empleado + aporte empresa por concepto.
        $aporte = function (string $fondoNit, string $fondoNom, string $empl, string $emplNom,
            string $concepto, float $empresa, string $un = 'ADM00099') {
            return ['14200569', 'APORTE EPS-61309505', '001', $fondoNit, $fondoNom, '01', 'C1', $un,
                'CC', '1', '0', '2026-08-31', '0', '0', '0', '0', 'cco', 'co', 'AREA '.$un, '23',
                $concepto, $empl, $emplNom, '', '0', $empresa, '0'];
        };
        // Renglón de totales al pie: SIN empleado ni fondo, solo el gran total → se descarta.
        $totales = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
            '', '', '', '', '', 165692, ''];

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray($hdr, null, 'A1');
        $sheet->fromArray($aporte('800251440', 'E.P.S SANITAS', '1003152760', 'PALOMINO YEINER', 'Aporte Obligatorio EPS Empresa', 121394), null, 'A2');
        $sheet->fromArray($aporte('800088702', 'EPS SURA', '1005785995', 'REVELO ARIANA', 'Aporte Obligatorio EPS Empresa', 44298), null, 'A3');
        $sheet->fromArray($totales, null, 'A4');
        $path = tempnam(sys_get_temp_dir(), 'siesa').'.xlsx';
        (new Xlsx($ss))->save($path);
        $archivo = new UploadedFile($path, 'Autoliquidacion_Agosto.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect()->assertSessionHas('success');

        // Período tomado de la columna Fecha (2026-08-31) → agosto 2026.
        // Solo 2 filas (las 2 de aporte); el renglón de totales NO se importa.
        $this->assertSame(2, AutoliquidacionAporte::where('mes', 8)->where('anio', 2026)->count());

        // Cada línea guarda el fondo (Id. Tercero Mov → cedula) y el empleado por separado,
        // con su aporte empresa y el concepto (la DESCRIPCIÓN, no el código numérico).
        $this->assertDatabaseHas('autoliquidacion_aportes', [
            'cedula' => '800251440', 'razon_social' => 'E.P.S SANITAS',
            'empleado' => '1003152760', 'empleado_nombre' => 'PALOMINO YEINER',
            'concepto_pila' => 'Aporte Obligatorio EPS Empresa', 'aporte_empresa' => 121394,
            'un_codigo' => 'ADM00099', 'mes' => 8, 'anio' => 2026,
        ]);

        // El total de aporte empresa es la suma de las 2 líneas de aporte (no incluye el total al pie).
        $this->assertEqualsWithDelta(165692, (float) AutoliquidacionAporte::where('mes', 8)->sum('aporte_empresa'), 0.5);
    }

    #[Test]
    public function solo_importa_las_lineas_cuya_id_cuenta_empieza_en_14(): void
    {
        // Solo el aporte que va a la cuenta 14 (por aplicar) se reclasifica. Las líneas con ID
        // Cuenta 26 (puente/descuento), 51/52 (gasto), etc. se deben obviar.
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov', 'Fecha',
            'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
            'Aporte del empl', 'Aporte empresa', 'Real Descontado',
        ], null, 'A1');
        $sheet->fromArray(['14200569', 'APORTE EPS', '800251440', 'SANITAS', 'ADM00099', '2026-08-31', 'A', 'Aporte EPS', '111', 'X', 0, 50000, 0], null, 'A2');
        $sheet->fromArray(['26100101', 'CUENTA PUENTE', '800251440', 'SANITAS', 'ADM00099', '2026-08-31', 'A', 'Descuento', '111', 'X', 0, 30000, 0], null, 'A3');
        $sheet->fromArray(['51050101', 'GASTO ADMON', '800251440', 'SANITAS', 'ADM00099', '2026-08-31', 'A', 'Aporte EPS', '111', 'X', 0, 20000, 0], null, 'A4');
        $path = tempnam(sys_get_temp_dir(), 'idc').'.xlsx';
        (new Xlsx($ss))->save($path);
        $archivo = new UploadedFile($path, 'autoliq.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])->assertRedirect();

        // Solo la línea de la cuenta 14 (50.000); las de 26 y 51 se obvian.
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 8)->where('anio', 2026)->count());
        $this->assertEqualsWithDelta(50000, (float) AutoliquidacionAporte::where('mes', 8)->sum('aporte_empresa'), 0.5);
        $this->assertSame('14200569', AutoliquidacionAporte::where('mes', 8)->first()->id_cuenta);
    }

    #[Test]
    public function el_desglose_por_concepto_no_muestra_valores_en_cero(): void
    {
        $archivo = $this->planilla([
            $this->fila('111', 'ADM00099', 'Aporte EPS', 30000),
            $this->fila('111', 'ADM00099', 'Aporte FSP', 0),                 // concepto en 0 → no se muestra
            $this->fila('444', 'ADM00099', 'Solo empleado', 0, '2026-04-30', 5000, 5000),
        ], 'Autoliquidación Abril.xlsx');

        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])->assertRedirect();

        $resp = $this->actingAs($c)->get(route('contable.autoliquidacion.index', ['mes' => 4, 'anio' => 2026]));
        $resp->assertStatus(200);

        // En el desglose por concepto PILA no aparecen los conceptos en 0.
        $porConcepto = $resp->viewData('porConcepto')->pluck('concepto_pila')->all();
        $this->assertContains('Aporte EPS', $porConcepto);
        $this->assertNotContains('Aporte FSP', $porConcepto);
        $this->assertNotContains('Solo empleado', $porConcepto);
    }

    #[Test]
    public function el_resumen_muestra_total_aporte_empresa_y_desgloses(): void
    {
        AutoliquidacionAporte::create(['cedula' => '111', 'un_codigo' => 'ADM00099', 'concepto_pila' => 'Aporte EPS', 'aporte_empresa' => 30000, 'mes' => 6, 'anio' => 2026]);
        AutoliquidacionAporte::create(['cedula' => '222', 'un_codigo' => 'OB4501', 'concepto_pila' => 'Aporte AFP', 'aporte_empresa' => 25000, 'mes' => 6, 'anio' => 2026]);

        $resp = $this->actingAs($this->contable('ver'))
            ->get(route('contable.autoliquidacion.index', ['mes' => 6, 'anio' => 2026]));

        $resp->assertStatus(200);
        $resp->assertSee('Por unidad de negocio');
        $resp->assertSee('Por concepto PILA');
        $resp->assertSee('ADM00099');
        $resp->assertSee('OB4501');
        $resp->assertSee('Aporte EPS');
        // Total aporte empresa 30.000 + 25.000 = 55.000.
        $resp->assertSee('$55.000', false);
    }
}
