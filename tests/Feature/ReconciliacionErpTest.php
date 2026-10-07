<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconciliacionErpTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function rf(string $cod, float $er, int $mes, int $anio): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => '14350105', 'cuenta_mayor' => 'Costos por aplicar',
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio,
        ]);
    }

    /** ERP con columna A vacía, fila de título, encabezados, detalle, subtotal y gran total. */
    private function erp(): UploadedFile
    {
        $data = [
            ['', 'Auxiliar de cuenta contable 14', '', '', '', '', ''],
            ['', 'U.N.', 'Débitos', 'Créditos', 'Neto', 'Fecha', 'Nit movto.'],
            ['', '8378', 600000, 0, 600000, '2026-05-31', '900123'],
            ['', '8378', 400000, 0, 400000, '2026-06-30', '900123'],
            ['', '8657', 300000, 0, 300000, '2026-05-31', '900123'],
            ['', '8657', 500000, 0, 500000, '2026-06-30', '900123'],
            ['', '8657', 0, 0, 800000, '', ''],            // subtotal (sin fecha/nit) → se ignora
            ['', 'Gran total', 0, 0, 1800000, '', ''],       // gran total → se ignora
        ];
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($data, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'erp').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'auxiliar14.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function compara_erp_vs_sistema_y_marca_las_que_no_cuadran(): void
    {
        FichaProyecto::create(['codigo_proyecto' => '8657', 'nombre_obra' => 'Obra 8657']);
        // Sistema (estado_er negativo = pendiente; convención ERP = −suma).
        $this->rf('8378', -600000, 5, 2026);
        $this->rf('8378', -400000, 6, 2026);     // 8378: sistema = +1.000.000 = ERP → cuadra
        $this->rf('8657', -300000, 5, 2026);     // 8657: falta JUNIO → no cuadra

        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.recon14.store'), ['archivo' => $this->erp()])
            ->assertRedirect()->assertSessionHas('success');

        $recon = $this->actingAs($c)->get(route('contable.recon14.index'))->viewData('recon');
        $this->assertNotNull($recon);

        $filas = collect($recon['filas'])->keyBy('codigo');

        // 8378 cuadra.
        $this->assertEqualsWithDelta(1000000, $filas['8378']['saldo_erp'], 1);
        $this->assertEqualsWithDelta(1000000, $filas['8378']['saldo_sistema'], 1);
        $this->assertEqualsWithDelta(0, $filas['8378']['diferencia'], 1);
        $this->assertTrue($filas['8378']['cuadra']);

        // 8657 NO cuadra (falta junio: ERP 800k vs sistema 300k → dif 500k).
        $this->assertEqualsWithDelta(800000, $filas['8657']['saldo_erp'], 1);
        $this->assertEqualsWithDelta(300000, $filas['8657']['saldo_sistema'], 1);
        $this->assertEqualsWithDelta(500000, $filas['8657']['diferencia'], 1);
        $this->assertFalse($filas['8657']['cuadra']);

        // Drill-down de 8657: junio resaltado (ERP 500k, sistema 0); mayo ok.
        $mes = collect($recon['mes']['8657'])->keyBy('mes');
        $this->assertTrue($mes['2026-06']['resaltar']);
        $this->assertEqualsWithDelta(500000, $mes['2026-06']['erp'], 1);
        $this->assertEqualsWithDelta(0, $mes['2026-06']['sistema'], 1);
        $this->assertFalse($mes['2026-05']['resaltar']);
    }

    #[Test]
    public function ignora_gran_total_y_subtotales(): void
    {
        $this->rf('8378', -1000000, 5, 2026);

        $c = $this->contable();
        $resp = $this->actingAs($c)->post(route('contable.recon14.store'), ['archivo' => $this->erp()]);
        $resp->assertRedirect();

        // 4 filas de detalle (no cuenta subtotal ni gran total).
        $this->assertStringContainsString('4 movimientos de detalle', session('success'));

        $recon = $this->actingAs($c)->get(route('contable.recon14.index'))->viewData('recon');
        $codigos = collect($recon['filas'])->pluck('codigo')->all();
        $this->assertNotContains('Gran total', $codigos);
        $this->assertNotContains('GRANTOTAL', $codigos);
    }

    #[Test]
    public function descarga_el_excel_de_la_comparacion(): void
    {
        $this->rf('8378', -1000000, 5, 2026);
        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.recon14.store'), ['archivo' => $this->erp()])->assertRedirect();

        Excel::fake();
        $this->actingAs($c)->get(route('contable.recon14.excel'))->assertOk();
        Excel::assertDownloaded('Reconciliacion_cuenta_14_'.date('Ymd').'.xlsx');
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_ver(): void
    {
        $ajeno = User::factory()->create(['rol' => 'op', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver']]);
        $this->actingAs($ajeno)->get(route('contable.recon14.index'))->assertForbidden();
    }
}
