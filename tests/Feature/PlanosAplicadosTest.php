<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\Homologacion;
use App\Models\ObraEstado;
use App\Models\PlanoAplicado;
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
 * Aplicación de planos (reverso y distribución) en la cuenta 14 del sistema: crea movimientos
 * equivalentes en registro_financieros (origen propio), es idempotente, no lo borra la recarga
 * BIABLE, se puede deshacer y se refleja en la reconciliación.
 */
class PlanosAplicadosTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'editar'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function cerrar(string $cod): void
    {
        ProyectoCerrado::create(['codigo_proyecto' => $cod, 'tipo_cierre' => 'total',
            'user_id' => User::factory()->create()->id]);
    }

    private function homolog(string $c14, string $c61): void
    {
        Homologacion::create(['cuenta_14' => $c14, 'cuenta_61' => $c61, 'nombre' => 'Homol']);
    }

    private function rf(string $cod, string $cuenta, float $er, int $mes, int $anio, string $origen = 'biable'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => $er, 'valor_debito' => 0,
            'valor_credito' => 0, 'mes' => $mes, 'anio' => $anio, 'origen' => $origen,
        ]);
    }

    /** Saldo del sistema por obra (SUM estado_er) en la cuenta 14. */
    private function saldo(string $cod): float
    {
        return (float) RegistroFinanciero::where('codigo_proyecto', $cod)
            ->where('cuenta_mayor', 'Costos por aplicar')->sum('estado_er');
    }

    /** Archivo BIABLE mínimo (una fila) para probar la recarga por mes. */
    private function biable(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            'Unidad de Negocio', 'Nombre Unidad de Negocio', 'Cuenta', 'Nombre Auxiliar',
            'Debitos', 'Creditos', 'Movto Libro2', 'Periodo', 'Tercero', 'Nombre Tercero',
            'Tercero Docto', 'Razon Social Docto', 'Docto.',
        ], null, 'A1');
        $r = 2;
        foreach ($filas as $f) { $ss->getActiveSheet()->fromArray($f, null, 'A'.$r); $r++; }
        $path = tempnam(sys_get_temp_dir(), 'biable').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'cierre.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // ─────────── Reverso: aplicar en el sistema ───────────

    #[Test]
    public function aplicar_reverso_crea_movimientos_de_cuenta_14_y_baja_el_saldo(): void
    {
        $this->cerrar('GM1');
        $this->homolog('14200105', '61300105');
        $this->rf('GM1', '14200105', -300000, 6, 2026);   // pendiente que el ERP revertirá

        $this->assertEqualsWithDelta(-300000, $this->saldo('GM1'), 1);

        $this->actingAs($this->contable())
            ->post(route('contable.plano-reversion.aplicar'), [
                'corte_mes' => 6, 'corte_anio' => 2026, 'documento' => 5,
                'destino_mes' => 7, 'destino_anio' => 2026,
            ])
            ->assertRedirect()->assertSessionHas('success');

        $plano = PlanoAplicado::sole();
        $this->assertSame('reverso', $plano->tipo);
        $this->assertSame(7, $plano->mes);
        $this->assertSame(2026, $plano->anio);
        $this->assertSame(2, $plano->n_lineas);                       // partida doble: pata 14 + pata 6
        $this->assertEqualsWithDelta(300000, $plano->total_credito, 1);
        $this->assertEqualsWithDelta(300000, $plano->total_debito, 1); // cuadra

        // Pata de cuenta 14 (crédito, baja el pendiente).
        $g14 = RegistroFinanciero::where('origen', 'reverso_plano')->where('cuenta_contable', '14200105')->sole();
        $this->assertSame('Costos por aplicar', $g14->cuenta_mayor);
        $this->assertEqualsWithDelta(300000, $g14->valor_credito, 1);
        $this->assertEqualsWithDelta(300000, $g14->estado_er, 1);
        $this->assertSame($plano->id, $g14->plano_aplicado_id);

        // Pata de cuenta 6 (contrapartida, costo aplicado, débito).
        $g6 = RegistroFinanciero::where('origen', 'reverso_plano')->where('cuenta_contable', '61300105')->sole();
        $this->assertSame('Costos aplicados', $g6->cuenta_mayor);
        $this->assertEqualsWithDelta(300000, $g6->valor_debito, 1);
        $this->assertEqualsWithDelta(-300000, $g6->estado_er, 1);

        // El saldo de la cuenta 14 de la obra ya quedó en cero (el pendiente se reflejó).
        $this->assertEqualsWithDelta(0, $this->saldo('GM1'), 1);
    }

    #[Test]
    public function reaplicar_el_mismo_reverso_reemplaza_no_acumula(): void
    {
        $this->cerrar('GM1');
        $this->homolog('14200105', '61300105');
        $this->rf('GM1', '14200105', -300000, 6, 2026);
        $c = $this->contable();

        $datos = ['corte_mes' => 6, 'corte_anio' => 2026, 'documento' => 5, 'destino_mes' => 7, 'destino_anio' => 2026];
        $this->actingAs($c)->post(route('contable.plano-reversion.aplicar'), $datos)->assertRedirect();
        $this->actingAs($c)->post(route('contable.plano-reversion.aplicar'), $datos)->assertRedirect();

        $this->assertSame(1, PlanoAplicado::count());                                 // no se duplica
        $this->assertSame(2, RegistroFinanciero::where('origen', 'reverso_plano')->count()); // 2 patas, no 4
    }

    #[Test]
    public function la_recarga_biable_del_mes_no_borra_los_movimientos_generados(): void
    {
        $this->cerrar('GM1');
        $this->homolog('14200105', '61300105');
        $this->rf('GM1', '14200105', -300000, 7, 2026);   // pendiente en el mismo mes destino
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.plano-reversion.aplicar'), [
            'corte_mes' => 7, 'corte_anio' => 2026, 'documento' => 5, 'destino_mes' => 7, 'destino_anio' => 2026,
        ])->assertRedirect();

        $this->assertSame(2, RegistroFinanciero::where('origen', 'reverso_plano')->count());

        // Recarga BIABLE del período 7/2026 (reemplaza SOLO lo biable).
        $this->actingAs($c)->post(route('contable.carga.store'), [
            'archivo' => $this->biable([['OT9', 'OTRA', '14200105', 'AUX', 50000, 0, 50000, '202607', '900', 'P', '890', 'SECAR', 'F-1']]),
            'mes' => 7, 'anio' => 2026,
        ])->assertRedirect();

        // Los movimientos generados sobreviven; la fila biable nueva entró.
        $this->assertSame(2, RegistroFinanciero::where('origen', 'reverso_plano')->where('anio', 2026)->count());
        $this->assertSame(1, RegistroFinanciero::where('origen', 'biable')->where('codigo_proyecto', 'OT9')->count());
    }

    #[Test]
    public function deshacer_elimina_los_movimientos_y_devuelve_el_saldo(): void
    {
        $this->cerrar('GM1');
        $this->homolog('14200105', '61300105');
        $this->rf('GM1', '14200105', -300000, 6, 2026);
        $c = $this->contable();

        $this->actingAs($c)->post(route('contable.plano-reversion.aplicar'), [
            'corte_mes' => 6, 'corte_anio' => 2026, 'documento' => 5, 'destino_mes' => 7, 'destino_anio' => 2026,
        ])->assertRedirect();

        $plano = PlanoAplicado::sole();

        $this->actingAs($c)->post(route('contable.planos-aplicados.deshacer', $plano->id))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(0, PlanoAplicado::count());
        $this->assertSame(0, RegistroFinanciero::where('origen', 'reverso_plano')->count());
        $this->assertEqualsWithDelta(-300000, $this->saldo('GM1'), 1);   // volvió a como estaba
    }

    // ─────────── Distribución: aplicar en el sistema ───────────

    #[Test]
    public function aplicar_distribucion_crea_la_partida_doble_14_y_6(): void
    {
        $uid = $this->contable()->id;
        ObraEstado::create(['codigo_proyecto' => 'OB5', 'estado' => 'cerrada', 'user_id' => $uid]);
        $d = Distribucion::create(['mes' => 6, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'enviado', 'reemplazada' => false, 'edicion_habilitada' => false]);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 6, 'anio' => 2026, 'codigo_proyecto' => 'OB5',
            'cuenta_14' => '14200105', 'cuenta_61' => '61350105', 'categoria' => 'MAT', 'nombre' => 'Costo',
            'monto_aplicar' => 200000, 'es_provision' => false, 'estado' => 'ok', 'user_id' => $uid]);

        $this->actingAs($this->contable())
            ->post(route('contable.plano-contable.aplicar', $d->id), [
                'documento' => 10, 'destino_mes' => 6, 'destino_anio' => 2026,
            ])
            ->assertRedirect()->assertSessionHas('success');

        $plano = PlanoAplicado::sole();
        $this->assertSame('distribucion', $plano->tipo);
        $this->assertSame($d->id, $plano->distribucion_id);
        $this->assertSame(2, $plano->n_lineas);                       // partida doble: CR 14 + DB 6

        // Pata 14: crédito que baja el pendiente.
        $g14 = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '14200105')->sole();
        $this->assertEqualsWithDelta(200000, $g14->valor_credito, 1);
        $this->assertEqualsWithDelta(200000, $g14->estado_er, 1);
        $this->assertSame('Costos por aplicar', $g14->cuenta_mayor);

        // Pata 6: débito del costo aplicado.
        $g6 = RegistroFinanciero::where('origen', 'distribucion_plano')->where('cuenta_contable', '61350105')->sole();
        $this->assertEqualsWithDelta(200000, $g6->valor_debito, 1);
        $this->assertEqualsWithDelta(-200000, $g6->estado_er, 1);
        $this->assertSame('Costos aplicados', $g6->cuenta_mayor);
    }

    #[Test]
    public function reaplicar_la_misma_distribucion_reemplaza_sus_movimientos(): void
    {
        $uid = $this->contable()->id;
        ObraEstado::create(['codigo_proyecto' => 'OB5', 'estado' => 'cerrada', 'user_id' => $uid]);
        $d = Distribucion::create(['mes' => 6, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'enviado', 'reemplazada' => false, 'edicion_habilitada' => false]);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 6, 'anio' => 2026, 'codigo_proyecto' => 'OB5',
            'cuenta_14' => '14200105', 'cuenta_61' => '61350105', 'categoria' => 'MAT', 'nombre' => 'Costo',
            'monto_aplicar' => 200000, 'es_provision' => false, 'estado' => 'ok', 'user_id' => $uid]);
        $c = $this->contable();

        $datos = ['documento' => 10, 'destino_mes' => 6, 'destino_anio' => 2026];
        $this->actingAs($c)->post(route('contable.plano-contable.aplicar', $d->id), $datos)->assertRedirect();
        $this->actingAs($c)->post(route('contable.plano-contable.aplicar', $d->id), $datos)->assertRedirect();

        $this->assertSame(1, PlanoAplicado::count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'distribucion_plano')->count());
    }

    // ─────────── Reconciliación: desglose biable vs generado ───────────

    #[Test]
    public function la_reconciliacion_muestra_la_parte_generada(): void
    {
        $this->rf('8378', '14350105', -500000, 5, 2026, 'biable');           // pendiente biable
        $this->rf('8378', '14350105', 200000, 6, 2026, 'reverso_plano');     // reversión aplicada por el sistema

        $data = [
            ['', 'Auxiliar 14', '', '', '', '', ''],
            ['', 'U.N.', 'Débitos', 'Créditos', 'Neto', 'Fecha', 'Nit movto.'],
            ['', '8378', 300000, 0, 300000, '2026-05-31', '900123'],   // ERP = 300k
        ];
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($data, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'erp').'.xlsx';
        (new Xlsx($ss))->save($path);
        $erp = new UploadedFile($path, 'aux.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $c = $this->contable();
        $this->actingAs($c)->post(route('contable.recon14.store'), ['archivo' => $erp])->assertRedirect();

        $fila = collect($this->actingAs($c)->get(route('contable.recon14.index'))->viewData('recon')['filas'])
            ->firstWhere('codigo', '8378');

        // sistema = biable + generado; convención ERP = −SUM(estado_er).
        $this->assertEqualsWithDelta(300000, $fila['saldo_sistema'], 1);   // −(−500000+200000)
        $this->assertEqualsWithDelta(-200000, $fila['saldo_generado'], 1); // −(+200000)
        $this->assertEqualsWithDelta(500000, $fila['saldo_biable'], 1);    // el resto
        $this->assertTrue($fila['cuadra']);                                // ERP 300k = sistema 300k
    }

    // ─────────── Permisos ───────────

    #[Test]
    public function un_usuario_solo_lectura_no_puede_aplicar(): void
    {
        $this->cerrar('GM1');
        $this->rf('GM1', '14200105', -300000, 6, 2026);

        $this->actingAs($this->contable('ver'))
            ->post(route('contable.plano-reversion.aplicar'), [
                'corte_mes' => 6, 'corte_anio' => 2026, 'documento' => 5, 'destino_mes' => 7, 'destino_anio' => 2026,
            ])
            ->assertForbidden();

        $this->assertSame(0, PlanoAplicado::count());
    }
}
