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
 * El importador debe traer TODA la cuenta 14, incluidos los créditos de reverso que el ERP postea
 * en subcuentas como 14552505 (contrapartida del débito en 1420xx). Antes solo se traía el débito
 * (1420 → "Costos por aplicar") y el crédito (1455 → clasificado "Activo") se botaba, con lo que la
 * bolsa quedaba inflada. Validación con SAD-00004650: tras cargar, INS00006 cuadra con el Libro 1
 * del ERP (débito − crédito).
 */
class ImportCuenta14ReversoTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** @param array<int, array{string,string,float,float,string}> $movs [un, cuenta, debito, credito, docto] */
    private function biable(array $movs): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([
            'Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta', 'Nombre Auxiliar',
            'Debitos', 'Creditos', 'Movto Libro2', 'Periodo', 'Tercero', 'Nombre Tercero',
            'Tercero Docto', 'Razon Social Docto', 'Docto.',
        ], null, 'A1');
        $r = 2;
        foreach ($movs as [$un, $cuenta, $deb, $cred, $docto]) {
            $sheet->fromArray(
                [$un, 'OBRA '.$un, $cuenta, 'AUX', $deb, $cred, $deb - $cred, '202609', '900', 'PROV', '890319324', 'SECAR', $docto],
                null, 'A'.$r
            );
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function trae_el_credito_de_reverso_en_14552505_y_la_bolsa_cuadra_con_el_erp(): void
    {
        // SAD-00004650: reverso con débito en 1420xx y crédito en 1455xx, ambos de INS00006.
        // Más una fila de activo (1105 caja) que SÍ debe seguir descartándose.
        $archivo = $this->biable([
            ['INS00006', '14200551', 1287000, 0,       'SAD-00004650'], // débito del reverso (antes: sí)
            ['INS00006', '14552505', 0,       1287000, 'SAD-00004650'], // crédito del reverso (antes: NO)
            ['INS00006', '11050501', 500000,  0,       'OTRO-1'],       // caja: activo real → se descarta
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 9, 'anio' => 2026])
            ->assertRedirect();

        // El crédito de reverso en 14552505 AHORA se importa (antes había 0 filas en toda la base).
        $this->assertSame(1, RegistroFinanciero::where('cuenta_contable', '14552505')->count());
        $this->assertSame(1, RegistroFinanciero::where('cuenta_contable', '14200551')->count());

        // Ambas quedan en la bolsa (Costos por aplicar); la caja (activo) se descarta.
        $this->assertSame(2, RegistroFinanciero::where('codigo_proyecto', 'INS00006')
            ->where('cuenta_mayor', 'Costos por aplicar')->count());
        $this->assertSame(0, RegistroFinanciero::where('cuenta_contable', '11050501')->count());

        // Libro 1: el neto de la cuenta 14 de INS00006 = débito − crédito = 0 (el reverso se cancela).
        $neto = (float) RegistroFinanciero::where('codigo_proyecto', 'INS00006')
            ->where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('SUM(valor_debito) - SUM(valor_credito) as n')->value('n');
        $this->assertEqualsWithDelta(0, $neto, 0.5);

        // estado_er es coherente con el saldo real: −SUM(estado_er) = neto Libro 1 = 0.
        $porRepartir = -1 * (float) RegistroFinanciero::where('codigo_proyecto', 'INS00006')
            ->where('cuenta_mayor', 'Costos por aplicar')->sum('estado_er');
        $this->assertEqualsWithDelta(0, $porRepartir, 0.5);
    }
}
