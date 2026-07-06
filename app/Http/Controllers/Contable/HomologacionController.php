<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HomologacionController extends Controller
{
    public function index()
    {
        $homologaciones = Homologacion::orderBy('cuenta_14')->get();
        return view('contable.homologaciones', compact('homologaciones'));
    }

    public function importar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        $dir = storage_path('app/homologaciones');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = uniqid() . '.' . $request->file('archivo')->getClientOriginalExtension();
        $request->file('archivo')->move($dir, $nombre);
        $full = $dir . '/' . $nombre;

        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($full);

        // Buscar la hoja "Plan de Cuentas" (o usar la primera si no aparece)
        $hojaObjetivo = null;
        foreach ($spreadsheet->getSheetNames() as $sn) {
            if (stripos($sn, 'plan de cuenta') !== false) { $hojaObjetivo = $sn; break; }
        }
        if ($hojaObjetivo === null) {
            $hojaObjetivo = $spreadsheet->getSheetNames()[0];
        }

        $rows = $spreadsheet->getSheetByName($hojaObjetivo)->toArray(null, true, false, false);

        // Localizar fila de encabezados (donde está "CUENTA INV OBRA")
        $headerIdx = null;
        foreach ($rows as $i => $r) {
            foreach ($r as $cell) {
                if (strtoupper(trim(preg_replace('/\s+/', ' ', (string)$cell))) === 'CUENTA INV OBRA') {
                    $headerIdx = $i; break 2;
                }
            }
        }
        if ($headerIdx === null) {
            @unlink($full);
            return back()->with('error', 'No encontré la columna "Cuenta Inv Obra" en la hoja. Verifica el archivo.');
        }

        // Mapear columnas del bloque izquierdo por nombre
        $map = [];
        foreach ($rows[$headerIdx] as $idx => $cell) {
            $h = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$cell)));
            match ($h) {
                'NOMBRE'          => $map['nombre']     = $idx,
                'CUENTA INV OBRA' => $map['c14']        = $idx,
                'COSTO'           => $map['c61']        = $idx,
                'ESTRUCTURA'      => $map['estructura'] = $idx,
                default           => null,
            };
        }
        if (!isset($map['c14']) || !isset($map['c61'])) {
            @unlink($full);
            return back()->with('error', 'El archivo no trae las columnas Cuenta Inv Obra y Costo juntas.');
        }

        $creados = 0; $actualizados = 0; $repetidos = []; $vistas = [];

        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $c14 = $this->cta($r[$map['c14']] ?? null);
            $c61 = $this->cta($r[$map['c61']] ?? null);

            // Solo filas válidas del bloque izquierdo: cuenta 14 real
            if ($c14 === null || !str_starts_with($c14, '14')) continue;
            if ($c61 === null) continue;

            if (isset($vistas[$c14])) { $repetidos[] = $c14; }
            $vistas[$c14] = true;

            $datos = [
                'cuenta_61'  => $c61,
                'nombre'     => isset($map['nombre'])     ? trim((string)($r[$map['nombre']] ?? '')) : null,
                'estructura' => isset($map['estructura']) ? trim((string)($r[$map['estructura']] ?? '')) : null,
                'origen'     => 'excel',
                'user_id'    => $request->user()?->id,
            ];

            $h = Homologacion::where('cuenta_14', $c14)->first();
            if ($h) { $h->update($datos); $actualizados++; }
            else { Homologacion::create(array_merge(['cuenta_14' => $c14], $datos)); $creados++; }
        }

        @unlink($full);

        $repetidos = array_values(array_unique($repetidos));
        $msg = "Homologaciones: {$creados} nuevas, {$actualizados} actualizadas (hoja: {$hojaObjetivo}).";
        if (count($repetidos) > 0) {
            $msg .= ' Cuentas 14 repetidas (quedó la última): ' . implode(', ', $repetidos) . '.';
        }

        return back()->with('success', $msg);
    }

    public function guardar(Request $request)
    {
        $data = $request->validate([
            'cuenta_14'  => 'required|string|max:255',
            'cuenta_61'  => 'required|string|max:255',
            'nombre'     => 'nullable|string',
            'estructura' => 'nullable|string',
        ]);
        $data['origen']  = 'manual';
        $data['user_id'] = $request->user()?->id;

        Homologacion::updateOrCreate(['cuenta_14' => $data['cuenta_14']], $data);
        return back()->with('success', 'Homologación guardada.');
    }

    public function actualizar(Request $request, Homologacion $homologacion)
    {
        $data = $request->validate([
            'cuenta_14'  => 'required|string|max:255',
            'cuenta_61'  => 'required|string|max:255',
            'nombre'     => 'nullable|string',
            'estructura' => 'nullable|string',
        ]);
        $homologacion->update($data);
        return back()->with('success', 'Homologación actualizada.');
    }

    public function eliminar(Homologacion $homologacion)
    {
        $homologacion->delete();
        return back()->with('success', 'Homologación eliminada.');
    }

    private function cta($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '-') return null;
        if (is_numeric($s)) {
            $f = (float)$s;
            if (floor($f) == $f) $s = (string)(int)$f; // 14200105.0 -> "14200105"
        }
        return $s;
    }
}