<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Requisito de negocio: la pantalla precarga TODO el pendiente, también al RE-ENTRAR a un borrador
 * parcial. Las obras aún no distribuidas vuelven a mostrar su pendiente (no 0) y se marcan para que el
 * guardado las incluya; las obras ya distribuidas conservan lo guardado (merge colaborativo).
 */
class PrecargaReentradaTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    private function seedObra(string $cod, float $saldo = -2_000_000): void
    {
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => '41350100',
            'cuenta_mayor' => 'Ingreso', 'estado_er' => 5_000_000, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
        RegistroFinanciero::create(['codigo_proyecto' => $cod, 'nombre_proyecto' => 'Obra '.$cod, 'cuenta_contable' => '14200105',
            'cuenta_mayor' => 'Costos por aplicar', 'estado_er' => $saldo, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 7, 'anio' => 2026]);
    }

    /** Borrador parcial: solo C-1 distribuida (500k); C-2 sin distribuir todavía. */
    private function borradorParcial(int $uid): Distribucion
    {
        $d = Distribucion::create(['mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => false,
            'lock_version' => 1, 'guardado_por' => $uid]);
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 7, 'anio' => 2026, 'codigo_proyecto' => 'C-1',
            'cuenta_14' => '14200105', 'cuenta_61' => '61300105', 'categoria' => 'MAT', 'nombre' => 'inv',
            'monto_aplicar' => 500_000, 'es_provision' => false, 'estado' => 'borrador']);
        return $d;
    }

    #[Test]
    public function al_reentrar_las_obras_no_distribuidas_vuelven_a_precargar_el_pendiente(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $op = $this->operador();
        $this->borradorParcial($op->id);

        $resp = $this->actingAs($op)->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $resp->assertOk();
        $obras = $resp->viewData('obras');

        // C-1 ya distribuida: conserva lo guardado (500k) y NO se precarga.
        $this->assertFalse((bool) $obras['C-1']['precargada']);
        $this->assertEqualsWithDelta(500_000, $obras['C-1']['sum_aplicar'], 1);

        // C-2 no distribuida: se precarga el pendiente COMPLETO (2M), no 0.
        $this->assertTrue((bool) $obras['C-2']['precargada']);
        $this->assertEqualsWithDelta(2_000_000, $obras['C-2']['sum_aplicar'], 1);

        // El front marca las obras precargadas como editadas al cargar.
        $this->assertStringContainsString("DATOS[cod].precargada", $resp->getContent());
    }

    #[Test]
    public function al_guardar_en_reentrada_se_persisten_las_obras_precargadas(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');
        $op = $this->operador();
        $d = $this->borradorParcial($op->id);

        // El front, en re-entrada, marca C-2 (precargada) y manda su pendiente. Guardado (merge).
        $this->actingAs($op)->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '0', 'accion' => 'guardar', 'dist' => $d->id, 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 500_000], 'C-2' => ['14200105' => 2_000_000]],
            'estado_obra' => ['C-1' => 'parcial', 'C-2' => 'parcial'],
            'obras_editadas' => ['C-2'], 'obras_base' => ['C-2' => 0],
            'lock_version' => 1,
        ])->assertOk()->assertJson(['ok' => true]);

        // C-2 quedó guardada con su pendiente; C-1 intacta. La distribución persiste COMPLETA.
        $this->assertEqualsWithDelta(2_000_000, AplicacionCosto::where('distribucion_id', $d->id)->where('codigo_proyecto', 'C-2')->sum('monto_aplicar'), 1);
        $this->assertEqualsWithDelta(500_000, AplicacionCosto::where('distribucion_id', $d->id)->where('codigo_proyecto', 'C-1')->sum('monto_aplicar'), 1);
    }

    #[Test]
    public function un_borrador_nuevo_precarga_todas_las_obras(): void
    {
        $this->seedObra('C-1');
        $this->seedObra('C-2');

        // Sin borrador: TODAS las obras se precargan con su pendiente.
        $resp = $this->actingAs($this->operador())->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');
        $obras = $resp->viewData('obras');

        $this->assertTrue((bool) $obras['C-1']['precargada']);
        $this->assertTrue((bool) $obras['C-2']['precargada']);
        $this->assertEqualsWithDelta(2_000_000, $obras['C-1']['sum_aplicar'], 1);
        $this->assertEqualsWithDelta(2_000_000, $obras['C-2']['sum_aplicar'], 1);
    }
}
