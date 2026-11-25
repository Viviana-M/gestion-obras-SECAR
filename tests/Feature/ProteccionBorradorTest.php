<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AbrePeriodoCierre;
use Tests\TestCase;

/**
 * Protección de borradores: un guardado chico no puede borrar/encoger un borrador grande por
 * accidente (auto lo bloquea; manual pide confirmar_reemplazo), y al entrar siempre se retoma el
 * trabajo existente aunque todas las versiones estén reemplazadas.
 */
class ProteccionBorradorTest extends TestCase
{
    use RefreshDatabase;
    use AbrePeriodoCierre;

    private function operador(): User
    {
        return User::factory()->create([
            'rol' => 'aux_costos', 'activo' => true, 'permisos_modulos' => ['operacion' => 'editar'],
        ]);
    }

    /** Borrador "grande" con $obras obras ya distribuidas. */
    private function borradorGrande(int $obras = 6, bool $reemplazada = false): Distribucion
    {
        $uid = $this->operador()->id;
        $d = Distribucion::create([
            'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => 1, 'estado' => 'borrador', 'edicion_habilitada' => false, 'reemplazada' => $reemplazada,
            'guardado_por' => $uid,
        ]);
        for ($i = 1; $i <= $obras; $i++) {
            AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 7, 'anio' => 2026,
                'codigo_proyecto' => 'C-'.$i, 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
                'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => 1000000, 'es_provision' => false,
                'estado' => 'ok', 'user_id' => $uid]);
        }
        return $d;
    }

    #[Test]
    public function el_autoguardado_no_aplica_un_encogimiento_drastico(): void
    {
        $this->borradorGrande(6);

        $resp = $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'auto' => '1', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 500]], // 1 obra encima de 6
        ]);

        $resp->assertStatus(409)->assertJson(['ok' => false, 'shrink' => true]);
        // No se tocó nada: la distribución grande sigue con sus 6 obras.
        $this->assertSame(6, AplicacionCosto::count());
    }

    #[Test]
    public function el_guardado_manual_exige_confirmar_reemplazo(): void
    {
        $this->borradorGrande(6);
        $op = $this->operador();

        // Sin confirmar → bloqueado con el aviso.
        $this->actingAs($op)->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 500]],
        ])->assertStatus(409)->assertJson(['ok' => false, 'shrink' => true]);
        $this->assertSame(6, AplicacionCosto::count()); // intacto

        // Con confirmar_reemplazo=1 → se permite (el usuario aceptó el reemplazo).
        $this->actingAs($op)->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'confirmar_reemplazo' => 1,
            'aplicar' => ['C-1' => ['14200105' => 500]],
        ])->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function un_guardado_que_no_encoge_pasa_sin_confirmar(): void
    {
        $this->borradorGrande(6);

        // 5 de 6 obras (no es un encogimiento drástico) → se guarda normal.
        $this->actingAs($this->operador())->postJson(route('operativo.distribucion.guardar'), [
            'accion' => 'guardar', 'mes' => 7, 'anio' => 2026, 'departamento' => 'mantenimiento',
            'aplicar' => ['C-1' => ['14200105' => 1000000], 'C-2' => ['14200105' => 1000000],
                'C-3' => ['14200105' => 1000000], 'C-4' => ['14200105' => 1000000], 'C-5' => ['14200105' => 1000000]],
        ])->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function al_entrar_se_retoma_la_ultima_version_aunque_este_reemplazada(): void
    {
        // Todas las versiones quedaron reemplazadas (no hay vigente): igual se retoma, no base vacía.
        $d = $this->borradorGrande(4, reemplazada: true);

        $resp = $this->actingAs($this->operador())
            ->get('/operativo/distribucion?mes=7&anio=2026&departamento=mantenimiento');

        $resp->assertOk();
        $this->assertSame($d->id, $resp->viewData('distId'));
    }
}
