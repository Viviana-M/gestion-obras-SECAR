<?php

namespace App\Http\Controllers\Operativo;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MaestroComercialController extends Controller
{
    public function index()
    {
        $fichas = FichaProyecto::orderBy('area')
            ->orderBy('codigo_proyecto')
            ->get();

        return view('operativo.maestro-comercial', compact('fichas'));
    }

    public function importar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        // Guardar temporalmente con extensión correcta
        $dir = storage_path('app/maestro');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = uniqid() . '.' . $request->file('archivo')->getClientOriginalExtension();
        $request->file('archivo')->move($dir, $nombre);
        $full = $dir . '/' . $nombre;

        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($full);

        $creados = 0;
        $actualizados = 0;
        $repetidos = [];
        $vistas = [];

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
                    'user_id'           => $request->user()?->id,
                ];

                // No tocamos responsable_comercial: se llena aparte y se conserva
                $ficha = FichaProyecto::where('codigo_proyecto', $ot)->first();
                if ($ficha) {
                    $ficha->update($datos);
                    $actualizados++;
                } else {
                    FichaProyecto::create(array_merge(['codigo_proyecto' => $ot], $datos));
                    $creados++;
                }
            }
        }

        @unlink($full);

        $repetidos = array_values(array_unique($repetidos));
        $msg = "Carga lista: {$creados} nuevas, {$actualizados} actualizadas.";
        if (count($repetidos) > 0) {
            $msg .= ' OT repetidas en el archivo (quedó la última de cada una): ' . implode(', ', $repetidos) . '.';
        }

        return back()->with('success', $msg);
    }

    public function guardar(Request $request)
    {
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
        $ficha->delete();
        return back()->with('success', 'Proyecto eliminado.');
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