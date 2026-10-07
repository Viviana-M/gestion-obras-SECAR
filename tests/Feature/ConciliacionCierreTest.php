<?php

namespace Tests\Feature;

use App\Models\CierreConciliacion;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Conciliación y cierre de cuenta 14 (y 6): tabla por obra con desglose por origen, cruce contra
 * ERP de 14 y 6, cierre del período con bloqueo de BIABLE y planos, y reapertura.
 */
class ConciliacionCierreTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function rf(string $cod, string $mayor, float $er, string $origen = 'biable', string $cuenta = '14200105', int $mes = 5, int $anio = 2026): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => $mayor, 'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0,
            'mes' => $mes, 'anio' => $anio, 'origen' => $origen,
        ]);
    }

    /** Auxiliar ERP: filas [codigo, neto]. */
    private function erpFile(array $filas): UploadedFile
    {
        $data = [['', 'U.N.', 'Débitos', 'Créditos', 'Neto', 'Fecha', 'Nit movto.']];
        foreach ($filas as [$cod, $neto]) {
            $data[] = ['', $cod, $neto > 0 ? $neto : 0, $neto < 0 ? -$neto : 0, $neto, '2026-05-31', '900123'];
        }
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($data, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'erp').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'aux.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function biableFile(): UploadedFile
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta', 'Nombre Auxiliar',
                'Debitos', 'Creditos', 'Movto Libro2', 'Periodo', 'Tercero', 'Nombre Tercero', 'Tercero Docto', 'Razon Social Docto', 'Docto.'],
            ['8378', 'Obra', '14200105', 'AUX', 100000, 0, 100000, '202605', '900', 'P', '890', 'SECAR', 'F-1'],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // ─────────── Tabla del sistema (14 + 6 + desglose por origen) ───────────

    #[Test]
    public function la_tabla_muestra_14_y_6_con_desglose_por_origen(): void
    {
        $this->rf('8378', 'Costos por aplicar', -500000, 'biable');            // pendiente biable
        $this->rf('8378', 'Costos por aplicar', 200000, 'reverso_plano');      // reversión aplicada
        $this->rf('8378', 'Costos aplicados', -300000, 'biable', '61300105');  // costo aplicado

        $fila = collect($this->actingAs($this->contable('ver'))
            ->get(route('contable.conciliacion-cierre.index', ['mes' => 5, 'anio' => 2026]))->viewData('filas'))
            ->firstWhere('codigo', '8378');

        $this->assertEqualsWithDelta(300000, $fila['sis14'], 1);        // −(−500000+200000)
        $this->assertEqualsWithDelta(500000, $fila['biable14'], 1);
        $this->assertEqualsWithDelta(-200000, $fila['reverso14'], 1);
        $this->assertEqualsWithDelta(300000, $fila['sis6'], 1);        // −(−300000)
    }

    // ─────────── Cruce contra ERP (14 y 6) ───────────

    #[Test]
    public function marca_ok_cuando_el_sistema_coincide_con_el_erp(): void
    {
        $this->rf('8378', 'Costos por aplicar', -500000);
        $this->rf('8378', 'Costos aplicados', -300000, 'biable', '61300105');
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.erp'), [
            'archivo_14' => $this->erpFile([['8378', 500000]]),
            'archivo_6'  => $this->erpFile([['8378', 300000]]),
        ])->assertRedirect()->assertSessionHas('success');

        $fila = collect($this->actingAs($c)
            ->get(route('contable.conciliacion-cierre.index', ['mes' => 5, 'anio' => 2026]))->viewData('filas'))
            ->firstWhere('codigo', '8378');

        $this->assertEqualsWithDelta(0, $fila['dif14'], 1);
        $this->assertEqualsWithDelta(0, $fila['dif6'], 1);
        $this->assertTrue($fila['ok']);
    }

    #[Test]
    public function marca_revisar_cuando_no_coincide(): void
    {
        $this->rf('8378', 'Costos por aplicar', -500000);
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.erp'), [
            'archivo_14' => $this->erpFile([['8378', 450000]]),   // 50k de diferencia
        ])->assertRedirect();

        $fila = collect($this->actingAs($c)
            ->get(route('contable.conciliacion-cierre.index', ['mes' => 5, 'anio' => 2026]))->viewData('filas'))
            ->firstWhere('codigo', '8378');

        $this->assertEqualsWithDelta(-50000, $fila['dif14'], 1);
        $this->assertFalse($fila['ok']);
    }

    // ─────────── Cerrar / bloquear / reabrir ───────────

    #[Test]
    public function cerrar_periodo_lo_bloquea_para_biable_y_planos(): void
    {
        $this->rf('8378', 'Costos por aplicar', -500000);
        $this->rf('8378', 'Costos aplicados', -300000, 'biable', '61300105');
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.erp'), [
            'archivo_14' => $this->erpFile([['8378', 500000]]),
            'archivo_6'  => $this->erpFile([['8378', 300000]]),
        ])->assertRedirect();

        // Cierra el período (cuadra).
        $this->actingAs($c)->post(route('contable.conciliacion-cierre.cerrar'), ['mes' => 5, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(CierreConciliacion::estaCerrado(5, 2026));

        // Recargar BIABLE de 5/2026 queda bloqueado.
        $this->actingAs($c)->post(route('contable.carga.store'), ['archivo' => $this->biableFile(), 'mes' => 5, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, RegistroFinanciero::where('codigo_proyecto', '8378')->where('documento', 'F-1')->count());

        // Aplicar un plano con destino 5/2026 queda bloqueado.
        ProyectoCerrado::create(['codigo_proyecto' => 'GM1', 'tipo_cierre' => 'total', 'user_id' => $c->id]);
        $this->rf('GM1', 'Costos por aplicar', -100000);
        $this->actingAs($c)->post(route('contable.plano-reversion.aplicar'), [
            'corte_mes' => 5, 'corte_anio' => 2026, 'documento' => 1, 'destino_mes' => 5, 'destino_anio' => 2026,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, \App\Models\PlanoAplicado::count());
    }

    #[Test]
    public function no_deja_cerrar_si_no_cuadra(): void
    {
        $this->rf('8378', 'Costos por aplicar', -500000);
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.erp'), [
            'archivo_14' => $this->erpFile([['8378', 450000]]),   // no cuadra
        ])->assertRedirect();

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.cerrar'), ['mes' => 5, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(CierreConciliacion::estaCerrado(5, 2026));
    }

    #[Test]
    public function reabrir_desbloquea_el_periodo(): void
    {
        $c = $this->contable();
        $cierre = CierreConciliacion::create(['mes' => 5, 'anio' => 2026, 'user_id' => $c->id, 'cerrado_at' => now()]);

        $this->actingAs($c)->post(route('contable.conciliacion-cierre.reabrir', $cierre->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertFalse(CierreConciliacion::estaCerrado(5, 2026));
    }

    #[Test]
    public function un_usuario_solo_lectura_no_puede_cerrar(): void
    {
        $this->actingAs($this->contable('ver'))
            ->post(route('contable.conciliacion-cierre.cerrar'), ['mes' => 5, 'anio' => 2026])
            ->assertForbidden();
    }
}
