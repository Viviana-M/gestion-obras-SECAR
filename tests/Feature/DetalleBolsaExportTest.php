<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El "Exportar Excel" del detalle de Otros Costos descarga un archivo FORMATEADO y listo para
 * imprimir: encabezado azul SECAR, extracción, totales, pie de página, orientación horizontal,
 * ajuste al ancho, fila de encabezado repetida y encabezado congelado.
 */
class DetalleBolsaExportTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create([
            'name' => 'Ana Pérez', 'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'ver'],
        ]);
    }

    private function seedBolsa(): void
    {
        // Bolsa MTO00099 (mantenimiento): una línea positiva (distribuible) y una negativa (reversado).
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200530',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '111', 'razon_social' => 'MO SAS',
            'tipo_documento' => 'FSE', 'documento' => '1', 'fecha_docto' => '2026-06-10',
            'estado_er' => -1000000, 'valor_debito' => 1000000, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026,
        ]);
        RegistroFinanciero::create([
            'codigo_proyecto' => 'MTO00099', 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => '14200599',
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => '999', 'razon_social' => 'X SAS',
            'tipo_documento' => 'NC', 'documento' => '9', 'fecha_docto' => '2026-09-30',
            'estado_er' => 500000, 'valor_debito' => 0, 'valor_credito' => 500000, 'mes' => 7, 'anio' => 2026,
        ]);
    }

    #[Test]
    public function descarga_el_detalle_con_titulo_subtitulo_y_totales(): void
    {
        Excel::fake();
        $this->seedBolsa();

        $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.detalle-bolsa.excel',
                ['mes' => 7, 'anio' => 2026, 'vista' => 'todo', 'departamento' => 'mantenimiento']))
            ->assertOk();

        Excel::assertDownloaded('Detalle_OtrosCostos_mantenimiento_7_2026.xlsx', function ($export) {
            $plano = json_encode($export->array(), JSON_UNESCAPED_UNICODE);

            foreach (['Detalle de Otros Costos por Cuenta', 'Saldos en Libro 1 (PCGA)', 'Mantenimiento',
                      'Líneas con saldo', 'Razón social tercero', '14200530', 'TOTALES'] as $txt) {
                if (! str_contains($plano, $txt)) {
                    return false;
                }
            }

            return true;
        });
    }

    #[Test]
    public function el_archivo_viene_formateado_y_listo_para_imprimir(): void
    {
        $this->seedBolsa();

        $this->actingAs($this->operador())
            ->get(route('operativo.distribucion.detalle-bolsa.excel',
                ['mes' => 7, 'anio' => 2026, 'vista' => 'todo', 'departamento' => 'mantenimiento']))
            ->assertOk();

        // Se renderiza el xlsx real a disco y se verifica el formato con PhpSpreadsheet.
        $rel = 'pruebas/detalle_export_'.uniqid().'.xlsx';
        Excel::store($this->exportDe(), $rel, 'local');
        $path = storage_path('app/'.$rel);
        if (! file_exists($path)) {
            $path = storage_path('app/private/'.$rel); // Laravel 11+ local root
        }
        $sheet = IOFactory::load($path)->getActiveSheet();

        // 1) Encabezado con color azul SECAR, título y subtítulo.
        $this->assertSame('Detalle de Otros Costos por Cuenta', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Saldos en Libro 1 (PCGA)', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('1B3F6E', $sheet->getStyle('A5')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFFFFF', $sheet->getStyle('A5')->getFont()->getColor()->getRGB());
        $this->assertTrue($sheet->getStyle('A5')->getFont()->getBold());

        // 2) Extracción (fecha/hora) y líneas con saldo.
        $this->assertStringContainsString('Extraído:', (string) $sheet->getCell('A3')->getValue());
        $this->assertStringContainsString('Líneas con saldo: 2', (string) $sheet->getCell('A3')->getValue());

        // 3) Encabezado de columnas en la fila 5, datos desde la 6.
        $this->assertSame('U.N.', $sheet->getCell('A5')->getValue());
        $this->assertSame('MTO00099', $sheet->getCell('A6')->getValue());
        $this->assertStringContainsString(',', $sheet->getCell('J6')->getFormattedValue()); // separador de miles

        // 4) Fila de totales (TOTALES + saldo neto 500.000).
        $this->assertSame('TOTALES', $sheet->getCell('A8')->getValue());
        $this->assertStringContainsString('500,000', $sheet->getCell('J8')->getFormattedValue());

        // 5) Pie de impresión: usuario + fecha/hora (izq) y "Página X de Y" (der).
        $footer = $sheet->getHeaderFooter()->getOddFooter();
        $this->assertStringContainsString('Extraído por: Ana Pérez', $footer);
        $this->assertStringContainsString('Página &P de &N', $footer);

        // 6) Impresión: horizontal, ajuste al ancho, fila de encabezado repetida; encabezado congelado.
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        $this->assertSame([5, 5], array_map('intval', $sheet->getPageSetup()->getRowsToRepeatAtTop()));
        $this->assertSame('A6', $sheet->getFreezePane());

        @unlink($path);
    }

    /** Reconstruye el export que arma el controlador, para el render real del test. */
    private function exportDe(): \App\Exports\DetalleBolsaExport
    {
        $svc = app(\App\Services\DistribucionService::class);
        $periodo = \App\Models\Homologacion::periodo(2026, 7);
        $det = $svc->detalleBolsaTerceros(['MTO00099'], $periodo, 2026, 7, 'todo');

        $filas = []; $sSaldo = 0; $sDist = 0; $sQueda = 0; $conSaldo = 0;
        foreach ($det as $lineas) {
            foreach ($lineas as $l) {
                if ($l['cero']) continue;
                $queda = round($l['saldo'] - $l['monto_distribuir'], 2);
                $filas[] = [$l['un_codigo'], $l['fecha'] ?? '', $l['un_nombre'], $l['nombre_proyecto'], $l['cuenta_14'],
                    $l['nombre'], $l['tercero_dcto'], $l['razon_social'], $l['documento'],
                    round($l['saldo'], 2), round($l['monto_distribuir'], 2), $queda, $l['observaciones']];
                $sSaldo += $l['saldo']; $sDist += $l['monto_distribuir']; $sQueda += $queda;
                if (round((float) $l['saldo']) != 0) $conSaldo++;
            }
        }

        return new \App\Exports\DetalleBolsaExport(
            titulo: 'Detalle de Otros Costos por Cuenta',
            subtitulo: 'Bolsa: Mantenimiento   ·   Saldos en Libro 1 (PCGA)',
            headers: ['U.N.', 'Fecha', 'Desc. U.N.', 'Nombre del proyecto', 'Auxiliar', 'Desc. auxiliar',
                'Nit movto.', 'Razón social tercero', 'Docto.', 'Saldo', 'A distribuir', 'Queda mes sig.', 'Observaciones'],
            filas: $filas,
            totales: ['saldo' => $sSaldo, 'distribuir' => $sDist, 'queda' => $sQueda],
            lineasConSaldo: $conSaldo,
            usuario: 'Ana Pérez',
            extraidoEn: now(),
        );
    }
}
