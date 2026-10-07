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
                      ->orWhere('codigo_movimiento', 'like', "%{$q}%")
                      ->orWhere('tipo_movimiento', 'like', "%{$q}%")
                      ->orWhere('cuenta', 'like', "%{$q}%");
                });
            })
            ->orderBy('tipo_inventario')->orderBy('codigo_movimiento')
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
        // La llave es (tipo_inventario + codigo_movimiento). tipo_movimiento es descripción.
        $keys = ['tipo_inventario', 'nombre', 'codigo_movimiento', 'tipo_movimiento', 'cuenta', 'naturaleza'];
        $headerIdx = null;
        $col = array_fill_keys($keys, null);
        foreach ($rows as $i => $r) {
            $found = array_fill_keys($keys, null);
            foreach ($r as $j => $cell) {
                $h = $this->normalizarEncabezado((string) $cell);
                if ($h === '') continue;
                $esMotivo     = str_contains($h, 'MOTIVO') || str_contains($h, 'MOVIMIENTO');
                $esInventario = str_contains($h, 'INVENTARIO');
                if (str_contains($h, 'NATURALEZA')) {
                    $found['naturaleza'] = $j;
                } elseif ($esMotivo) {
                    // Codigo Motivo (la llave) vs Descripción/Nombre Motivo (display).
                    if (str_contains($h, 'CODIGO') || str_contains($h, 'COD ')) {
                        $found['codigo_movimiento'] = $j;
                    } else {
                        $found['tipo_movimiento'] = $j;
                    }
                } elseif (str_contains($h, 'CUENTA')) {
                    $found['cuenta'] = $j;
                } elseif ($esInventario) {
                    // Nombre Tipo de Inventario (display) vs Codigo Tipo de inventario (la llave).
                    if (str_contains($h, 'NOMBRE') || str_contains($h, 'DESCRIPCION')) {
                        $found['nombre'] = $j;
                    } else {
                        $found['tipo_inventario'] = $j;
                    }
                }
            }
            if ($found['tipo_inventario'] !== null && $found['codigo_movimiento'] !== null && $found['cuenta'] !== null) {
                $headerIdx = $i;
                $col = $found;
                break;
            }
        }

        if ($headerIdx === null) {
            return back()->with('error', 'No encontré los encabezados esperados (código tipo de inventario, código de motivo y cuenta).');
        }

        $val = fn ($r, $key) => $col[$key] !== null ? (trim((string) ($r[$col[$key]] ?? '')) ?: null) : null;

        $n = 0; $saltadas = 0;
        DB::transaction(function () use ($rows, $headerIdx, $col, $val, &$n, &$saltadas) {
            foreach ($rows as $i => $r) {
                if ($i <= $headerIdx) continue;
                $ti   = trim((string) ($r[$col['tipo_inventario']] ?? ''));
                $cmov = trim((string) ($r[$col['codigo_movimiento']] ?? ''));
                $cta  = trim((string) ($r[$col['cuenta']] ?? ''));
                if ($ti === '' || $cmov === '' || $cta === '') { $saltadas++; continue; }

                LlaveItemCuenta::updateOrCreate(
                    ['tipo_inventario' => $ti, 'codigo_movimiento' => $cmov],
                    [
                        'cuenta'                 => $cta,
                        'nombre_tipo_inventario' => $val($r, 'nombre'),
                        'tipo_movimiento'        => $val($r, 'tipo_movimiento'),
                        'naturaleza'             => $val($r, 'naturaleza'),
                        'activo'                 => true,
                    ]
                );
                $n++;
            }
        });

        $msg = "Se cargaron {$n} llaves (tipo de inventario + código de movimiento → cuenta).";
        if ($saltadas > 0) $msg .= " Se saltaron {$saltadas} filas incompletas.";

        return back()->with('success', $msg);
    }

    /** Reglas comunes; el par (tipo_inventario, codigo_movimiento) debe ser único. */
    private function validar(Request $request, ?int $ignorarId): array
    {
        return $request->validate([
            'tipo_inventario' => [
                'required', 'string', 'max:100',
                Rule::unique('llave_items_cuenta')
                    ->where(fn ($q) => $q->where('codigo_movimiento', $request->input('codigo_movimiento')))
                    ->ignore($ignorarId),
            ],
            'nombre_tipo_inventario' => 'nullable|string|max:255',
            'codigo_movimiento'      => 'required|string|max:50',
            'tipo_movimiento'        => 'nullable|string|max:255', // descripción, solo para mostrar
            'cuenta'                 => 'required|string|max:60',
            'naturaleza'             => 'nullable|string|max:20',
        ], [
            'tipo_inventario.unique' => 'Ya existe una llave para ese tipo de inventario y código de movimiento.',
        ], [
            'tipo_inventario'   => 'tipo de inventario',
            'codigo_movimiento' => 'código de movimiento',
            'tipo_movimiento'   => 'descripción del movimiento',
            'cuenta'            => 'cuenta',
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
