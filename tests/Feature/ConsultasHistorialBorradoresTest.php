<?php

namespace Tests\Feature;

use App\Models\AplicacionCosto;
use App\Models\Distribucion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El "Informe de costos" muestra, por (mes, departamento), el historial de TODAS las versiones de
 * obras (incluidas las reemplazadas) y avisa cuando el vigente luce incompleto frente al historial.
 */
class ConsultasHistorialBorradoresTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $nombre): User
    {
        return User::factory()->create(['name' => $nombre, 'rol' => 'aux_costos', 'activo' => true,
            'permisos_modulos' => ['operacion' => 'editar']]);
    }

    private function dist(int $version, bool $reemplazada, int $guardadoPor): Distribucion
    {
        return Distribucion::create([
            'mes' => 10, 'anio' => 2026, 'departamento' => 'mantenimiento', 'tipo' => 'obras',
            'version' => $version, 'estado' => 'borrador', 'edicion_habilitada' => false,
            'reemplazada' => $reemplazada, 'guardado_por' => $guardadoPor,
        ]);
    }

    private function aplicar(Distribucion $d, string $cod, float $monto, int $uid): void
    {
        AplicacionCosto::create(['distribucion_id' => $d->id, 'mes' => 10, 'anio' => 2026,
            'codigo_proyecto' => $cod, 'cuenta_14' => '14200105', 'cuenta_61' => '61300105',
            'categoria' => 'MAT', 'nombre' => 'c', 'monto_aplicar' => $monto, 'es_provision' => false,
            'estado' => 'ok', 'user_id' => $uid]);
    }

    #[Test]
    public function muestra_el_historial_y_avisa_si_el_vigente_luce_incompleto(): void
    {
        $nubia   = $this->user('Nubia');
        $brandon = $this->user('Brandon');

        // v1 (reemplazada): Nubia con 6 obras.
        $v1 = $this->dist(1, true, $nubia->id);
        foreach (['C-1','C-2','C-3','C-4','C-5','C-6'] as $c) $this->aplicar($v1, $c, 1000, $nubia->id);
        // v2 (vigente): Brandon con 1 obra.
        $v2 = $this->dist(2, false, $brandon->id);
        $this->aplicar($v2, 'C-1', 500, $brandon->id);

        $fila = collect($this->actingAs($brandon)->get(route('operativo.distribucion.consultas'))->viewData('filas'))
            ->firstWhere('departamento', 'mantenimiento');

        $this->assertNotNull($fila);
        $this->assertSame(1, $fila['obras']['obras']);          // vigente = v2, 1 obra
        $this->assertSame(6, $fila['max_obras_historial']);     // la anterior tuvo 6
        $this->assertTrue($fila['incompleto']);                 // salta la alerta ámbar
        $this->assertCount(2, $fila['historial']);              // ambas versiones en el historial

        // El historial trae la vigente y la reemplazada, ordenadas por versión desc.
        $this->assertSame(2, $fila['historial'][0]['version']);
        $this->assertFalse($fila['historial'][0]['reemplazada']);   // v2 vigente
        $this->assertSame('Brandon', $fila['historial'][0]['usuario']);
        $this->assertSame(1, $fila['historial'][1]['version']);
        $this->assertTrue($fila['historial'][1]['reemplazada']);    // v1 reemplazada
        $this->assertSame(6, $fila['historial'][1]['obras']);
        $this->assertSame('Nubia', $fila['historial'][1]['usuario']);

        // En pantalla aparece el aviso y el desplegable del historial.
        $html = $this->actingAs($brandon)->get(route('operativo.distribucion.consultas'))->getContent();
        $this->assertStringContainsString('Ver historial de borradores', $html);
        $this->assertStringContainsString('Revisá el historial por si quedó incompleto', $html);
    }
}
