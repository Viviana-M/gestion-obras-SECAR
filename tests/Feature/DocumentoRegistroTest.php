<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentoRegistroTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** BIABLE con la columna "Docto." (además de las demás que el importador reconoce). */
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
        foreach ($filas as $f) {
            $sheet->fromArray($f, null, 'A'.$r);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function fila(string $un, string $cuenta, float $movto, string $docto): array
    {
        // [UN, nombreUN, cuenta, aux, deb, cred, movto, periodo, tercero, nombreTercero, terceroDocto, razonDocto, docto]
        return [$un, 'OBRA '.$un, $cuenta, 'AUX', abs($movto), 0, $movto, '202408', '900', 'PROV', '890319324', 'SECAR', $docto];
    }

    #[Test]
    public function guarda_el_numero_de_documento_de_cada_movimiento(): void
    {
        $archivo = $this->biable([
            $this->fila('OB4501', '61350505', 1000, 'CCC-00000180'),
            $this->fila('OB4501', '61350505', 2000, 'FSP-00002775'),
            $this->fila('OB4501', '61350505', 3000, 'CCC-00000180'),   // mismo documento, otra línea
            $this->fila('OB4501', '61350505', 500, ''),                // sin documento → null
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2024])
            ->assertRedirect();

        // Los movimientos quedan con su número de documento.
        $this->assertSame(2, RegistroFinanciero::where('documento', 'CCC-00000180')->count());
        $this->assertSame(1, RegistroFinanciero::where('documento', 'FSP-00002775')->count());
        // El vacío queda en null (no cadena vacía).
        $this->assertSame(1, RegistroFinanciero::whereNull('documento')->count());

        // Consulta de reconciliación: agrupar por documento en una obra.
        $porDoc = RegistroFinanciero::where('codigo_proyecto', 'OB4501')
            ->whereNotNull('documento')
            ->selectRaw('documento, COUNT(*) as n, SUM(estado_er) as saldo')
            ->groupBy('documento')->pluck('n', 'documento');

        $this->assertSame(2, (int) $porDoc['CCC-00000180']);
        $this->assertSame(1, (int) $porDoc['FSP-00002775']);
    }
}
