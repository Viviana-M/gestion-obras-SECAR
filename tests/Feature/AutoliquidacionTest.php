<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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

    /** Genera un xlsx con encabezado + filas de datos (fecha en 2026-04). */
    private function planilla(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $encabezado = ['ID Cuenta','Cuenta contable','Id. Tercero Mov','Razon Social','Id. U.N. Mov','Fecha','Descripción UN','Descripción Codigo PILA','Empleado','Nombre del empl','Aporte del empl','Aporte empresa','Real Descontado'];
        $sheet->fromArray($encabezado, null, 'A1');
        $r = 2;
        foreach ($filas as $f) {
            $sheet->fromArray($f, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'pila').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'pila.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function fila(string $ced, string $un, string $concepto, float $empl, float $empresa, float $real, string $fecha = '2026-04-15'): array
    {
        $serial = ExcelDate::PHPToExcel(new \DateTime($fecha));
        return ['26109505','CUENTA PUENTE EPS',$ced,'APELLIDO NOMBRE',$un,$serial,'DESC UN',$concepto,$ced,'APELLIDO NOMBRE',$empl,$empresa,$real];
    }

    #[Test]
    public function carga_la_planilla_deriva_el_periodo_y_guarda_el_detalle(): void
    {
        $archivo = $this->planilla([
            $this->fila('111', 'ADM00099', 'Aporte EPS', 50000, 30000, 100000),
            $this->fila('222', 'OB4501', 'Aporte AFP', 40000, 25000, 0),
            $this->fila('111', 'ADM00099', 'Aporte ARL', 0, 12000, 0),
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect();

        // Período derivado de la fecha (abril 2026).
        $this->assertSame(3, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        $this->assertDatabaseHas('autoliquidacion_aportes', [
            'cedula' => '222', 'un_codigo' => 'OB4501', 'concepto_pila' => 'Aporte AFP',
            'aporte_empresa' => 25000, 'mes' => 4, 'anio' => 2026,
        ]);
        // 2 personas distintas.
        $this->assertSame(2, AutoliquidacionAporte::where('mes', 4)->distinct()->count('cedula'));
    }

    #[Test]
    public function recargar_el_mismo_periodo_reemplaza_lo_anterior(): void
    {
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 1, 2, 3)]),
        ])->assertRedirect();
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());

        // Recarga con otras filas del MISMO período => reemplaza.
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([
                $this->fila('999', 'OB4501', 'AFP', 4, 5, 6),
                $this->fila('888', 'OB4501', 'ARL', 7, 8, 9),
            ]),
        ])->assertRedirect();

        $this->assertSame(2, AutoliquidacionAporte::where('mes', 4)->where('anio', 2026)->count());
        $this->assertSame(0, AutoliquidacionAporte::where('cedula', '111')->count()); // lo viejo se borró
    }

    #[Test]
    public function si_el_usuario_elige_un_periodo_distinto_al_archivo_no_carga(): void
    {
        $this->actingAs($this->contable())->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 1, 2, 3)]),
            'mes' => 5, 'anio' => 2026, // el archivo es de abril
        ])->assertRedirect();

        $this->assertSame(0, AutoliquidacionAporte::count());
    }

    #[Test]
    public function un_usuario_sin_permiso_de_editar_no_puede_cargar(): void
    {
        $this->actingAs($this->contable('ver'))->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 1, 2, 3)]),
        ])->assertForbidden();
    }

    #[Test]
    public function el_resumen_muestra_totales_y_desgloses(): void
    {
        AutoliquidacionAporte::create(['cedula'=>'111','un_codigo'=>'ADM00099','un_descripcion'=>'ADMIN','concepto_pila'=>'Aporte EPS','aporte_empleado'=>50000,'aporte_empresa'=>30000,'real_descontado'=>100000,'fecha'=>'2026-04-15','mes'=>4,'anio'=>2026]);
        AutoliquidacionAporte::create(['cedula'=>'222','un_codigo'=>'OB4501','un_descripcion'=>'OBRA','concepto_pila'=>'Aporte AFP','aporte_empleado'=>40000,'aporte_empresa'=>25000,'real_descontado'=>0,'fecha'=>'2026-04-15','mes'=>4,'anio'=>2026]);

        $resp = $this->actingAs($this->contable('ver'))
            ->get(route('contable.autoliquidacion.index', ['mes' => 4, 'anio' => 2026]));

        $resp->assertStatus(200);
        $resp->assertSee('ADM00099');
        $resp->assertSee('OB4501');
        $resp->assertSee('Aporte EPS');
        $resp->assertSee('Por unidad de negocio');
        $resp->assertSee('Por concepto PILA');
    }
}
