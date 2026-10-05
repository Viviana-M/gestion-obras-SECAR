<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecalcularPcgaTest extends TestCase
{
    use RefreshDatabase;

    private function rf(array $attrs): RegistroFinanciero
    {
        return RegistroFinanciero::create(array_merge([
            'codigo_proyecto' => 'C-1', 'nombre_proyecto' => 'Obra', 'cuenta_mayor' => 'Costos por aplicar',
            'valor_debito' => 0, 'valor_credito' => 0, 'movto_libro2' => 777, 'estado_er' => 999,
            'mes' => 6, 'anio' => 2026, 'origen' => 'biable',
        ], $attrs));
    }

    #[Test]
    public function recalcula_a_libro1_y_elimina_las_fantasma_solo_en_biable(): void
    {
        // cuenta 14/61/4 → estado_er = −(déb−cré); otra cuenta → +(déb−cré).
        $c14 = $this->rf(['cuenta_contable' => '14200530', 'valor_debito' => 800000, 'valor_credito' => 300000]); // movto 500k
        $c61 = $this->rf(['cuenta_contable' => '61350105', 'valor_debito' => 200000, 'valor_credito' => 0]);      // movto 200k
        $c4  = $this->rf(['cuenta_contable' => '41350100', 'valor_debito' => 0, 'valor_credito' => 1000000]);     // movto -1M
        $c25 = $this->rf(['cuenta_contable' => '25100000', 'valor_debito' => 100000, 'valor_credito' => 40000]);  // movto 60k (no 14/61/4)
        // Fantasma: solo libro 2 (neto 0) → se eliminan.
        $f0  = $this->rf(['cuenta_contable' => '14200536', 'valor_debito' => 0, 'valor_credito' => 0]);
        $feq = $this->rf(['cuenta_contable' => '14200599', 'valor_debito' => 500, 'valor_credito' => 500]);
        // Plano del sistema: NO se toca.
        $plano = $this->rf(['cuenta_contable' => '14200530', 'valor_debito' => 0, 'valor_credito' => 500000,
            'origen' => 'distribucion_plano', 'estado_er' => 123456, 'movto_libro2' => 123456]);

        $this->artisan('financiero:recalcular-pcga', ['--force' => true])->assertSuccessful();

        // Fantasma eliminadas; el resto de biable sigue.
        $this->assertNull(RegistroFinanciero::find($f0->id));
        $this->assertNull(RegistroFinanciero::find($feq->id));
        $this->assertSame(4, RegistroFinanciero::where('origen', 'biable')->count());

        // Recálculo Libro 1: movto_libro2 = déb − cré; estado_er según cuenta.
        $this->assertEqualsWithDelta(500000, $c14->fresh()->movto_libro2, 0.5);
        $this->assertEqualsWithDelta(-500000, $c14->fresh()->estado_er, 0.5);   // 14x → negativo
        $this->assertEqualsWithDelta(-200000, $c61->fresh()->estado_er, 0.5);   // 61x → negativo
        $this->assertEqualsWithDelta(1000000, $c4->fresh()->estado_er, 0.5);    // 4x → −(−1M) = +1M
        $this->assertEqualsWithDelta(60000, $c25->fresh()->movto_libro2, 0.5);
        $this->assertEqualsWithDelta(60000, $c25->fresh()->estado_er, 0.5);     // no 14/61/4 → +(déb−cré)

        // El plano del sistema queda intacto.
        $this->assertNotNull($plano->fresh());
        $this->assertEqualsWithDelta(123456, $plano->fresh()->estado_er, 0.5);
        $this->assertEqualsWithDelta(123456, $plano->fresh()->movto_libro2, 0.5);
    }

    #[Test]
    public function sin_registros_biable_no_hace_nada(): void
    {
        $this->rf(['cuenta_contable' => '14200530', 'valor_debito' => 100, 'origen' => 'distribucion_plano', 'estado_er' => 55]);
        $this->artisan('financiero:recalcular-pcga', ['--force' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(55, RegistroFinanciero::first()->estado_er, 0.5); // intacto
    }
}
