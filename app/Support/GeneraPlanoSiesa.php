<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generación del plano contable en el formato de 4 hojas que espera SIESA
 * (Documentocontable + Movimientocontable + MovimientoCxC + MovimientoCxP).
 * Compartido por el Plano de Cierre Contable y el Plano de Saldos contrarios (reversión)
 * para que AMBOS tengan exactamente las mismas columnas.
 */
trait GeneraPlanoSiesa
{
    /** Una línea del movimiento contable (las 12 columnas del detalle). */
    protected function filaPlano(int $numeroDoc, string $cuenta, ?string $tercero, string $obra, ?string $centroCostos, float $debito, float $credito, string $tipoDoc = 'CCC'): array
    {
        return [
            'tipo_doc'       => $tipoDoc,
            'numero_doc'     => $numeroDoc,
            'cuenta'         => $cuenta,
            'tercero'        => $tercero,
            'unidad'         => $obra,
            'centro_costos'  => $centroCostos,
            'flujo'          => null,
            'debito'         => round($debito, 2),
            'credito'        => round($credito, 2),
            'base_gravable'  => 0,
            'tipo_doc_banco' => null,
            'num_doc_banco'  => null,
        ];
    }

    /** Construye el xlsx de 4 hojas que espera SIESA y devuelve la ruta temporal. */
    protected function generarPlanoSiesa(array $movimientos, string $tipoDoc, string $nit, int $numeroDoc, string $fecha, string $observacion): string
    {
        $ss = new Spreadsheet();
        $ss->removeSheetByIndex(0);

        // Hoja 1: Documentocontable (cabecera del asiento)
        $h1 = $ss->createSheet();
        $h1->setTitle('Documentocontable');
        $this->escribirEncabezadosSiesa($h1, [
            'Tipo de documento',
            'Numero de documento',
            'Fecha del documento - El formato debe ser AAAAMMDD',
            'Tercero del documento',
            'Observaciones del documento',
        ]);
        $h1->fromArray([[$tipoDoc, $numeroDoc, $fecha, $nit, $observacion]], null, 'A2');

        // Hoja 2: Movimientocontable (las 12 columnas del detalle)
        $h2 = $ss->createSheet();
        $h2->setTitle('Movimientocontable');
        $fmt = ' - el formato debe ser (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)';
        $this->escribirEncabezadosSiesa($h2, [
            'Tipo de documento',
            'Numero de documento',
            'Auxiliar de cuenta contable',
            'Tercero',
            'Unidad de negocio',
            'Auxiliar de centro de costos',
            'Auxiliar de concepto de fuljo de efectivo',
            'Valor debito' . $fmt,
            'Valor credito' . $fmt,
            'Valor base gravable' . $fmt,
            'Tipo de documento de banco',
            'Numero de documento de banco',
        ]);

        $filas = [];
        foreach ($movimientos as $m) {
            $filas[] = [
                $m['tipo_doc'], $m['numero_doc'], $m['cuenta'], $m['tercero'], $m['unidad'],
                $m['centro_costos'], $m['flujo'], $m['debito'], $m['credito'],
                $m['base_gravable'], $m['tipo_doc_banco'], $m['num_doc_banco'],
            ];
        }
        $h2->fromArray($filas, null, 'A2');

        // Hojas 3 y 4: van vacias, pero SIESA las exige con sus encabezados
        $h3 = $ss->createSheet();
        $h3->setTitle('MovimientoCxC');
        $this->escribirEncabezadosSiesa($h3, [
            'Tipo de documento', 'Numero de documento', 'Auxiliar de cuenta contable', 'Tercero',
            'Unidad de negocio',
            'Valor debito  -  (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)',
            'Valor crédito - (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)',
            'Sucursal cliente', 'Tipo de documento de cruce', 'Numero de documento de cruce',
            'Fecha de vencimiento del documento - el formato debe ser AAAAMMDD',
            'Fecha de pronto pago del documento - el formato debe ser AAAAMMDD',
            'Tercero vendedor', 'Observaciones del movimiento de saldo abierto',
        ]);

        $h4 = $ss->createSheet();
        $h4->setTitle('MovimientoCxP');
        $this->escribirEncabezadosSiesa($h4, [
            'Tipo de documento', 'Numero de documento', 'Auxiliar de cuenta contable', 'Tercero',
            'Unidad de negocio',
            'Valor debito - (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)',
            'Valor crédito - (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)',
            'Sucursal proveedor',
            'Prefijo de documento de cruce - Es el prefijo del documento del proveedor, no se valida contra nada y puede dejarse vacío.',
            'Numero de documento de cruce', 'Auxiliar de concepto de fuljo de efectivo',
            'Fecha de vencimiento del documento - el formato debe ser AAAAMMDD.',
            'Fecha de pronto pago del documento - el formato debe ser AAAAMMDD',
            'Fecha del documento de cruce - el formato debe ser AAAAMMDD',
            'Observaciones del movimiento de saldo abierto',
        ]);

        $ss->setActiveSheetIndex(1);

        $tmp = storage_path('app/plano_' . uniqid() . '.xlsx');
        (new Xlsx($ss))->save($tmp);

        return $tmp;
    }

    private function escribirEncabezadosSiesa($hoja, array $encabezados): void
    {
        $col = 1;
        foreach ($encabezados as $e) {
            $hoja->setCellValue([$col, 1], $e);
            $hoja->getColumnDimensionByColumn($col)->setWidth(22);
            $col++;
        }
        $rango = 'A1:' . $hoja->getHighestColumn() . '1';
        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)
             ->getStartColor()->setRGB('F3F4F6');
    }

    protected function ultimoDiaDelMesSiesa(int $anio, int $mes): string
    {
        $dias = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
        return sprintf('%04d%02d%02d', $anio, $mes, $dias);
    }
}
