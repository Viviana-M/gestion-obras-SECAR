<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use App\Models\ProyectoCerrado;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PlanoReversionController extends Controller
{
    /**
     * Página de Contabilidad: vista previa de las cuentas 14 con saldo CONTRARIO
     * ("reversión excesiva" = saldo de la 14 del lado equivocado) y descarga del plano.
     * Filtro opcional por mes/año (acumulado al mes, mismo criterio que el resto).
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; } // corte solo si vienen ambos

        $filas = $this->cuentas14Contrarias($mes, $anio);
        $total = array_sum(array_map(fn ($f) => abs($f['saldo']), $filas));

        $periodos = RegistroFinanciero::selectRaw('anio, mes')
            ->distinct()->orderByDesc('anio')->orderByDesc('mes')->get();

        return view('contable.plano-reversion', compact('filas', 'total', 'mes', 'anio', 'periodos'));
    }
    /**
     * COLUMNAS DEL PLANO (extensible).
     * Para agregar un campo de SIESA en el futuro:
     *   1) añade aquí 'clave' => 'Encabezado'
     *   2) puebla esa 'clave' en el método fila()
     */
    private array $columnas = [
        'cuenta'          => 'Cuenta',
        'nombre'          => 'Nombre cuenta',
        'codigo_proyecto' => 'Proyecto',
        'debito'          => 'Débito',
        'credito'         => 'Crédito',
        // 'fecha'        => 'Fecha',
        // 'documento'    => 'Documento',
        // 'naturaleza'   => 'Naturaleza',
        // 'tercero'      => 'Tercero',
        // 'centro_costo' => 'Centro de costo',
    ];

    public function exportarPlano(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $mes  = $request->filled('mes') ? (int) $request->get('mes') : null;
        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        if ($mes === null || $anio === null) { $mes = null; $anio = null; }

        $lineas = $this->construirLineas($mes, $anio);

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Plano reversión');

        // Encabezados
        $col = 1;
        foreach ($this->columnas as $titulo) {
            $sheet->setCellValue([$col, 1], $titulo);
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            $col++;
        }
        $rango = 'A1:' . $sheet->getHighestColumn() . '1';
        $sheet->getStyle($rango)->getFont()->setBold(true);
        $sheet->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)
              ->getStartColor()->setRGB('F3F4F6');

        // Filas
        $fila = 2;
        foreach ($lineas as $l) {
            $col = 1;
            foreach ($this->columnas as $key => $titulo) {
                $sheet->setCellValue([$col, $fila], $l[$key] ?? '');
                $col++;
            }
            $fila++;
        }

        $nombre = 'plano_reversion_' . date('Ymd_His') . '.xlsx';
        $tmp = storage_path('app/' . $nombre);
        (new Xlsx($ss))->save($tmp);

        return response()->download($tmp, $nombre)->deleteFileAfterSend(true);
    }

    /**
     * Saldo por (obra cerrada, subcuenta 14) — misma fórmula que la tarjeta. Con corte
     * acumulado al mes si se indica (todos los períodos anteriores + el mes).
     */
    private function baseSaldos14(?int $mes, ?int $anio)
    {
        return RegistroFinanciero::whereIn('codigo_proyecto', ProyectoCerrado::pluck('codigo_proyecto'))
            ->where('cuenta_mayor', 'Costos por aplicar')
            ->when($mes && $anio, function ($q) use ($mes, $anio) {
                $q->where(function ($sub) use ($mes, $anio) {
                    $sub->where('anio', '<', $anio)
                        ->orWhere(fn ($s) => $s->where('anio', $anio)->where('mes', '<=', $mes));
                });
            })
            ->selectRaw('codigo_proyecto, cuenta_contable, SUM(estado_er) as saldo')
            ->groupBy('codigo_proyecto', 'cuenta_contable')
            ->orderBy('codigo_proyecto')
            ->orderBy('cuenta_contable')
            ->get();
    }

    /**
     * Cuentas 14 con saldo contrario (para la vista previa): [cuenta, nombre, proyecto, saldo],
     * de las obras cerradas cuyo neto de la 14 no cuadró. Ordenadas por mayor saldo (absoluto).
     */
    private function cuentas14Contrarias(?int $mes, ?int $anio): array
    {
        $rows    = $this->baseSaldos14($mes, $anio);
        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')->pluck('descripcion', 'cuenta_contable');

        $netoObra = [];
        foreach ($rows as $r) {
            $netoObra[$r->codigo_proyecto] = ($netoObra[$r->codigo_proyecto] ?? 0) + (float) $r->saldo;
        }
        $obrasMal = array_filter($netoObra, fn ($v) => abs(round($v, 2)) >= 0.5);

        $filas = [];
        foreach ($rows as $r) {
            if (! isset($obrasMal[$r->codigo_proyecto])) continue;
            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;
            $filas[] = [
                'cuenta'    => $r->cuenta_contable,
                'nombre'    => $nombres[$r->cuenta_contable] ?? '',
                'proyecto'  => $r->codigo_proyecto,
                'saldo'     => $saldo,
            ];
        }
        usort($filas, fn ($a, $b) => abs($b['saldo']) <=> abs($a['saldo']));

        return $filas;
    }

    private function construirLineas(?int $mes = null, ?int $anio = null): array
    {
        $homol = Homologacion::pluck('cuenta_61', 'cuenta_14');

        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')
            ->pluck('descripcion', 'cuenta_contable');

        $rows = $this->baseSaldos14($mes, $anio);

        // Neto de la 14 por obra (para quedarnos solo con las que de verdad quedaron mal)
        $netoObra = [];
        foreach ($rows as $r) {
            $netoObra[$r->codigo_proyecto] = ($netoObra[$r->codigo_proyecto] ?? 0) + (float) $r->saldo;
        }
        $obrasMal = array_filter($netoObra, fn($v) => abs(round($v, 2)) >= 0.5);

        // Si SIESA lo pide al revés, cambia a true (único punto a tocar)
        $invertir = false;

        $lineas = [];
        foreach ($rows as $r) {
            $cod = $r->codigo_proyecto;
            if (!isset($obrasMal[$cod])) continue;          // obra ya cuadrada: se ignora completa

            $saldo = round((float) $r->saldo, 2);
            if (abs($saldo) < 0.5) continue;                // subcuenta sin saldo: se ignora

            $c14 = $r->cuenta_contable;
            $c61 = $homol[$c14] ?? 'SIN HOMOLOGAR';
            $nom14 = $nombres[$c14] ?? '';
            $nom61 = $nombres[$c61] ?? '';
            $m = abs($saldo);

            // saldo NEGATIVO (pendiente) -> CR 14 / DB 61 ; POSITIVO (reversado) -> DB 14 / CR 61
            $acreditar14 = ($saldo < 0);
            if ($invertir) $acreditar14 = !$acreditar14;

            if ($acreditar14) {
                $lineas[] = $this->fila($c14, $nom14, $cod, 0, $m);
                $lineas[] = $this->fila($c61, $nom61, $cod, $m, 0);
            } else {
                $lineas[] = $this->fila($c14, $nom14, $cod, $m, 0);
                $lineas[] = $this->fila($c61, $nom61, $cod, 0, $m);
            }
        }

        return $lineas;
    }

    private function fila(string $cuenta, string $nombre, string $cod, float $deb, float $cred, array $extra = []): array
    {
        return array_merge([
            'cuenta'          => $cuenta,
            'nombre'          => $nombre,
            'codigo_proyecto' => $cod,
            'debito'          => $deb,
            'credito'         => $cred,
        ], $extra);
    }
}