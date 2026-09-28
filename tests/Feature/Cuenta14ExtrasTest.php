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

class Cuenta14ExtrasTest extends TestCase
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

    /** [UN,nombreUN,cuenta,aux,deb,cred,movto,periodo,tercero,nombreTercero,terceroDocto,razonDocto,docto] */
    private function fila(string $cuenta, string $docto, float $deb, string $tercero = '900'): array
    {
        return ['OB4501', 'OBRA UNO', $cuenta, 'AUX', $deb, 0, $deb, '202408', $tercero, 'PROV', '890319324', 'SECAR', $docto];
    }

    // ─────────── Item 1: Débito/Crédito/Saldo en el reporte SECAR ───────────

    #[Test]
    public function el_reporte_secar_muestra_debito_credito_y_saldo_de_la_cuenta_14(): void
    {
        // Dos movimientos de la misma obra: un débito y un crédito.
        RegistroFinanciero::create(['codigo_proyecto' => 'OB1', 'nombre_proyecto' => 'Obra 1', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'razon_social' => 'PROVEEDOR', 'valor_debito' => 3000000, 'valor_credito' => 0,
            'estado_er' => -3000000, 'mes' => 8, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => 'OB1', 'nombre_proyecto' => 'Obra 1', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'razon_social' => 'PROVEEDOR', 'valor_debito' => 0, 'valor_credito' => 1000000,
            'estado_er' => 1000000, 'mes' => 8, 'anio' => 2026]);

        $fila = collect($this->actingAs($this->contable())->get(route('contable.cruce-secar.index'))->viewData('filas'))
            ->firstWhere('codigo', 'OB1');

        $this->assertEqualsWithDelta(3000000, $fila['debito'], 1);
        $this->assertEqualsWithDelta(1000000, $fila['credito'], 1);
        $this->assertEqualsWithDelta(2000000, $fila['saldo'], 1);   // débito − crédito
    }

    // ─────────── El importador carga TODAS las filas, fiel 1:1 ───────────

    #[Test]
    public function el_importador_carga_todas_las_filas_sin_descartar_por_huella(): void
    {
        $archivo = $this->biable([
            $this->fila('14200105', 'CCC-1', 1000),   // A
            $this->fila('14200105', 'CCC-1', 1000),   // A repetida EXACTA → antes se botaba; ahora entra
            $this->fila('14200105', 'CCC-2', 1000),   // mismo valor, otro documento
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2024])
            ->assertRedirect()->assertSessionHas('success');

        // Las 3 filas quedan: no se descarta ninguna en silencio al cargar.
        $this->assertSame(3, RegistroFinanciero::where('mes', 8)->where('anio', 2024)->count());
        $this->assertSame(2, RegistroFinanciero::where('documento', 'CCC-1')->count());
        $this->assertSame(1, RegistroFinanciero::where('documento', 'CCC-2')->count());
        // Ya no se anuncia "duplicadas omitidas": nada se omite.
        $this->assertStringNotContainsString('omitidas', session('success'));
    }

    // ─────────── COM00099 es una obra real con saldo: debe entrar ───────────

    #[Test]
    public function el_importador_incluye_la_obra_com00099(): void
    {
        // Antes se botaba COM00099 en el importador, lo que descuadraba el cierre (p. ej. el $37.000).
        $archivo = $this->biable([
            ['COM00099', 'COMERCIAL', '14200105', 'AUX', 37000, 0, 37000, '202408', '900', 'PROV', '890', 'SECAR', 'FAC-1'],
        ]);

        $this->actingAs($this->contable())
            ->post(route('contable.carga.store'), ['archivo' => $archivo, 'mes' => 8, 'anio' => 2024])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, RegistroFinanciero::where('codigo_proyecto', 'COM00099')->count());
    }

    // ─────────── Item 3: reporte "Posibles duplicados" ───────────

    #[Test]
    public function el_reporte_de_duplicados_agrupa_los_repetidos(): void
    {
        // Dos filas idénticas (mismo grupo) + una distinta por documento.
        $base = ['codigo_proyecto' => 'OB9', 'nombre_proyecto' => 'Obra 9', 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '900', 'razon_social' => 'PROV',
            'documento' => 'CCC-1', 'valor_debito' => 5000, 'valor_credito' => 0, 'estado_er' => -5000,
            'periodo' => '202408', 'mes' => 8, 'anio' => 2024];
        RegistroFinanciero::create($base);
        RegistroFinanciero::create($base);                                   // repetida
        RegistroFinanciero::create(array_merge($base, ['documento' => 'CCC-2'])); // distinta → no agrupa

        $grupos = $this->actingAs($this->contable())->get(route('contable.duplicados.index'))->viewData('grupos');

        $this->assertCount(1, $grupos);                     // solo el grupo repetido
        $this->assertSame(2, (int) $grupos[0]->repeticiones);
        $this->assertSame('CCC-1', $grupos[0]->documento);
        $this->assertNotEmpty($grupos[0]->ids);             // trae los IDs
    }
}
