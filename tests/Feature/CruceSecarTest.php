<?php

namespace Tests\Feature;

use App\Models\FichaProyecto;
use App\Models\RegistroFinanciero;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CruceSecarTest extends TestCase
{
    use RefreshDatabase;

    private function contable(string $nivel = 'ver'): User
    {
        return User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => $nivel],
        ]);
    }

    private function rf(string $cod, float $er, string $tercero, ?string $razon, string $cuentaMayor = 'Costos por aplicar'): void
    {
        RegistroFinanciero::create([
            'codigo_proyecto' => $cod, 'nombre_proyecto' => 'Proy '.$cod,
            'cuenta_contable' => '14350105', 'cuenta_mayor' => $cuentaMayor,
            'tercero_dcto' => $tercero, 'razon_social' => $razon,
            'estado_er' => $er, 'valor_debito' => 0, 'valor_credito' => 0, 'mes' => 8, 'anio' => 2024,
        ]);
    }

    #[Test]
    public function netea_secar_contra_terceros_por_obra(): void
    {
        FichaProyecto::create(['codigo_proyecto' => 'MO008570', 'nombre_obra' => 'Obra grande', 'activa' => false]);

        // MO008570: SECAR +853.057.866 ; terceros −953.574.268 ; neto −100.516.402.
        $this->rf('MO008570', 853057866, '890319324', 'SECAR INGENIEROS SA');
        $this->rf('MO008570', -953574268, '800111222', 'PROVEEDOR REAL SAS');
        // O08523-A: SECAR +1.015.378 ; terceros −46.215.669 ; neto −45.200.291.
        $this->rf('O08523-A', 1015378, '90319324', 'SECAR INGENIEROS SA');   // NIT con variante
        $this->rf('O08523-A', -46215669, '800222', 'CONSTRUCTORA X');

        $resp = $this->actingAs($this->contable())->get(route('contable.cruce-secar.index'));
        $resp->assertOk();
        $filas = collect($resp->viewData('filas'));

        $a = $filas->firstWhere('codigo', 'MO008570');
        $this->assertEqualsWithDelta(853057866, $a['saldo_secar'], 1);
        $this->assertEqualsWithDelta(-953574268, $a['saldo_terceros'], 1);
        $this->assertEqualsWithDelta(-100516402, $a['saldo_neto'], 1);
        $this->assertSame('Pendiente real', $a['marca']);
        $this->assertSame('Inactiva', $a['estado']);

        $b = $filas->firstWhere('codigo', 'O08523-A');
        $this->assertEqualsWithDelta(1015378, $b['saldo_secar'], 1);
        $this->assertEqualsWithDelta(-46215669, $b['saldo_terceros'], 1);
        $this->assertEqualsWithDelta(-45200291, $b['saldo_neto'], 1);
        $this->assertSame('Pendiente real', $b['marca']);
    }

    #[Test]
    public function marca_las_que_se_netean_a_cero_y_ordena_por_saldo_neto(): void
    {
        // OBA: SECAR y terceros se anulan → neto 0 → "Se netea a ~$0".
        $this->rf('OBA', 5000000, '890319324', 'SECAR INGENIEROS SA');
        $this->rf('OBA', -5000000, '800111', 'PROVEEDOR');
        // OBB: solo terceros (razón NULL cuenta como tercero) → neto grande → "Pendiente real".
        $this->rf('OBB', -8000000, '800222', null);
        // OBC: sin SECAR y neto 0 → NO aparece.
        $this->rf('OBC', 3000, '800333', 'OTRO');
        $this->rf('OBC', -3000, '800333', 'OTRO');

        $resp = $this->actingAs($this->contable())->get(route('contable.cruce-secar.index'));
        $filas = collect($resp->viewData('filas'));

        // Orden por |saldo_neto| desc: OBB (8M) antes que OBA (0); OBC excluida.
        $this->assertSame(['OBB', 'OBA'], $filas->pluck('codigo')->all());
        $this->assertSame('Se netea a ~$0', $filas->firstWhere('codigo', 'OBA')['marca']);
        $this->assertSame('Pendiente real', $filas->firstWhere('codigo', 'OBB')['marca']);
        $this->assertEqualsWithDelta(-8000000, $filas->firstWhere('codigo', 'OBB')['saldo_terceros'], 1);
    }

    #[Test]
    public function descarga_el_excel(): void
    {
        Excel::fake();
        $this->rf('MO008570', 853057866, '890319324', 'SECAR INGENIEROS SA');

        $this->actingAs($this->contable())->get(route('contable.cruce-secar.excel'))->assertOk();

        Excel::assertDownloaded('Cruce_cuenta_14_vs_SECAR_'.date('Ymd').'.xlsx');
    }

    #[Test]
    public function un_usuario_sin_contabilidad_no_puede_ver(): void
    {
        $ajeno = User::factory()->create(['rol' => 'operario', 'activo' => true, 'permisos_modulos' => ['operacion' => 'ver']]);
        $this->actingAs($ajeno)->get(route('contable.cruce-secar.index'))->assertForbidden();
    }
}
