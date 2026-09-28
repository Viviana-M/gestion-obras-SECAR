<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MaestroComercialController extends Controller
{
    public function index(Request $request)
    {
        // Con ~2.000 obras: buscador (código/nombre/cliente) + paginación.
        $q = trim((string) $request->get('q', ''));

        $fichas = FichaProyecto::query()
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('codigo_proyecto', 'like', "%{$q}%")
                ->orWhere('nombre_obra', 'like', "%{$q}%")
                ->orWhere('cliente', 'like', "%{$q}%")))
            ->orderBy('area')
            ->orderBy('codigo_proyecto')
            ->paginate(50)
            ->withQueryString();

        $total = FichaProyecto::count();

        return view('operativo.maestro-comercial', compact('fichas', 'q', 'total'));
    }

    public function importar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        // ~1.937 filas: se blinda contra el corte del servidor (Hostinger) y se acelera la
        // importación desactivando el log de queries y usando una sola transacción. Además, se
        // precargan los OT existentes (evita un SELECT por fila).
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        DB::connection()->disableQueryLog();

        // Guardar temporalmente con extensión correcta
        $dir = storage_path('app/maestro');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = uniqid() . '.' . $request->file('archivo')->getClientOriginalExtension();
        $request->file('archivo')->move($dir, $nombre);
        $full = $dir . '/' . $nombre;

        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($full);

        $uid = $request->user()?->id;
        $creados = 0;
        $actualizados = 0;
        $repetidos = [];
        $vistas = [];

        // OT ya existentes en el maestro (para decidir update vs insert sin un SELECT por fila).
        $existentes = FichaProyecto::pluck('codigo_proyecto')->flip();

        // Decisiones de la columna ACTIVA por OT (solo las que traen la columna en el archivo)
        // y nombre de la obra, para cerrar/reabrir y armar la lista de revisión al final.
        $decisiones = [];   // ot => bool activa
        $nombres    = [];   // ot => nombre_obra

        DB::transaction(function () use ($spreadsheet, $uid, &$creados, &$actualizados, &$repetidos, &$vistas, &$existentes, &$decisiones, &$nombres) {
        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $area = stripos($sheetName, 'MANT') !== false ? 'Mantenimiento'
                  : (stripos($sheetName, 'INSTAL') !== false ? 'Instalaciones' : $sheetName);

            $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, false, false);

            // Localizar la fila de encabezados (donde está "ORDEN DE TRABAJO")
            $headerIdx = null;
            foreach ($rows as $i => $r) {
                foreach ($r as $cell) {
                    if (strtoupper(trim(preg_replace('/\s+/', ' ', (string)$cell))) === 'ORDEN DE TRABAJO') {
                        $headerIdx = $i;
                        break 2;
                    }
                }
            }
            if ($headerIdx === null) continue;

            // Mapear columnas por nombre de encabezado
            $map = [];
            foreach ($rows[$headerIdx] as $idx => $cell) {
                $h = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$cell)));
                match ($h) {
                    'ORDEN DE TRABAJO'   => $map['ot']          = $idx,
                    'CLIENTE'            => $map['cliente']     = $idx,
                    'OBRA'               => $map['obra']        = $idx,
                    'VALOR CONTRATADO'   => $map['valor']       = $idx,
                    'MC PROYECTADO'      => $map['mc']          = $idx,
                    'UTILIDAD ESTIMADA'  => $map['utilidad']    = $idx,
                    'COSTO ESTIMADO'     => $map['costo']       = $idx,
                    'RESPONSABLE'        => $map['responsable'] = $idx,
                    'ACTIVA'             => $map['activa']      = $idx,
                    default              => null,
                };
            }
            if (!isset($map['ot'])) continue;

            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $r  = $rows[$i];
                $ot = isset($map['ot']) && isset($r[$map['ot']]) ? trim((string)$r[$map['ot']]) : '';
                if ($ot === '') continue;

                if (isset($vistas[$ot])) {
                    $repetidos[] = $ot;
                }
                $vistas[$ot] = true;

                $datos = [
                    'cliente'           => isset($map['cliente'])     ? trim((string)($r[$map['cliente']] ?? '')) : null,
                    'nombre_obra'       => isset($map['obra'])        ? trim((string)($r[$map['obra']] ?? '')) : null,
                    'area'              => $area,
                    'valor_contratado'  => isset($map['valor'])       ? $this->num($r[$map['valor']] ?? null) : null,
                    'margen_ofertado'   => isset($map['mc'])          ? $this->mc($r[$map['mc']] ?? null) : null,
                    'utilidad_ofertada' => isset($map['utilidad'])    ? $this->num($r[$map['utilidad']] ?? null) : null,
                    'costo_estimado'    => isset($map['costo'])       ? $this->num($r[$map['costo']] ?? null) : null,
                    'responsable_obra'  => isset($map['responsable']) ? trim((string)($r[$map['responsable']] ?? '')) : null,
                    'origen'            => 'excel',
                    'user_id'           => $uid,
                ];

                // La columna "Activa" solo se aplica SI viene en el archivo (Si/1/true → true;
                // No/0/false → false). Si no viene, se conserva el valor actual de la ficha.
                if (isset($map['activa'])) {
                    $activa = $this->bool($r[$map['activa']] ?? null);
                    $datos['activa'] = $activa;
                    $decisiones[$ot] = $activa;         // se usa al final para cerrar/reabrir
                }
                $nombres[$ot] = $datos['nombre_obra'] ?? ($nombres[$ot] ?? null);

                // No tocamos responsable_comercial: se llena aparte y se conserva.
                if (isset($existentes[$ot])) {
                    FichaProyecto::where('codigo_proyecto', $ot)->update($datos);
                    $actualizados++;
                } else {
                    FichaProyecto::create(array_merge(['codigo_proyecto' => $ot], $datos));
                    $existentes[$ot] = true;            // si el mismo OT nuevo se repite, se actualiza
                    $creados++;
                }
            }
        }
        });

        // ── Cierre/reapertura automáticos según la columna ACTIVA, cuidando la cuenta 14 ──
        // Saldo de cuenta 14 por obra (una sola consulta).
        $saldos14 = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->selectRaw('codigo_proyecto, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto')
            ->pluck('saldo', 'codigo_proyecto');

        $cerradas          = 0;
        $reabiertas        = 0;
        $inactivasConSaldo = [];

        DB::transaction(function () use ($decisiones, $nombres, $saldos14, $uid, &$cerradas, &$reabiertas, &$inactivasConSaldo) {
            foreach ($decisiones as $ot => $activa) {
                $saldo = (float) ($saldos14[$ot] ?? 0);

                if ($activa === false) {
                    if (abs($saldo) <= 0.5) {
                        // Inactiva y sin saldo en cuenta 14: se cierra (marcada como cierre por Excel).
                        ProyectoCerrado::firstOrCreate(
                            ['codigo_proyecto' => $ot],
                            ['nombre_proyecto' => $nombres[$ot] ?? null, 'tipo_cierre' => 'total',
                             'origen' => 'excel', 'fecha_cierre' => now(), 'user_id' => $uid]
                        );
                        $cerradas++;
                    } else {
                        // Inactiva PERO con saldo: NO se cierra; a la lista de revisión.
                        $inactivasConSaldo[] = [
                            'codigo' => $ot, 'nombre' => $nombres[$ot] ?? '', 'saldo' => abs($saldo),
                        ];
                    }
                } else {
                    // Activa: si estaba cerrada por este mecanismo (origen excel), se reabre.
                    $reabiertas += ProyectoCerrado::where('codigo_proyecto', $ot)
                        ->where('origen', 'excel')->delete();
                }
            }
        });

        @unlink($full);

        usort($inactivasConSaldo, fn ($a, $b) => $b['saldo'] <=> $a['saldo']);

        $repetidos = array_values(array_unique($repetidos));
        $msg = "Carga lista: {$creados} nuevas, {$actualizados} actualizadas.";
        if ($cerradas > 0 || count($inactivasConSaldo) > 0) {
            $msg .= " {$cerradas} obras cerradas, ".count($inactivasConSaldo)
                 .' inactivas con saldo pendiente en cuenta 14 (requieren revisión).';
        }
        if ($reabiertas > 0) {
            $msg .= " {$reabiertas} obras reabiertas.";
        }
        if (count($repetidos) > 0) {
            $msg .= ' OT repetidas en el archivo (quedó la última de cada una): ' . implode(', ', $repetidos) . '.';
        }

        return back()
            ->with('success', $msg)
            ->with('inactivasImport', $inactivasConSaldo);
    }

    public function guardar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $data = $request->validate([
            'codigo_proyecto'       => 'required|string|max:255',
            'cliente'               => 'nullable|string',
            'nombre_obra'           => 'nullable|string',
            'area'                  => 'nullable|string',
            'valor_contratado'      => 'nullable|numeric',
            'margen_ofertado'       => 'nullable|numeric',
            'utilidad_ofertada'     => 'nullable|numeric',
            'costo_estimado'        => 'nullable|numeric',
            'responsable_obra'      => 'nullable|string',
            'responsable_comercial' => 'nullable|string',
        ]);

        $data['origen']  = 'manual';
        $data['user_id'] = $request->user()?->id;

        FichaProyecto::updateOrCreate(
            ['codigo_proyecto' => $data['codigo_proyecto']],
            $data
        );

        return back()->with('success', 'Proyecto guardado.');
    }

    public function actualizar(Request $request, FichaProyecto $ficha)
    {
        abort_unless($request->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $data = $request->validate([
            'codigo_proyecto'       => 'required|string|max:255',
            'cliente'               => 'nullable|string',
            'nombre_obra'           => 'nullable|string',
            'area'                  => 'nullable|string',
            'valor_contratado'      => 'nullable|numeric',
            'margen_ofertado'       => 'nullable|numeric',
            'utilidad_ofertada'     => 'nullable|numeric',
            'costo_estimado'        => 'nullable|numeric',
            'responsable_obra'      => 'nullable|string',
            'responsable_comercial' => 'nullable|string',
        ]);

        $ficha->update($data);

        return back()->with('success', 'Proyecto actualizado.');
    }

    public function eliminar(FichaProyecto $ficha)
    {
        abort_unless(auth()->user()->puedeEditarModulo('operacion'), 403,
            'No tienes permiso para editar en Operación.');

        $ficha->delete();
        return back()->with('success', 'Proyecto eliminado.');
    }

    /** Interpreta la columna "Activa": Si/1/true/x/activa → true; No/0/false/inactiva → false. */
    private function bool($v): bool
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') return true; // celda vacía = activa (comportamiento por defecto)
        if (in_array($s, ['no', 'n', '0', 'false', 'inactiva', 'inactivo'], true)) return false;
        if (in_array($s, ['si', 'sí', 's', '1', 'true', 'x', 'activa', 'activo'], true)) return true;

        return true; // cualquier otro texto: se toma como activa
    }

    private function num($v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '-') return null;
        if (is_numeric($v)) return (float)$v;
        $s = str_replace(['$', ' '], '', $s);
        $s = str_replace('.', '', $s);   // separador de miles
        $s = str_replace(',', '.', $s);  // coma decimal
        return is_numeric($s) ? (float)$s : null;
    }

    private function mc($v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '-') return null;
        if (is_numeric($v)) {
            $n = (float)$v;
            return round($n <= 1 ? $n * 100 : $n, 2); // 0.26 -> 26 ; 26 -> 26
        }
        $s = str_replace(['%', ' '], '', $s);
        $s = str_replace(',', '.', $s);  // "13,9" -> "13.9"
        return is_numeric($s) ? round((float)$s, 2) : null;
    }
}