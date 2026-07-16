<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\AplicacionCosto;
use App\Models\ObraEstado;
use App\Models\Distribucion;
use App\Models\User;
use App\Services\RepartoFifoTerceros;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PlanoContableController extends Controller
{
    /** NIT de SECAR: es el tercero de la cabecera del documento. */
    private const NIT_SECAR = '890319324';

    /** Tipo de documento en SIESA. Fijo. */
    private const TIPO_DOC = 'CCC';

    /** Centro de costos por departamento. Solo va en las lineas de cuenta 6. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    /** Contrapartida de las provisiones (costo en transito). */
    private const CUENTA_PROVISION = '26050604';

    private const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    public function index(Request $request)
    {
        $mes  = (int) $request->get('mes', date('n'));
        $anio = (int) $request->get('anio', date('Y'));

        $aplican  = ObraEstado::whereIn('estado', ['cerrada', 'parcial'])->pluck('codigo_proyecto');
        $usuarios = User::pluck('name', 'id');

        $versiones = Distribucion::where('mes', $mes)->where('anio', $anio)
            ->where('estado', 'enviado')
            ->orderBy('departamento')->orderByDesc('version')->get();

        $data = $versiones->map(function ($d) use ($aplican, $usuarios) {
            $lineas = AplicacionCosto::where('distribucion_id', $d->id)
                ->whereIn('codigo_proyecto', $aplican)
                ->where('monto_aplicar', '>', 0)
                ->orderBy('codigo_proyecto')->get();
            return [
                'id'           => $d->id,
                'version'      => $d->version,
                'departamento' => $d->departamento,
                'enviado_at'   => $d->enviado_at,
                'enviado_por'  => $usuarios[$d->enviado_por] ?? '-',
                'habilitada'   => $d->edicion_habilitada,
                'lineas'       => $lineas,
                'total'        => $lineas->sum('monto_aplicar'),
                'obras'        => $lineas->pluck('codigo_proyecto')->unique()->count(),
            ];
        });

        return view('contable.plano-contable', compact('mes', 'anio', 'data'));
    }

    /**
     * Genera el archivo de importacion de SIESA: CUATRO hojas.
     * El tercero de cada linea NO es SECAR: es el PROVEEDOR al que se le compro,
     * resuelto por FIFO contra las lineas de la cuenta 14.
     */
    public function exportarPlano(Request $request, Distribucion $distribucion)
    {
        $datos = $request->validate([
            'documento'   => ['required', 'integer', 'min:1'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ], [
            'documento.required' => 'Contabilidad debe indicar el numero de documento del asiento.',
        ]);

        $numeroDoc = (int) $datos['documento'];
        $mes       = (int) $distribucion->mes;
        $anio      = (int) $distribucion->anio;
        $depto     = (string) $distribucion->departamento;

        $centroCostos = self::CENTRO_COSTOS[$depto] ?? null;
        if ($centroCostos === null) {
            return back()->with('error', "No se que centro de costos usar para el departamento '{$depto}'.");
        }

        $aplican = ObraEstado::whereIn('estado', ['cerrada', 'parcial'])->pluck('codigo_proyecto');

        $lineas = AplicacionCosto::where('distribucion_id', $distribucion->id)
            ->whereIn('codigo_proyecto', $aplican)
            ->where('monto_aplicar', '>', 0)
            ->orderBy('codigo_proyecto')
            ->orderBy('cuenta_14')
            ->get();

        if ($lineas->isEmpty()) {
            return back()->with('error', 'Esta version no tiene lineas para exportar.');
        }

        $obras   = $lineas->pluck('codigo_proyecto')->unique()->values()->all();
        $reparto = new RepartoFifoTerceros($obras);

        $movimientos = $this->construirMovimientos($lineas, $reparto, $numeroDoc, $centroCostos);

        // Control de cuadre: si no cuadra, no se exporta.
        $debito  = round(array_sum(array_column($movimientos, 'debito')), 2);
        $credito = round(array_sum(array_column($movimientos, 'credito')), 2);

        if (abs($debito - $credito) > 0.5) {
            return back()->with('error',
                'El plano NO cuadra: debito ' . number_format($debito, 2, ',', '.') .
                ' vs credito ' . number_format($credito, 2, ',', '.') .
                '. No se genero el archivo.');
        }

        $fecha       = $this->ultimoDiaDelMes($anio, $mes);
        $observacion = $datos['observacion']
            ?? 'CIERRE DE COSTOS OBRAS ' . mb_strtoupper($depto) . ' MES DE ' . (self::MESES[$mes] ?? '') . ' ' . $anio;

        $archivo = $this->generarExcel($movimientos, $numeroDoc, $fecha, $observacion);

        $nombre = 'PLANO_' . mb_strtoupper($depto) . '_' . (self::MESES[$mes] ?? '') . '_' . $anio
                . '_v' . $distribucion->version . '.xlsx';

        return response()->download($archivo, $nombre)->deleteFileAfterSend(true);
    }

    /**
     * Arma las lineas del movimiento contable.
     *   Costo normal -> CR cuenta 14 / DB cuenta 61, ambas con el tercero del proveedor.
     *   Provision    -> CR 26050604 (sin tercero) / DB cuenta 61 (tercero SECAR).
     * El centro de costos va SOLO en las lineas de cuenta 6.
     */
    private function construirMovimientos($lineas, RepartoFifoTerceros $reparto, int $numeroDoc, string $centroCostos): array
    {
        $porObra = $lineas->groupBy('codigo_proyecto');
        $mov = [];

        foreach ($porObra as $obra => $items) {
            $creditos = [];
            $debitos  = [];

            foreach ($items as $l) {
                $monto = round((float) $l->monto_aplicar, 2);
                $c14   = (string) $l->cuenta_14;
                $c61   = (string) $l->cuenta_61;

                if ($c61 === 'SIN HOMOLOGAR' || $c61 === '') {
                    continue;   // no se puede mandar a SIESA sin cuenta destino
                }

                if ($l->es_provision) {
                    $creditos[] = $this->fila($numeroDoc, self::CUENTA_PROVISION, null, (string) $obra, null, 0, $monto);
                    $debitos[]  = $this->fila($numeroDoc, $c61, self::NIT_SECAR, (string) $obra, $centroCostos, $monto, 0);
                    continue;
                }

                // Costo que viene de una bolsa de area: se ACREDITA la cuenta 14 en la OT
                // de la bolsa (origen) y se DEBITA la cuenta 61 en la OT de la obra destino,
                // con su centro de costos. Son bolsas internas: el tercero es SECAR.
                if (!empty($l->origen_bolsa)) {
                    $creditos[] = $this->fila($numeroDoc, $c14, self::NIT_SECAR, (string) $l->origen_bolsa, null, 0, $monto);
                    $debitos[]  = $this->fila($numeroDoc, $c61, self::NIT_SECAR, (string) $obra, $centroCostos, $monto, 0);
                    continue;
                }

                foreach ($reparto->repartir((string) $obra, $c14, $monto) as $p) {
                    $creditos[] = $this->fila($numeroDoc, $c14, $p['tercero'], (string) $obra, null, 0, $p['monto']);
                    $debitos[]  = $this->fila($numeroDoc, $c61, $p['tercero'], (string) $obra, $centroCostos, $p['monto'], 0);
                }
            }

            // Mismo orden que el plano que hoy usa contabilidad: primero las 14, luego las 61.
            foreach ($creditos as $f) $mov[] = $f;
            foreach ($debitos  as $f) $mov[] = $f;
        }

        return $mov;
    }

    private function fila(int $numeroDoc, string $cuenta, ?string $tercero, string $obra, ?string $centroCostos, float $debito, float $credito): array
    {
        return [
            'tipo_doc'       => self::TIPO_DOC,
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

    /** Construye el xlsx de 4 hojas que espera SIESA. */
    private function generarExcel(array $movimientos, int $numeroDoc, string $fecha, string $observacion): string
    {
        $ss = new Spreadsheet();
        $ss->removeSheetByIndex(0);

        // Hoja 1: Documentocontable (cabecera del asiento)
        $h1 = $ss->createSheet();
        $h1->setTitle('Documentocontable');
        $this->escribirEncabezados($h1, [
            'Tipo de documento',
            'Numero de documento',
            'Fecha del documento - El formato debe ser AAAAMMDD',
            'Tercero del documento',
            'Observaciones del documento',
        ]);
        $h1->fromArray([[self::TIPO_DOC, $numeroDoc, $fecha, self::NIT_SECAR, $observacion]], null, 'A2');

        // Hoja 2: Movimientocontable (las 12 columnas del detalle)
        $h2 = $ss->createSheet();
        $h2->setTitle('Movimientocontable');
        $fmt = ' - el formato debe ser (signo + 15 enteros + punto + 4 decimales) (+000000000000000.0000)';
        $this->escribirEncabezados($h2, [
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
        $this->escribirEncabezados($h3, [
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
        $this->escribirEncabezados($h4, [
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

    private function escribirEncabezados($hoja, array $encabezados): void
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

    private function ultimoDiaDelMes(int $anio, int $mes): string
    {
        $dias = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));
        return sprintf('%04d%02d%02d', $anio, $mes, $dias);
    }

    public function habilitar(Distribucion $distribucion)
    {
        abort_unless(auth()->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $distribucion->edicion_habilitada = !$distribucion->edicion_habilitada;
        $distribucion->save();

        \App\Models\DistribucionVersion::create([
            'distribucion_id' => $distribucion->id,
            'evento'          => $distribucion->edicion_habilitada ? 'reabierto' : 'bloqueado',
            'user_id'         => auth()->id(),
            'user_nombre'     => auth()->user()?->name,
            'snapshot'        => null,
        ]);

        $msg = $distribucion->edicion_habilitada
            ? "Version {$distribucion->version} habilitada: operaciones ya puede editarla."
            : "Version {$distribucion->version} bloqueada de nuevo.";
        return back()->with('success', $msg);
    }
}