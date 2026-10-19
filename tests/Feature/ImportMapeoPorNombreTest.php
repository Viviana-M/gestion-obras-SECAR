<?php

namespace Tests\Feature;

use App\Imports\Financiero\MovimientoBiableImport;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El importador BIABLE reconoce las columnas POR NOMBRE (no por posición): el orden del archivo
 * puede cambiar y se toleran variaciones de encabezado (mayúsculas/acentos/espacios). Si falta una
 * columna OBLIGATORIA, la carga se rechaza con un mensaje claro y NO se borra el período.
 */
class ImportMapeoPorNombreTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $filas */
    private function excel(array $headers, array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($filas as $fila) {
            $sheet->fromArray($fila, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function reconoce_las_columnas_por_nombre_aunque_cambie_el_orden_y_el_formato(): void
    {
        // Encabezados en OTRO orden, con acentos/mayúsculas/espacios distintos a los "canónicos".
        $archivo = $this->excel(
            [
                'Razón Social Docto', 'Docto.', 'Tercero Docto', 'Tipo de Documento', 'Fecha del documento',
                'VALOR CRÉDITO', 'Valor Débito', 'Descripción cuenta', 'Cuenta Contable', 'Unidad de Negocio',
            ],
            [
                ['PROVEEDOR SAS', '7970', '901510086', 'FSE', '20260815', 0, 500000, 'MATERIALES', '14200563', 'INS00009'],
            ]
        );

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2026])
            ->assertRedirect();

        $reg = RegistroFinanciero::where('cuenta_contable', '14200563')->sole();
        $this->assertSame('INS00009', $reg->codigo_proyecto);
        $this->assertSame('901510086', $reg->tercero_dcto);     // Tercero Docto (no hay col "Tercero" de movimiento)
        $this->assertSame('PROVEEDOR SAS', $reg->razon_social);
        $this->assertSame('7970', (string) $reg->documento);
        $this->assertSame('FSE', $reg->tipo_documento);
        $this->assertSame('2026-08-15', \Illuminate\Support\Carbon::parse($reg->fecha_docto)->format('Y-m-d'));
        $this->assertEqualsWithDelta(500000, (float) $reg->valor_debito, 0.5);
    }

    #[Test]
    public function rechaza_la_carga_si_falta_una_columna_obligatoria_sin_borrar_el_periodo(): void
    {
        // Ya hay información BIABLE del período 8/2026 (p. ej. una carga anterior correcta).
        RegistroFinanciero::create([
            'codigo_proyecto' => 'INS00009', 'cuenta_contable' => '14200563', 'cuenta_mayor' => 'Costos por aplicar',
            'valor_debito' => 100000, 'valor_credito' => 0, 'movto_libro1' => 100000, 'estado_er' => -100000,
            'signo_contable' => -1, 'periodo' => '202608', 'mes' => 8, 'anio' => 2026, 'origen' => 'biable',
        ]);

        // Archivo SIN la columna obligatoria "Docto.".
        $archivo = $this->excel(
            ['Unidad de Negocio', 'Cuenta Contable', 'Valor Débito', 'Valor Crédito', 'Tercero Docto', 'Razón Social Docto'],
            [['INS00009', '14200599', 500000, 0, '901510086', 'PROVEEDOR SAS']]
        );

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2026])
            ->assertRedirect()
            ->assertSessionHas('error');

        // El período NO se tocó: la fila anterior sigue ahí y la nueva no entró.
        $this->assertSame(1, RegistroFinanciero::where('mes', 8)->where('anio', 2026)->count());
        $this->assertSame(0, RegistroFinanciero::where('cuenta_contable', '14200599')->count());
    }

    #[Test]
    public function el_validador_de_encabezados_lista_las_columnas_obligatorias_que_faltan(): void
    {
        // Falta "Docto." y "Tercero Docto"; "Razón social Docto" sí está (con variación de formato).
        $faltan = MovimientoBiableImport::encabezadosFaltantes([
            'Unidad de negocio', 'Cuenta contable', 'Valor débito', 'Valor crédito', 'RAZON SOCIAL DOCTO',
        ]);

        $this->assertContains('Docto. (número)', $faltan);
        $this->assertContains('Tercero Docto', $faltan);
        $this->assertNotContains('Razón social Docto', $faltan);
        $this->assertNotContains('Unidad de negocio', $faltan);
    }
}
