<?php

namespace Tests\Feature;

use App\Models\CargaFinanciera;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Support\Lotes\ImportadorCierre;
use App\Support\Lotes\MotorLotes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cargue de cierre (BIABLE) con varios meses:
 *   1) Cargar un mes NO borra los demás (cada borrado está acotado a su mes/año).
 *   2) Candado: si el archivo es de OTRO período (columna 'periodo'), se rechaza ANTES de borrar,
 *      para no sobrescribir un mes con los datos de otro por un selector mal puesto.
 */
class CargaCierreMultiMesTest extends TestCase
{
    use RefreshDatabase;

    /** Genera un xlsx BIABLE mínimo (encabezados reconocidos + filas) bajo storage/app. */
    private function archivoBiable(string $rel, string $codigo, string $periodo): string
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta Contable', 'Descripcion Cuenta',
             'Valor Debito', 'Valor Credito', 'Docto', 'Tercero Docto', 'Razon Social Docto', 'Periodo'],
            [$codigo, 'Obra '.$codigo, '61350100', 'Costo',   1000000, 0, '100', '900', 'Proveedor', $periodo],
            [$codigo, 'Obra '.$codigo, '41350100', 'Ingreso', 0, 2000000, '101', '901', 'Cliente',   $periodo],
        ], null, 'A1');

        $abs = storage_path('app/'.$rel);
        @mkdir(dirname($abs), 0777, true);
        (new XlsxWriter($ss))->save($abs);

        return $rel;
    }

    /** Corre la carga completa de un período, igual que CargaFinancieraController::store(). */
    private function cargar(string $rel, int $mes, int $anio): void
    {
        $carga = CargaFinanciera::create([
            'mes' => $mes, 'anio' => $anio, 'archivo_original' => basename($rel),
            'ruta_archivo' => $rel, 'estado' => 'procesando', 'user_id' => 1,
        ]);
        $imp   = new ImportadorCierre();
        $motor = new MotorLotes();
        $a = $motor->analizar($imp, $carga->ruta_archivo, ['mes' => $mes, 'anio' => $anio]);
        $carga->setTotalFilas($a['total']);
        $carga->setMetaLotes($a['meta']);
        $carga->save();
        $motor->correrCompleto($imp, $carga, 1000);
    }

    #[Test]
    public function cargar_octubre_no_borra_septiembre(): void
    {
        User::factory()->create(['id' => 1]);
        $sep = $this->archivoBiable('test_cierre/sep.xlsx', 'SEP-001', '202609');
        $oct = $this->archivoBiable('test_cierre/oct.xlsx', 'OCT-001', '202610');

        $this->cargar($sep, 9, 2026);
        $sepAntes = RegistroFinanciero::where('anio', 2026)->where('mes', 9)->count();
        $this->assertGreaterThan(0, $sepAntes, 'Septiembre debería tener registros tras cargarlo.');

        $this->cargar($oct, 10, 2026);

        $this->assertGreaterThan(0, RegistroFinanciero::where('anio', 2026)->where('mes', 10)->count());
        $this->assertSame($sepAntes, RegistroFinanciero::where('anio', 2026)->where('mes', 9)->count(),
            'Cargar octubre no debe alterar los registros de septiembre.');
    }

    #[Test]
    public function el_panel_de_cargue_muestra_el_control_de_costos_61_y_anticipos_14(): void
    {
        $user = User::factory()->create([
            'rol' => 'admin', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
        CargaFinanciera::create([
            'mes' => 9, 'anio' => 2026, 'archivo_original' => '2026_09.xlsx', 'ruta_archivo' => 'x.xlsx',
            'estado' => 'completado', 'registros' => 2, 'user_id' => $user->id,
        ]);
        // Costos (61): débito 1.000.000 · Costos por aplicar (1420): débito 500.000 en septiembre 2026.
        // La fila 1405 (otra cuenta 14) NO debe contar: el control es solo 1420.
        foreach ([['61350100', 1000000], ['14200105', 500000], ['14050101', 777000]] as [$cta, $deb]) {
            RegistroFinanciero::create([
                'codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra', 'cuenta_contable' => $cta,
                'cuenta_mayor' => 'x', 'estado_er' => 0, 'valor_debito' => $deb, 'valor_credito' => 0,
                'mes' => 9, 'anio' => 2026,
            ]);
        }

        $this->actingAs($user)->get(route('contable.carga'))
            ->assertOk()
            ->assertSee('Control del mes', false)
            ->assertSee('$1.000.000', false)   // Costos (61)
            ->assertSee('$500.000', false)     // Costos por aplicar (solo 1420)
            ->assertDontSee('$1.277.000', false); // 1420 + 1405: no debe aparecer (1405 queda fuera)
    }

    #[Test]
    public function un_archivo_de_otro_mes_se_rechaza_sin_borrar_el_mes_seleccionado(): void
    {
        User::factory()->create(['id' => 1]);
        $sep = $this->archivoBiable('test_cierre/sep.xlsx', 'SEP-001', '202609');
        $oct = $this->archivoBiable('test_cierre/oct.xlsx', 'OCT-001', '202610');

        // Septiembre cargado correctamente (selector = septiembre, archivo de septiembre).
        $this->cargar($sep, 9, 2026);
        $sepAntes = RegistroFinanciero::where('anio', 2026)->where('mes', 9)->count();
        $this->assertGreaterThan(0, $sepAntes);

        // Ahora, por error, se intenta cargar el archivo de OCTUBRE con el selector en SEPTIEMBRE.
        try {
            $this->cargar($oct, 9, 2026);
            $this->fail('Debió rechazar el archivo de octubre cargado como septiembre.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('período', $e->getMessage());
        }

        // Lo clave: NO se borró ni se tocó septiembre (el rechazo ocurre antes del delete).
        $this->assertSame($sepAntes, RegistroFinanciero::where('anio', 2026)->where('mes', 9)->count(),
            'El rechazo debe ocurrir ANTES de borrar: septiembre queda intacto.');
    }
}
