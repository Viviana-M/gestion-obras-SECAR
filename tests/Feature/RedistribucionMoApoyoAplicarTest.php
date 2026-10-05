<?php

namespace Tests\Feature;

use App\Imports\Financiero\MovimientoBiableImport;
use App\Models\Homologacion;
use App\Models\ManoObraDirecta;
use App\Models\PlanoAplicado;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aplicar en la cuenta 14 del sistema el plano de reverso de MO de apoyo con control explícito
 * (chulito "Afectar el sistema"), origen='ajuste_plano', idempotencia, y recargas de BIABLE que no
 * duplican ni borran los ajustes.
 */
class RedistribucionMoApoyoAplicarTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** Fila BIABLE de cuenta 14/61 (origen='biable'), para simular el cierre y las recargas. */
    private function biable(string $un, string $cuenta, string $tercero, float $deb, float $cred): void
    {
        $movto = $deb - $cred;
        $estado = (str_starts_with($cuenta, '14') || str_starts_with($cuenta, '61') || str_starts_with($cuenta, '4')) ? -$movto : $movto;
        RegistroFinanciero::create([
            'codigo_proyecto' => $un, 'nombre_proyecto' => 'Bolsa', 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => str_starts_with($cuenta, '6') ? 'Costos aplicados' : 'Costos por aplicar',
            'tercero_dcto' => $tercero, 'razon_social' => 'T '.$tercero,
            'estado_er' => $estado, 'valor_debito' => $deb, 'valor_credito' => $cred,
            'mes' => 8, 'anio' => 2026, 'origen' => 'biable', 'periodo' => '202608',
        ]);
    }

    private function base(): void
    {
        Homologacion::create(['cuenta_14' => '14200530', 'cuenta_61' => '61350105', 'nombre' => 'MO apoyo',
            'estructura' => 'MOI', 'vigente_desde' => 202001, 'vigente_hasta' => null, 'version' => 1]);
        ManoObraDirecta::create(['cedula' => '111', 'nombre' => 'Juan Perez', 'activo' => true]);
        // Salario del empleado 111 en la bolsa INS00099 (origen BIABLE), tercero = el empleado.
        $this->biable('INS00099', '14200530', '111', 500000, 0);
    }

    private function aplicar(User $u): void
    {
        $this->actingAs($u)->post(route('contable.redistribucion-mo.aplicar'),
            ['mes' => 8, 'anio' => 2026, 'afectar' => '1', 'documento_ccc' => 'CCC-77'])->assertRedirect();
    }

    private function netoIns14(): float
    {
        return (float) RegistroFinanciero::where('codigo_proyecto', 'INS00099')->where('cuenta_contable', '14200530')->sum('estado_er');
    }

    #[Test]
    public function sin_el_chulito_no_escribe_en_el_sistema(): void
    {
        $this->base();
        $this->actingAs($this->contable())->post(route('contable.redistribucion-mo.aplicar'), ['mes' => 8, 'anio' => 2026])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, RegistroFinanciero::where('origen', 'ajuste_plano')->count());
        $this->assertSame(0, PlanoAplicado::count());
    }

    #[Test]
    public function marcar_y_aplicar_escribe_ajuste_plano_con_tercero_del_empleado_y_cuadra(): void
    {
        $this->base();
        $this->aplicar($this->contable());

        $plano = PlanoAplicado::where('tipo', 'reverso_apoyo')->sole();
        $this->assertTrue((bool) $plano->afecta_sistema);
        $this->assertNotNull($plano->afectado_por);
        $this->assertNotNull($plano->afectado_en);
        $this->assertSame('CCC-77', $plano->documento_ccc);

        $cr = RegistroFinanciero::where('origen', 'ajuste_plano')->where('cuenta_contable', '14200530')->sole();
        $this->assertSame('INS00099', $cr->codigo_proyecto);
        $this->assertSame('111', $cr->tercero_dcto);               // tercero del empleado, no SECAR
        $this->assertSame('CCC-77', $cr->documento);               // el documento del plano
        $this->assertEqualsWithDelta(500000, $cr->valor_credito, 1);

        $this->assertEqualsWithDelta(0, $this->netoIns14(), 1);    // la bolsa queda en 0
    }

    #[Test]
    public function reaplicar_reemplaza_su_corrida_no_duplica(): void
    {
        $this->base();
        $c = $this->contable();
        $this->aplicar($c);
        $this->aplicar($c);

        $this->assertSame(1, PlanoAplicado::where('tipo', 'reverso_apoyo')->count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'ajuste_plano')->count()); // CR 14 + DB 61
    }

    #[Test]
    public function recargar_biable_sin_los_ajustes_conserva_el_ajuste(): void
    {
        $this->base();
        $this->aplicar($this->contable());
        $this->assertEqualsWithDelta(0, $this->netoIns14(), 1);

        // Recarga: borra SOLO biable y reinserta el cierre SIN los movimientos del plano.
        RegistroFinanciero::where('mes', 8)->where('anio', 2026)->where('origen', 'biable')->delete();
        $this->biable('INS00099', '14200530', '111', 500000, 0);
        MovimientoBiableImport::deduplicarAjustes(8, 2026);

        // El ajuste se conserva (la recarga no lo trae) → neto sigue en 0.
        $this->assertSame(2, RegistroFinanciero::where('origen', 'ajuste_plano')->count());
        $this->assertEqualsWithDelta(0, $this->netoIns14(), 1);
    }

    #[Test]
    public function recargar_biable_con_los_ajustes_contabilizados_no_duplica(): void
    {
        $this->base();
        $this->aplicar($this->contable());

        // Recarga: borra biable y reinserta el cierre que YA trae el reverso contabilizado en el ERP
        // (CR 14 y DB 61 con el mismo tercero/valor/UN que el ajuste).
        RegistroFinanciero::where('mes', 8)->where('anio', 2026)->where('origen', 'biable')->delete();
        $this->biable('INS00099', '14200530', '111', 500000, 0);        // costo
        $this->biable('INS00099', '14200530', '111', 0, 500000);        // reverso CR 14 (ya contabilizado)
        $this->biable('INS00099', '61350105', '111', 500000, 0);        // reverso DB 61 (ya contabilizado)
        MovimientoBiableImport::deduplicarAjustes(8, 2026);

        // Los ajustes duplicados se retiran (prevalece biable) → no se duplica; neto sigue en 0.
        $this->assertSame(0, RegistroFinanciero::where('origen', 'ajuste_plano')->count());
        $this->assertEqualsWithDelta(0, $this->netoIns14(), 1);
    }

    #[Test]
    public function el_borrado_por_mes_de_biable_no_toca_el_ajuste_plano(): void
    {
        $this->base();
        $this->aplicar($this->contable());

        RegistroFinanciero::where('mes', 8)->where('anio', 2026)->where('origen', 'biable')->delete();

        $this->assertSame(0, RegistroFinanciero::where('origen', 'biable')->count());
        $this->assertSame(2, RegistroFinanciero::where('origen', 'ajuste_plano')->count()); // sobrevive
    }
}
