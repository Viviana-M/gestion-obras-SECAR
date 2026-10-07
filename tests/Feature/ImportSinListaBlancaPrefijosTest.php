<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El cierre ya NO bota obras por prefijo: acepta cualquier código de obra no vacío
 * (antes una lista blanca de prefijos descartaba obras reales con saldo, p. ej. ADM, LOG,
 * FAD, FIL, VTD, G0…). Solo se descartan filas sin código o de totales.
 */
class ImportSinListaBlancaPrefijosTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    private function biable(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta', 'Nombre Auxiliar',
            'Debitos', 'Creditos', 'Movto Libro2', 'Periodo', 'Tercero', 'Nombre Tercero',
            'Tercero Docto', 'Razon Social Docto', 'Docto.',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) { $sheet->fromArray($f, null, 'A'.$r); $r++; }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Fila de cuenta 14 (Costos por aplicar) con saldo. */
    private function fila(string $un, float $movto): array
    {
        return [$un, 'OBRA '.$un, '14200105', 'AUX', abs($movto), 0, $movto, '202312', '900', 'PROV', '890319324', 'SECAR', 'CCC-1'];
    }

    #[Test]
    public function importa_obras_de_cualquier_prefijo_y_descarta_solo_vacias_o_totales(): void
    {
        $archivo = $this->biable([
            $this->fila('ADM00099', 1000),   // antes se botaba (prefijo ADM)
            $this->fila('LOG00099', 2000),   // LOG
            $this->fila('FIL00001', 3000),   // FIL
            $this->fila('VTD00001', 4000),   // VTD
            $this->fila('G0010453', 5000),   // empieza en G0
            $this->fila('MOB08644', 6000),   // uno de los que ya se aceptaban
            $this->fila('', 7000),           // sin código → se descarta
            $this->fila('Gran total', 8000), // total → se descarta
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 12, 'anio' => 2023])
            ->assertRedirect();

        foreach (['ADM00099', 'LOG00099', 'FIL00001', 'VTD00001', 'G0010453', 'MOB08644'] as $cod) {
            $this->assertSame(1, RegistroFinanciero::where('codigo_proyecto', $cod)->where('mes', 12)->where('anio', 2023)->count(),
                "La obra {$cod} debía importarse");
        }

        // No se creó nada para la fila sin código ni para el "Gran total".
        $this->assertSame(0, RegistroFinanciero::where('codigo_proyecto', '')->count());
        $this->assertSame(0, RegistroFinanciero::where('codigo_proyecto', 'like', '%total%')->count());
        $this->assertSame(0, RegistroFinanciero::where('codigo_proyecto', 'like', '%Total%')->count());

        // 6 obras válidas importadas en total.
        $this->assertSame(6, RegistroFinanciero::where('mes', 12)->where('anio', 2023)->count());
    }
}
