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
        return ['26109505', 'CUENTA PUENTE EPS', $ced, 'APELLIDO NOMBRE', $un, $fecha,
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
        $resp->assertSee('debe traer estas 13 columnas', false);
        $resp->assertSee('Id. Tercero Mov', false);
        $resp->assertSee('Descripción Codigo PILA', false);
        $resp->assertSee('Real Descontado', false);
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
