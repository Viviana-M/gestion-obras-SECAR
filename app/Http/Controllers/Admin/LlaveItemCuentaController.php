<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LlaveItemCuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LlaveItemCuentaController extends Controller
{
    private function soloAdmin(): void
    {
        abort_unless(auth()->user()?->esAdmin(), 403, 'Solo un administrador puede gestionar la llave de cuentas por ítem.');
    }

    public function index(Request $request)
    {
        $this->soloAdmin();

        $q = trim((string) $request->get('q', ''));
        $items = LlaveItemCuenta::query()
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('tipo_inventario', 'like', "%{$q}%")
                      ->orWhere('nombre_tipo_inventario', 'like', "%{$q}%")
                      ->orWhere('tipo_movimiento', 'like', "%{$q}%")
                      ->orWhere('cuenta', 'like', "%{$q}%");
                });
            })
            ->orderBy('tipo_inventario')->orderBy('tipo_movimiento')
            ->get();

        return view('admin.llave-items.index', ['items' => $items, 'q' => $q]);
    }

    public function store(Request $request)
    {
        $this->soloAdmin();
        $datos = $this->validar($request, null);
        LlaveItemCuenta::create($datos + ['activo' => true]);

        return back()->with('success', 'Llave agregada.');
    }

    public function update(Request $request, LlaveItemCuenta $llaveItemCuenta)
    {
        $this->soloAdmin();
        $datos = $this->validar($request, $llaveItemCuenta->id);
        $llaveItemCuenta->update($datos);

        return back()->with('success', 'Llave actualizada.');
    }

    public function toggle(LlaveItemCuenta $llaveItemCuenta)
    {
        $this->soloAdmin();
        $llaveItemCuenta->activo = ! $llaveItemCuenta->activo;
        $llaveItemCuenta->save();

        return back()->with('success', $llaveItemCuenta->activo ? 'Llave activada.' : 'Llave desactivada.');
    }

    /**
     * Cargue por Excel: una fila por (tipo de inventario + tipo de movimiento) con su
     * cuenta. Se reemplaza (updateOrCreate) por el par, así recargar el mismo contenido
     * no duplica.
     */
    public function importar(Request $request)
    {
        $this->soloAdmin();
        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls|max:51200']);

        $full = $request->file('archivo')->getRealPath();
        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($full)->getActiveSheet()->toArray(null, true, false, false);

        if (empty($rows)) {
            return back()->with('error', 'El archivo está vacío.');
        }

        // Localizar la fila de encabezados y mapear las columnas por su nombre (flexible).
        $headerIdx = null;
        $col = ['tipo_inventario' => null, 'nombre' => null, 'tipo_movimiento' => null, 'cuenta' => null, 'naturaleza' => null];
        foreach ($rows as $i => $r) {
            $found = ['tipo_inventario' => null, 'nombre' => null, 'tipo_movimiento' => null, 'cuenta' => null, 'naturaleza' => null];
            foreach ($r as $j => $cell) {
                $h = $this->normalizarEncabezado((string) $cell);
                if ($h === '') continue;
                if (str_contains($h, 'NATURALEZA')) {
                    $found['naturaleza'] = $j;
                } elseif (str_contains($h, 'CUENTA')) {
                    $found['cuenta'] = $j;
                } elseif (str_contains($h, 'MOVIMIENTO') || str_contains($h, 'MOTIVO')) {
                    $found['tipo_movimiento'] = $j;
                } elseif (str_contains($h, 'NOMBRE') && str_contains($h, 'INVENTARIO')) {
                    $found['nombre'] = $j;
                } elseif (str_contains($h, 'TIPO') && str_contains($h, 'INVENTARIO')) {
                    $found['tipo_inventario'] = $j;
                }
            }
            if ($found['tipo_inventario'] !== null && $found['tipo_movimiento'] !== null && $found['cuenta'] !== null) {
                $headerIdx = $i;
                $col = $found;
                break;
            }
        }

        if ($headerIdx === null) {
            return back()->with('error', 'No encontré los encabezados esperados (tipo de inventario, tipo de movimiento y cuenta).');
        }

        $n = 0; $saltadas = 0;
        DB::transaction(function () use ($rows, $headerIdx, $col, &$n, &$saltadas) {
            foreach ($rows as $i => $r) {
                if ($i <= $headerIdx) continue;
                $ti  = trim((string) ($r[$col['tipo_inventario']] ?? ''));
                $tm  = trim((string) ($r[$col['tipo_movimiento']] ?? ''));
                $cta = trim((string) ($r[$col['cuenta']] ?? ''));
                if ($ti === '' || $tm === '' || $cta === '') { $saltadas++; continue; }

                LlaveItemCuenta::updateOrCreate(
                    ['tipo_inventario' => $ti, 'tipo_movimiento' => $tm],
                    [
                        'cuenta'                 => $cta,
                        'nombre_tipo_inventario' => $col['nombre'] !== null ? (trim((string) ($r[$col['nombre']] ?? '')) ?: null) : null,
                        'naturaleza'             => $col['naturaleza'] !== null ? (trim((string) ($r[$col['naturaleza']] ?? '')) ?: null) : null,
                        'activo'                 => true,
                    ]
                );
                $n++;
            }
        });

        $msg = "Se cargaron {$n} llaves (tipo de inventario + movimiento → cuenta).";
        if ($saltadas > 0) $msg .= " Se saltaron {$saltadas} filas incompletas.";

        return back()->with('success', $msg);
    }

    /** Reglas comunes; el par (tipo_inventario, tipo_movimiento) debe ser único. */
    private function validar(Request $request, ?int $ignorarId): array
    {
        return $request->validate([
            'tipo_inventario' => [
                'required', 'string', 'max:100',
                Rule::unique('llave_items_cuenta')
                    ->where(fn ($q) => $q->where('tipo_movimiento', $request->input('tipo_movimiento')))
                    ->ignore($ignorarId),
            ],
            'nombre_tipo_inventario' => 'nullable|string|max:255',
            'tipo_movimiento'        => 'required|string|max:255',
            'cuenta'                 => 'required|string|max:60',
            'naturaleza'             => 'nullable|string|max:20',
        ], [
            'tipo_inventario.unique' => 'Ya existe una llave para ese tipo de inventario y tipo de movimiento.',
        ], [
            'tipo_inventario'        => 'tipo de inventario',
            'tipo_movimiento'        => 'tipo de movimiento',
            'cuenta'                 => 'cuenta',
        ]);
    }

    /** Normaliza un encabezado: mayúsculas, sin acentos, espacios colapsados. */
    private function normalizarEncabezado(string $s): string
    {
        $s = strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U',
        ]);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    }
}
