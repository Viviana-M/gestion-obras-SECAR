<?php

namespace Tests\Feature;

use App\Models\AutoliquidacionAporte;
use App\Models\CargaFinanciera;
use App\Models\CargaPorLote;
use App\Models\RegistroFinanciero;
use App\Models\SaldoBalance;
use App\Models\User;
use App\Support\Lotes\ImportadorAutoliquidacion;
use App\Support\Lotes\MotorLotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Procesamiento POR LOTES: el cierre BIABLE (antes sin pruebas) y el motor de lotes
 * (ventanas de filas + barra de progreso) para autoliquidación.
 */
class CargaPorLotesTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true,
            'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    /** xlsx del BIABLE (cierre) con encabezados que "sluggean" a las claves del importador. */
    private function biable(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta', 'Nombre Auxiliar',
            'Debitos', 'Creditos', 'Movto Libro2', 'Periodo', 'Tercero', 'Nombre Tercero',
            'Tercero Docto', 'Razon Social Docto',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) {
            $sheet->fromArray($f, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Fila de costo (cuenta 6 → registro) o de balance (cuenta 1/2/3 → saldo). */
    private function fila(string $un, string $nombreUn, string $cuenta, float $deb, float $cred, float $movto, string $periodo = '202408'): array
    {
        return [$un, $nombreUn, $cuenta, 'AUX', $deb, $cred, $movto, $periodo, '900123', 'TERCERO SA', '890319324', 'SECAR'];
    }

    #[Test]
    public function el_cierre_importa_registros_y_saldos(): void
    {
        $archivo = $this->biable([
            $this->fila('OB4501', 'OBRA UNO', '61350505', 1000, 0, 1000),  // costo → registro_financieros
            $this->fila('OB4501', 'OBRA UNO', '11050501', 500, 0, 500),    // activo → saldos_balance
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2024])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, RegistroFinanciero::where('mes', 8)->where('anio', 2024)->count());
        $this->assertSame(1, SaldoBalance::where('mes', 8)->where('anio', 2024)->count());
        $this->assertDatabaseHas('carga_financieras', ['mes' => 8, 'anio' => 2024, 'estado' => 'completado']);

        // El registro toma el tercero del MOVIMIENTO (col Tercero), no el del documento.
        $this->assertSame('900123', RegistroFinanciero::where('mes', 8)->first()->tercero_dcto);
    }

    #[Test]
    public function recargar_el_mismo_mes_del_cierre_reemplaza(): void
    {
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.carga.store'), [
            'archivo' => $this->biable([$this->fila('OB4501', 'OBRA UNO', '61350505', 1000, 0, 1000)]),
            'mes' => 8, 'anio' => 2024,
        ])->assertRedirect();
        $this->assertSame(1, RegistroFinanciero::where('mes', 8)->where('anio', 2024)->count());

        // Recarga del MISMO mes con dos filas nuevas → reemplaza (no acumula).
        $this->actingAs($c)->post(route('contable.carga.store'), [
            'archivo' => $this->biable([
                $this->fila('OB4501', 'OBRA UNO', '61350505', 2000, 0, 2000),
                $this->fila('MTO00099', 'MANTENIMIENTO', '61350505', 3000, 0, 3000),
            ]),
            'mes' => 8, 'anio' => 2024,
        ])->assertRedirect();

        $this->assertSame(2, RegistroFinanciero::where('mes', 8)->where('anio', 2024)->count());
    }

    /** xlsx de autoliquidación PILA estándar (13 columnas). */
    private function planillaPila(int $nFilas): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov', 'Fecha',
            'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
            'Aporte del empl', 'Aporte empresa', 'Real Descontado',
        ], null, 'A1');
        for ($i = 1; $i <= $nFilas; $i++) {
            $sheet->fromArray([
                '14200569', 'APORTE EPS', '80012345', 'SANITAS', 'ADM00099', '2026-08-31',
                'AREA', 'Aporte EPS', (string) (1000 + $i), 'EMPLEADO '.$i, 0, 10000, 0,
            ], null, 'A'.($i + 1));
        }
        $dir = storage_path('app/pruebas');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $rel = 'pruebas/pila_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save(storage_path('app/'.$rel));

        return $rel;
    }

    #[Test]
    public function el_motor_procesa_en_varias_ventanas(): void
    {
        // 5 filas, ventanas de 2 → 3 lotes; debe insertarlas todas y avanzar el progreso.
        $rel   = $this->planillaPila(5);
        $imp   = new ImportadorAutoliquidacion();
        $motor = new MotorLotes();

        $a = $motor->analizar($imp, $rel);
        $this->assertSame(5, $a['total']);

        $carga = CargaPorLote::create([
            'tipo' => 'autoliquidacion', 'mes' => $a['mes'], 'anio' => $a['anio'],
            'ruta_archivo' => $rel, 'total_filas' => $a['total'], 'meta_lotes' => $a['meta'],
            'estado' => 'procesando',
        ]);

        $motor->procesarSiguiente($imp, $carga, 2);
        $this->assertSame(2, $carga->getFilasProcesadas());
        $this->assertSame(2, AutoliquidacionAporte::count());
        $this->assertSame('procesando', $carga->getEstado());

        $motor->procesarSiguiente($imp, $carga, 2);
        $motor->procesarSiguiente($imp, $carga, 2);

        $this->assertSame('completado', $carga->getEstado());
        $this->assertSame(5, AutoliquidacionAporte::count());
        $this->assertSame(5, $carga->getFilasInsertadas());
    }

    #[Test]
    public function el_flujo_ajax_de_autoliquidacion_prepara_y_procesa(): void
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'ID Cuenta', 'Cuenta contable', 'Id. Tercero Mov', 'Razon Social', 'Id. U.N. Mov', 'Fecha',
            'Descripción UN', 'Descripción Codigo PILA', 'Empleado', 'Nombre del empl',
            'Aporte del empl', 'Aporte empresa', 'Real Descontado',
        ], null, 'A1');
        $sheet->fromArray(['14200569', 'EPS', '800', 'SANITAS', 'ADM00099', '2026-08-31', 'A', 'EPS', '111', 'X', 0, 15000, 0], null, 'A2');
        $sheet->fromArray(['14200569', 'AFP', '801', 'PORVENIR', 'ADM00099', '2026-08-31', 'A', 'AFP', '222', 'Y', 0, 25000, 0], null, 'A3');
        $path = tempnam(sys_get_temp_dir(), 'pila').'.xlsx';
        (new Xlsx($ss))->save($path);
        $archivo = new UploadedFile($path, 'agosto.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $c = $this->contable();

        $prep = $this->actingAs($c)->postJson(route('contable.autoliquidacion.preparar'), ['archivo' => $archivo]);
        $prep->assertOk()->assertJsonStructure(['carga_id', 'total', 'tam']);
        $this->assertSame(2, $prep->json('total'));

        $proc = $this->actingAs($c)->postJson(route('contable.autoliquidacion.procesar'), ['carga_id' => $prep->json('carga_id')]);
        $proc->assertOk();
        $this->assertTrue($proc->json('done'));
        $this->assertSame(2, AutoliquidacionAporte::where('mes', 8)->where('anio', 2026)->count());
    }

    #[Test]
    public function un_usuario_sin_permiso_de_editar_no_puede_preparar(): void
    {
        $this->actingAs($this->contable('ver'))
            ->postJson(route('contable.autoliquidacion.preparar'), [])
            ->assertForbidden();
    }
}
