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
     * xlsx con la estructura NUEVA (11 columnas) y el período en el NOMBRE del archivo.
     * Header: ID Cuenta, Cuenta contable, id. C.O. del Mov, Id. Tercero Mov, Razon Social,
     * Id. U.N. Mov, Descripción Codigo PILA, Empleado, Nombre del empl, NDC, Aporte empresa.
     */
    private function planilla(array $filas, string $nombre = '2026_06.xlsx'): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'ID Cuenta', 'Cuenta contable', 'id. C.O. del Mov', 'Id. Tercero Mov', 'Razon Social',
            'Id. U.N. Mov', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl', 'NDC', 'Aporte empresa',
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

    private function fila(string $ced, string $un, string $concepto, float $empresa, string $co = 'CO-01', string $ndc = 'ND-9'): array
    {
        // [ID Cuenta, Cuenta contable, C.O., cédula, Razon Social, U.N., concepto, Empleado, Nombre, NDC, Aporte empresa]
        return ['26109505', 'CUENTA PUENTE EPS', $co, $ced, 'APELLIDO NOMBRE', $un, $concepto, $ced, 'APELLIDO NOMBRE', $ndc, $empresa];
    }

    #[Test]
    public function toma_el_periodo_del_nombre_del_archivo_y_guarda_la_estructura_nueva(): void
    {
        $archivo = $this->planilla([
            $this->fila('111', 'ADM00099', 'Aporte EPS', 30000, 'CO-ADM', 'NDC-1'),
            $this->fila('222', 'OB4501', 'Aporte AFP', 25000, 'CO-OBR', 'NDC-2'),
            $this->fila('111', 'ADM00099', 'Aporte ARL', 12000, 'CO-ADM', 'NDC-3'),
        ], '2026_06.xlsx');

        $this->actingAs($this->contable())
            ->post(route('contable.autoliquidacion.store'), ['archivo' => $archivo])
            ->assertRedirect();

        // Período tomado del nombre "2026_06" → junio 2026.
        $this->assertSame(3, AutoliquidacionAporte::where('mes', 6)->where('anio', 2026)->count());
        // Guarda las columnas nuevas (centro_operacion, ndc) y el aporte empresa.
        $this->assertDatabaseHas('autoliquidacion_aportes', [
            'cedula' => '222', 'un_codigo' => 'OB4501', 'concepto_pila' => 'Aporte AFP',
            'centro_operacion' => 'CO-OBR', 'ndc' => 'NDC-2', 'aporte_empresa' => 25000,
            'mes' => 6, 'anio' => 2026,
        ]);
        // 2 personas distintas.
        $this->assertSame(2, AutoliquidacionAporte::where('mes', 6)->distinct()->count('cedula'));
    }

    #[Test]
    public function el_nombre_sin_patron_aaaa_mm_avisa_y_no_carga(): void
    {
        $this->actingAs($this->contable())->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000)], 'planilla_pila.xlsx'),
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AutoliquidacionAporte::count());
    }

    #[Test]
    public function recargar_el_mismo_periodo_reemplaza_lo_anterior(): void
    {
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000)], '2026_06.xlsx'),
        ])->assertRedirect();
        $this->assertSame(1, AutoliquidacionAporte::where('mes', 6)->where('anio', 2026)->count());

        // Recarga con otras filas del MISMO período => reemplaza.
        $this->actingAs($c)->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([
                $this->fila('999', 'OB4501', 'AFP', 5000),
                $this->fila('888', 'OB4501', 'ARL', 8000),
            ], '2026_06.xlsx'),
        ])->assertRedirect();

        $this->assertSame(2, AutoliquidacionAporte::where('mes', 6)->where('anio', 2026)->count());
        $this->assertSame(0, AutoliquidacionAporte::where('cedula', '111')->count()); // lo viejo se borró
    }

    #[Test]
    public function un_usuario_sin_permiso_de_editar_no_puede_cargar(): void
    {
        $this->actingAs($this->contable('ver'))->post(route('contable.autoliquidacion.store'), [
            'archivo' => $this->planilla([$this->fila('111', 'ADM00099', 'EPS', 2000)], '2026_06.xlsx'),
        ])->assertForbidden();
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
