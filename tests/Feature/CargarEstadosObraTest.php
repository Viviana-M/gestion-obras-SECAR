<?php

namespace Tests\Feature;

use App\Models\ObraEstado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El comando obras:cargar-estados aplica el listado del cliente de forma idempotente (updateOrCreate),
 * sin tocar la obra excluida (MOA08659).
 */
class CargarEstadosObraTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function aplica_el_listado_y_es_idempotente(): void
    {
        $this->artisan('obras:cargar-estados')->assertSuccessful();

        // Totales del listado: 147 abierta, 547 cerrada, 43 parcial (737 en total).
        $this->assertSame(737, ObraEstado::count());
        $this->assertSame(147, ObraEstado::where('estado', 'abierta')->count());
        $this->assertSame(547, ObraEstado::where('estado', 'cerrada')->count());
        $this->assertSame(43, ObraEstado::where('estado', 'parcial')->count());

        // Algunos puntuales del Excel.
        $this->assertSame('cerrada', ObraEstado::where('codigo_proyecto', 'C1218701')->value('estado'));
        $this->assertSame('parcial', ObraEstado::where('codigo_proyecto', 'C1101401')->value('estado'));
        $this->assertSame('abierta', ObraEstado::where('codigo_proyecto', 'C2320601')->value('estado'));

        // MOA08659 queda FUERA a propósito (cerrada en Excel pero con saldo en la 14).
        $this->assertFalse(ObraEstado::where('codigo_proyecto', 'MOA08659')->exists());

        // Re-aplicar no duplica ni cambia totales.
        $this->artisan('obras:cargar-estados')->assertSuccessful();
        $this->assertSame(737, ObraEstado::count());
    }

    #[Test]
    public function dry_run_no_escribe_nada(): void
    {
        $this->artisan('obras:cargar-estados', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ObraEstado::count());
    }

    #[Test]
    public function actualiza_el_estado_existente_sin_duplicar(): void
    {
        // Un estado previo distinto se actualiza al del listado (no se crea una fila nueva).
        ObraEstado::create(['codigo_proyecto' => 'C1218701', 'estado' => 'abierta']);

        $this->artisan('obras:cargar-estados')->assertSuccessful();

        $this->assertSame(1, ObraEstado::where('codigo_proyecto', 'C1218701')->count());
        $this->assertSame('cerrada', ObraEstado::where('codigo_proyecto', 'C1218701')->value('estado'));
    }
}
