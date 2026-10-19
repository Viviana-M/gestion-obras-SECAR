<?php

namespace Tests\Feature;

use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validación de sobre-reversión: se agrupa por (obra · cuenta · tercero · |valor|) sobre todos
 * los períodos y se marca sólo el exceso de crédito (reversiones sin costo detrás). Un débito
 * nunca es sospechoso por sí solo; el borrado toca sólo el exceso de crédito y con confirmación.
 */
class SobreReversionTest extends TestCase
{
    use RefreshDatabase;

    private function contable(): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);
    }

    /** Línea de débito (costo). */
    private function debito(string $obra, string $cuenta, float $valor, string $doc, int $mes, string $tercero = '890'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $obra, 'nombre_proyecto' => 'OBRA', 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $tercero, 'razon_social' => 'PROV',
            'documento' => $doc, 'valor_debito' => $valor, 'valor_credito' => 0, 'estado_er' => -$valor,
            'periodo' => sprintf('2025%02d', $mes), 'mes' => $mes, 'anio' => 2025,
        ]);
    }

    /** Línea de crédito (reversión). */
    private function credito(string $obra, string $cuenta, float $valor, string $doc, int $mes, string $tercero = '890'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $obra, 'nombre_proyecto' => 'OBRA', 'cuenta_contable' => $cuenta,
            'cuenta_mayor' => 'Costos por aplicar', 'tercero_dcto' => $tercero, 'razon_social' => 'PROV',
            'documento' => $doc, 'valor_debito' => 0, 'valor_credito' => $valor, 'estado_er' => $valor,
            'periodo' => sprintf('2025%02d', $mes), 'mes' => $mes, 'anio' => 2025,
        ]);
    }

    // ─────────── El caso real GM000021: 2 costos + 2 reversiones → legítimo ───────────

    #[Test]
    public function no_marca_reversiones_legitimas_respaldadas_por_su_costo(): void
    {
        // Un sueldo causado dos veces (documentos distintos) y revertido en un documento con dos
        // líneas idénticas. 2 débitos ↔ 2 créditos: cada reversión tiene su costo. No sobra nada.
        $this->debito('GM000021', '14200105', 134670, 'NQ-00000045', 3);
        $this->debito('GM000021', '14200105', 134670, 'NQ-00000046', 3);
        $this->credito('GM000021', '14200105', 134670, 'CCC-00000179', 5);
        $this->credito('GM000021', '14200105', 134670, 'CCC-00000179', 5);

        $grupos = $this->actingAs($this->contable())
            ->get(route('contable.sobre-reversion.index'))->viewData('grupos');

        $this->assertCount(0, $grupos);   // 2 ≤ 2 → no es sobre-reversión
    }

    // ─────────── Un duplicado real: 3 reversiones, 2 costos → sobra 1 ───────────

    #[Test]
    public function marca_el_exceso_de_credito_como_sobre_reversion(): void
    {
        $this->debito('OB1', '14200105', 50000, 'NQ-1', 3);
        $this->debito('OB1', '14200105', 50000, 'NQ-2', 3);
        $this->credito('OB1', '14200105', 50000, 'CCC-1', 5);
        $this->credito('OB1', '14200105', 50000, 'CCC-1', 5);
        $this->credito('OB1', '14200105', 50000, 'CCC-2', 6);   // la de más

        $grupos = $this->actingAs($this->contable())
            ->get(route('contable.sobre-reversion.index'))->viewData('grupos');

        $this->assertCount(1, $grupos);
        $this->assertSame(2, (int) $grupos[0]->n_deb);
        $this->assertSame(3, (int) $grupos[0]->n_cred);
        $this->assertEqualsWithDelta(50000, (float) $grupos[0]->monto, 1);
        $this->assertNotEmpty($grupos[0]->ids_credito);
    }

    // ─────────── Eliminar sólo el exceso de crédito, jamás un débito ───────────

    #[Test]
    public function eliminar_borra_solo_el_exceso_de_credito_y_conserva_lo_demas(): void
    {
        $this->debito('OB1', '14200105', 50000, 'NQ-1', 3);
        $this->debito('OB1', '14200105', 50000, 'NQ-2', 3);
        $this->credito('OB1', '14200105', 50000, 'CCC-1', 5);
        $this->credito('OB1', '14200105', 50000, 'CCC-1', 5);
        $this->credito('OB1', '14200105', 50000, 'CCC-2', 6);

        $this->actingAs($this->contable())
            ->post(route('contable.sobre-reversion.eliminar'), [
                'obra' => 'OB1', 'cuenta' => '14200105', 'tercero' => '890', 'monto' => 50000,
            ])
            ->assertRedirect()->assertSessionHas('success');

        // Queda 1 crédito de sobra menos: 2 débitos + 2 créditos.
        $this->assertSame(2, RegistroFinanciero::where('valor_debito', '<>', 0)->count());
        $this->assertSame(2, RegistroFinanciero::where('valor_credito', '<>', 0)->count());
        // Los débitos NUNCA se tocan.
        $this->assertSame(1, RegistroFinanciero::where('documento', 'NQ-1')->count());
        $this->assertSame(1, RegistroFinanciero::where('documento', 'NQ-2')->count());
        // Ya no queda sobre-reversión.
        $this->assertCount(0, $this->actingAs($this->contable())
            ->get(route('contable.sobre-reversion.index'))->viewData('grupos'));
    }

    #[Test]
    public function eliminar_no_borra_nada_cuando_no_hay_exceso(): void
    {
        $this->debito('GM000021', '14200105', 134670, 'NQ-45', 3);
        $this->debito('GM000021', '14200105', 134670, 'NQ-46', 3);
        $this->credito('GM000021', '14200105', 134670, 'CCC-179', 5);
        $this->credito('GM000021', '14200105', 134670, 'CCC-179', 5);

        $this->actingAs($this->contable())
            ->post(route('contable.sobre-reversion.eliminar'), [
                'obra' => 'GM000021', 'cuenta' => '14200105', 'tercero' => '890', 'monto' => 134670,
            ])
            ->assertRedirect()->assertSessionHas('error');

        // Nada se borró: siguen las 4 filas.
        $this->assertSame(4, RegistroFinanciero::count());
    }
}
