<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lector robusto del auxiliar de una cuenta del ERP (mismo formato que usa la Reconciliación 14):
 * localiza los encabezados por nombre (U.N., Débitos, Créditos, Neto, Fecha, Nit movto.), ignora
 * "Gran total", filas sin código y subtotales (sin fecha o sin NIT), y acumula por obra el Neto
 * (o Débitos − Créditos) y el neto por mes. Se usa para cruzar tanto la cuenta 14 como la 6.
 */
class AuxiliarErpReader
{
    /**
     * @return array{obras:array<string,float>, porMes:array<string,array<string,float>>, nombres:array<string,string>, detalle:int}
     */
    public function leer(string $ruta): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($ruta);

        $obras = []; $porMes = []; $nombres = []; $detalle = 0;

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, false, false);

            [$headerIdx, $col] = $this->mapearColumnas($rows);
            if ($headerIdx === null) {
                continue;
            }

            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $r  = $rows[$i];
                $un = trim((string) ($r[$col['un']] ?? ''));
                if ($un === '' || $this->norm($un) === 'GRAN TOTAL') {
                    continue;
                }

                $periodo = isset($col['fecha']) ? $this->periodo($r[$col['fecha']] ?? null) : null;
                $nit     = isset($col['nit']) ? trim((string) ($r[$col['nit']] ?? '')) : '';
                if ($periodo === null || $nit === '') {
                    continue; // subtotales: sin fecha o sin NIT
                }

                $neto = $this->netoFila($r, $col);
                $key  = $this->normCod($un);

                $obras[$key]   = ($obras[$key] ?? 0) + $neto;
                $nombres[$key] ??= $un;
                $ym = sprintf('%04d-%02d', $periodo[0], $periodo[1]);
                $porMes[$key][$ym] = ($porMes[$key][$ym] ?? 0) + $neto;
                $detalle++;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return ['obras' => $obras, 'porMes' => $porMes, 'nombres' => $nombres, 'detalle' => $detalle];
    }

    /** @return array{0:?int,1:array<string,int>} */
    private function mapearColumnas(array $rows): array
    {
        foreach ($rows as $i => $r) {
            $found = [];
            foreach ($r as $j => $cell) {
                $h = $this->norm((string) $cell);
                if ($h === '') continue;
                if ($h === 'UN' || (str_contains($h, 'UNIDAD') && str_contains($h, 'NEGOCIO'))) {
                    $found['un'] ??= $j;
                } elseif (str_contains($h, 'DEBITO')) {
                    $found['debitos'] ??= $j;
                } elseif (str_contains($h, 'CREDITO')) {
                    $found['creditos'] ??= $j;
                } elseif (str_contains($h, 'NETO')) {
                    $found['neto'] ??= $j;
                } elseif (str_contains($h, 'FECHA')) {
                    $found['fecha'] ??= $j;
                } elseif (str_contains($h, 'NIT')) {
                    $found['nit'] ??= $j;
                }
            }
            if (isset($found['un']) && (isset($found['neto']) || (isset($found['debitos']) && isset($found['creditos'])))) {
                return [$i, $found];
            }
        }
        return [null, []];
    }

    private function netoFila(array $r, array $col): float
    {
        if (isset($col['neto'])) {
            $n = $this->num($r[$col['neto']] ?? null);
            if (abs($n) > 0.0000001) {
                return $n;
            }
        }
        $deb = isset($col['debitos'])  ? $this->num($r[$col['debitos']]  ?? null) : 0.0;
        $cre = isset($col['creditos']) ? $this->num($r[$col['creditos']] ?? null) : 0.0;
        return $deb - $cre;
    }

    private function norm(string $s): string
    {
        $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U']);
        $s = str_replace('.', '', $s);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /** Clave de cruce de código de obra: sin espacios, mayúsculas. */
    public function normCod($s): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $s)));
    }

    /** [anio, mes] de una fecha (serial Excel, ISO o dd/mm/aaaa); null si no es fecha. */
    private function periodo($v): ?array
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            try {
                $d = ExcelDate::excelToDateTimeObject((float) $v);
                return [(int) $d->format('Y'), (int) $d->format('n')];
            } catch (\Throwable $e) { return null; }
        }
        $s = trim((string) $v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y'] as $f) {
            $d = \DateTime::createFromFormat($f, $s);
            $err = \DateTime::getLastErrors();
            $ok = $err === false || (empty($err['warning_count']) && empty($err['error_count']));
            if ($d !== false && $ok) return [(int) $d->format('Y'), (int) $d->format('n')];
        }
        return null;
    }

    private function num($v): float
    {
        if (is_numeric($v)) return (float) $v;
        $s = str_replace(['$', ' '], '', trim((string) $v));
        if ($s === '' || $s === '-') return 0.0;
        $neg = false;
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) { $neg = true; $s = substr($s, 1, -1); }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = (strrpos($s, ',') > strrpos($s, '.')) ? str_replace('.', '', $s) : str_replace(',', '', $s);
        }
        $s = str_replace(',', '.', $s);
        $n = is_numeric($s) ? (float) $s : 0.0;
        return $neg ? -$n : $n;
    }
}
