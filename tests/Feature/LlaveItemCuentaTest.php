<?php

namespace Tests\Feature;

use App\Models\LlaveItemCuenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlaveItemCuentaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'admin', 'activo' => true]);
    }

    /**
     * Genera un xlsx con las columnas reales:
     * [Codigo Tipo de inventario, Nombre Tipo de Inventario, Codigo Motivo, Descripción Motivo, Cuenta, Naturaleza].
     */
    private function excel(array $filas): UploadedFile
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray(['Codigo Tipo de inventario', 'Nombre Tipo de Inventario', 'Codigo Motivo', 'Descripción Motivo', 'Cuenta', 'Naturaleza'], null, 'A1');
        $r = 2;
        foreach ($filas as $f) { $sheet->fromArray($f, null, 'A'.$r); $r++; }
        $path = tempnam(sys_get_temp_dir(), 'llave').'.xlsx';
        (new Xlsx($ss))->save($path);

        return new UploadedFile($path, 'llaves.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function el_admin_crea_una_llave(): void
    {
        $this->actingAs($this->admin())->post(route('admin.llave-items.store'), [
            'tipo_inventario' => '01', 'nombre_tipo_inventario' => 'Inventario en obra',
            'codigo_movimiento' => '14', 'tipo_movimiento' => 'Salida Directa Inventario en Obra',
            'cuenta' => '73950505', 'naturaleza' => 'Débito',
        ])->assertRedirect();

        $this->assertDatabaseHas('llave_items_cuenta', [
            'tipo_inventario' => '01', 'codigo_movimiento' => '14', 'cuenta' => '73950505', 'naturaleza' => 'Débito',
        ]);
    }

    #[Test]
    public function el_par_tipo_mas_codigo_es_unico(): void
    {
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '14', 'tipo_movimiento' => 'Salida Directa', 'cuenta' => '111']);

        $this->actingAs($this->admin())->post(route('admin.llave-items.store'), [
            'tipo_inventario' => '01', 'codigo_movimiento' => '14', 'cuenta' => '222',
        ])->assertSessionHasErrors('tipo_inventario');

        $this->assertSame(1, LlaveItemCuenta::count());
        // El mismo tipo con OTRO código sí se permite.
        $this->actingAs($this->admin())->post(route('admin.llave-items.store'), [
            'tipo_inventario' => '01', 'codigo_movimiento' => '15', 'cuenta' => '333',
        ])->assertRedirect();
        $this->assertSame(2, LlaveItemCuenta::count());
    }

    #[Test]
    public function carga_por_excel_llave_por_codigo_guarda_naturaleza_y_reemplaza(): void
    {
        $admin = $this->admin();
        $filas = [
            ['01', 'Inventario en obra', '14', 'Salida Directa Inventario en Obra', '73950505', 'Débito'],
            ['01', 'Inventario en obra', '15', 'Reintegro salida directa inv. en Obra', '73950510', 'Crédito'],
            ['02', 'Inventario almacén', '71', 'Salida Directa Inventario en Obra', '73950520', 'Débito'],
        ];

        $this->actingAs($admin)->post(route('admin.llave-items.importar'), ['archivo' => $this->excel($filas)])
            ->assertRedirect();

        $this->assertSame(3, LlaveItemCuenta::count());
        // La llave es por (tipo + código); se guarda naturaleza y la descripción.
        $this->assertDatabaseHas('llave_items_cuenta', [
            'tipo_inventario' => '01', 'codigo_movimiento' => '14', 'cuenta' => '73950505',
            'naturaleza' => 'Débito', 'tipo_movimiento' => 'Salida Directa Inventario en Obra',
        ]);
        $this->assertSame('73950510', LlaveItemCuenta::resolver('01', '15')->cuenta);
        $this->assertSame('Crédito', LlaveItemCuenta::resolver('01', '15')->naturaleza);

        // Recargar el MISMO par (01,14) con otra cuenta reemplaza (no duplica).
        $this->actingAs($admin)->post(route('admin.llave-items.importar'), [
            'archivo' => $this->excel([['01', 'Inventario en obra', '14', 'Salida Directa', '99999999', 'Débito']]),
        ])->assertRedirect();

        $this->assertSame(3, LlaveItemCuenta::count()); // sigue 3, no 4
        $this->assertSame('99999999', LlaveItemCuenta::resolver('01', '14')->cuenta);
    }

    #[Test]
    public function el_admin_actualiza_busca_y_alterna_el_estado(): void
    {
        $admin = $this->admin();
        $l = LlaveItemCuenta::create(['tipo_inventario' => '05', 'codigo_movimiento' => '90', 'tipo_movimiento' => 'Baja', 'cuenta' => '111', 'activo' => true]);

        $this->actingAs($admin)->put(route('admin.llave-items.update', $l->id), [
            'tipo_inventario' => '05', 'codigo_movimiento' => '90', 'cuenta' => '222', 'naturaleza' => 'Crédito',
        ])->assertRedirect();
        $this->assertSame('222', $l->refresh()->cuenta);
        $this->assertSame('Crédito', $l->naturaleza);

        // Búsqueda por código de movimiento.
        $this->actingAs($admin)->get(route('admin.llave-items.index', ['q' => '90']))
            ->assertStatus(200)->assertSee('05', false);

        $this->actingAs($admin)->put(route('admin.llave-items.toggle', $l->id))->assertRedirect();
        $this->assertFalse($l->refresh()->activo);
    }

    #[Test]
    public function un_no_admin_no_puede_entrar(): void
    {
        $noAdmin = User::factory()->create([
            'rol' => 'contadora', 'activo' => true, 'permisos_modulos' => ['contabilidad' => 'editar'],
        ]);

        $this->actingAs($noAdmin)->get(route('admin.llave-items.index'))->assertForbidden();
        $this->actingAs($noAdmin)->post(route('admin.llave-items.store'), [
            'tipo_inventario' => 'x', 'codigo_movimiento' => 'y', 'cuenta' => 'z',
        ])->assertForbidden();
    }

    #[Test]
    public function resolver_devuelve_la_cuenta_del_par_por_codigo(): void
    {
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '14', 'cuenta' => '73950505', 'activo' => true]);
        LlaveItemCuenta::create(['tipo_inventario' => '01', 'codigo_movimiento' => '90', 'cuenta' => '000', 'activo' => false]);

        $this->assertSame('73950505', LlaveItemCuenta::resolver('01', '14')?->cuenta);
        $this->assertNull(LlaveItemCuenta::resolver('01', '90'));   // inactiva → no resuelve
        $this->assertNull(LlaveItemCuenta::resolver('99', '14'));   // no existe
    }
}
