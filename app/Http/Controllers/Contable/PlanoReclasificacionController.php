<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * PLANO DE RECLASIFICACIÓN
 *
 * Cuando contabilidad cambia la cuenta 61 destino de una cuenta 14, el histórico NO se
 * reescribe: los costos que ya se asentaron siguen en la cuenta 61 anterior.
 *
 * Si al versionar se marcó "requiere plano de reclasificación", este módulo genera el
 * ASIENTO CORRECTIVO que mueve esos costos de la cuenta 61 vieja a la nueva:
 *
 *     Débito  → cuenta 61 NUEVA      (entra el costo)
 *     Crédito → cuenta 61 ANTERIOR   (sale el costo)
 *
 * El pasado no se altera: se corrige con un movimiento nuevo y trazable.
 *
 * OJO CON EL SIGNO: en registro_financieros los costos vienen con estado_er NEGATIVO
 * (por eso todo el sistema los envuelve en abs()). Por eso $invertir = true.
 *
 * IMPORTANTE: todas las consultas de este controlador usan sinFiltro(). La marca de
 * reclasificación vive en la VERSIÓN donde se pidió, y esa versión puede dejar de ser
 * la vigente si la cuenta se vuelve a versionar. Sin sinFiltro(), una reclasificación
 * pendiente desaparecería de la pantalla sin haberse hecho.
 */
class PlanoReclasificacionController extends Controller
{
    /**
     * COLUMNAS DEL PLANO. Mismo formato que el plano de reversión, para que SIESA
     * reciba siempre la misma estructura.
     */
    private array $columnas = [
        'cuenta'          => 'Cuenta',
        'nombre'          => 'Nombre cuenta',
        'codigo_proyecto' => 'Proyecto',
        'debito'          => 'Débito',
        'credito'         => 'Crédito',
    ];

    /** Si SIESA lo pide al revés, cambia a true. Único punto a tocar. */
    private bool $invertir = true;

    public function index()
    {
        // sinFiltro(): la versión que pidió la reclasificación puede ya no ser la vigente
        // (si la cuenta se volvió a versionar). Debe seguir apareciendo igual.
        $pendientes = Homologacion::sinFiltro()
            ->where('requiere_reclasificacion', true)
            ->whereNull('reclasificado_at')
            ->orderBy('cuenta_14')
            ->orderBy('version')
            ->get();

        $hechas = Homologacion::sinFiltro()
            ->where('requiere_reclasificacion', true)
            ->whereNotNull('reclasificado_at')
            ->orderByDesc('reclasificado_at')
            ->get();

        return view('contable.reclasificaciones', [
            'pendientes' => $pendientes->map(fn($h) => $this->resumenFila($h)),
            'hechas'     => $hechas->map(fn($h) => $this->resumenFila($h)),
        ]);
    }

    /**
     * Busca la homologación por id SIN el global scope.
     * El route model binding normal solo encuentra versiones vigentes, y aquí
     * necesitamos poder abrir también las históricas.
     */
    private function buscar(int $id): ?Homologacion
    {
        return Homologacion::sinFiltro()->find($id);
    }

    /** Detalle: muestra las líneas exactas que llevaría el plano. */
    public function detalle(int $id)
    {
        $homologacion = $this->buscar($id);
        if (!$homologacion) {
            return redirect()->route('contable.reclasificaciones.index')
                ->with('error', 'No encontré esa homologación.');
        }

        $ctx = $this->contexto($homologacion);
        if (is_string($ctx)) {
            return redirect()->route('contable.reclasificaciones.index')->with('error', $ctx);
        }

        $lineas = $this->construirLineas($ctx);

        return view('contable.reclasificacion-detalle', [
            'h'            => $homologacion,
            'c61Anterior'  => $ctx['c61Anterior'],
            'c61Nueva'     => $ctx['c61Nueva'],
            'desdeTexto'   => Homologacion::nombrePeriodo($ctx['desde']),
            'hastaTexto'   => Homologacion::nombrePeriodo($ctx['hasta']),
            'vigenteTexto' => Homologacion::nombrePeriodo($homologacion->vigente_desde),
            'lineas'       => $lineas,
            'totalDebito'  => array_sum(array_column($lineas, 'debito')),
            'totalCredito' => array_sum(array_column($lineas, 'credito')),
            'obras'        => count(array_unique(array_column($lineas, 'codigo_proyecto'))),
        ]);
    }

    public function descargar(int $id)
    {
        $homologacion = $this->buscar($id);
        if (!$homologacion) {
            return redirect()->route('contable.reclasificaciones.index')
                ->with('error', 'No encontré esa homologación.');
        }

        $ctx = $this->contexto($homologacion);
        if (is_string($ctx)) {
            return redirect()->route('contable.reclasificaciones.index')->with('error', $ctx);
        }

        $lineas = $this->construirLineas($ctx);

        if (empty($lineas)) {
            return back()->with('error',
                'No hay costos que reclasificar para ' . $homologacion->cuenta_14 . ' en el rango indicado. '
                . 'Revisa el período desde el que pediste reclasificar.');
        }

        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Reclasificación');

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

        $fila = 2;
        foreach ($lineas as $l) {
            $col = 1;
            foreach ($this->columnas as $key => $titulo) {
                $sheet->setCellValue([$col, $fila], $l[$key] ?? '');
                $col++;
            }
            $fila++;
        }

        $nombre = 'reclasificacion_' . $homologacion->cuenta_14 . '_' . date('Ymd_His') . '.xlsx';
        $tmp    = storage_path('app/' . $nombre);
        (new Xlsx($ss))->save($tmp);

        return response()->download($tmp, $nombre)->deleteFileAfterSend(true);
    }

    /**
     * Marca la reclasificación como hecha. Es un paso APARTE de la descarga:
     * contabilidad confirma cuando de verdad importó el plano en SIESA.
     */
    public function marcar(Request $request, int $id)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $homologacion = $this->buscar($id);
        if (!$homologacion) {
            return redirect()->route('contable.reclasificaciones.index')
                ->with('error', 'No encontré esa homologación.');
        }

        if (!$homologacion->requiere_reclasificacion) {
            return back()->with('error', 'Esta homologación no está marcada para reclasificación.');
        }
        if ($homologacion->reclasificado_at) {
            return back()->with('error', 'Esta reclasificación ya estaba marcada como hecha.');
        }

        $homologacion->reclasificado_at  = now();
        $homologacion->reclasificado_por = $request->user()?->id;
        $homologacion->save();

        return redirect()->route('contable.reclasificaciones.index')->with('success',
            'Reclasificación de ' . $homologacion->cuenta_14 . ' marcada como hecha.');
    }

    // ═════════════════════════ Interno ═════════════════════════

    /**
     * Reúne todo lo necesario para armar el plano de una homologación versionada.
     * Devuelve un array, o un string con el motivo por el que no se puede.
     */
    private function contexto(Homologacion $h): array|string
    {
        if (!$h->requiere_reclasificacion) {
            return 'La cuenta ' . $h->cuenta_14 . ' no está marcada para reclasificación.';
        }
        if (!$h->reclasificar_desde) {
            return 'La cuenta ' . $h->cuenta_14 . ' no tiene período de reclasificación definido.';
        }
        if (!$h->reemplaza_a) {
            return 'La cuenta ' . $h->cuenta_14 . ' no reemplaza a ninguna versión anterior: no hay nada que reclasificar.';
        }

        $anterior = Homologacion::sinFiltro()->find($h->reemplaza_a);
        if (!$anterior) {
            return 'No encontré la versión anterior de ' . $h->cuenta_14 . '.';
        }

        // Rango a corregir: desde lo que pidió contabilidad, hasta el último período en que
        // la cuenta vieja estuvo vigente (el mes anterior a la nueva).
        $desde = (int) $h->reclasificar_desde;
        $hasta = Homologacion::periodoAnterior((int) $h->vigente_desde);

        if ($desde > $hasta) {
            return 'El período de reclasificación de ' . $h->cuenta_14 . ' es posterior al rango corregible.';
        }

        return [
            'cuenta14'    => (string) $h->cuenta_14,
            'c61Anterior' => (string) $anterior->cuenta_61,
            'c61Nueva'    => (string) $h->cuenta_61,
            'desde'       => $desde,
            'hasta'       => $hasta,
        ];
    }

    /**
     * Busca los costos YA asentados en la cuenta 61 anterior que corresponden a esta
     * cuenta 14, dentro del rango. Se triangula por la 14 que viene al final de la
     * descripción, igual que hace el resumen de distribución.
     */
    private function construirLineas(array $ctx): array
    {
        // Si la 61 no cambió, no hay nada que mover.
        if ($ctx['c61Anterior'] === $ctx['c61Nueva']) {
            return [];
        }

        $nombres = RegistroFinanciero::selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')
            ->pluck('descripcion', 'cuenta_contable');

        $rows = RegistroFinanciero::where('cuenta_mayor', 'Costos aplicados')
            ->where('cuenta_contable', $ctx['c61Anterior'])
            ->whereRaw('(anio * 100 + mes) >= ?', [$ctx['desde']])
            ->whereRaw('(anio * 100 + mes) <= ?', [$ctx['hasta']])
            ->selectRaw('codigo_proyecto, descripcion, SUM(estado_er) as total')
            ->groupBy('codigo_proyecto', 'descripcion')
            ->get();

        // Quedarnos solo con las líneas cuya descripción apunta a NUESTRA cuenta 14,
        // y acumular por obra.
        $porObra = [];
        foreach ($rows as $r) {
            $c14 = $this->extraer14($r->descripcion);
            if ($c14 !== $ctx['cuenta14']) continue;

            $cod = (string) $r->codigo_proyecto;
            $porObra[$cod] = ($porObra[$cod] ?? 0) + (float) $r->total;
        }

        ksort($porObra);

        $nom61Ant = (string) ($nombres[$ctx['c61Anterior']] ?? '');
        $nom61New = (string) ($nombres[$ctx['c61Nueva']] ?? '');

        $lineas = [];
        foreach ($porObra as $cod => $saldo) {
            $saldo = round((float) $saldo, 2);
            if (abs($saldo) < 0.5) continue;   // nada material que mover

            $monto = abs($saldo);

            // Saca el saldo de la 61 vieja y mételo en la nueva.
            // Con saldo débito (lo normal en un costo): CR vieja / DB nueva.
            $debitarNueva = ($saldo > 0);
            if ($this->invertir) $debitarNueva = !$debitarNueva;

            if ($debitarNueva) {
                $lineas[] = $this->fila($ctx['c61Nueva'],    $nom61New, $cod, $monto, 0);
                $lineas[] = $this->fila($ctx['c61Anterior'], $nom61Ant, $cod, 0, $monto);
            } else {
                $lineas[] = $this->fila($ctx['c61Nueva'],    $nom61New, $cod, 0, $monto);
                $lineas[] = $this->fila($ctx['c61Anterior'], $nom61Ant, $cod, $monto, 0);
            }
        }

        return $lineas;
    }

    /** La cuenta 14 viene al final de la descripción del movimiento en cuenta 6. */
    private function extraer14(?string $desc): ?string
    {
        if (!$desc) return null;
        if (preg_match('/(\d{6,})\s*$/', trim($desc), $m)) return $m[1];
        return null;
    }

    private function fila(string $cuenta, string $nombre, string $cod, float $deb, float $cred): array
    {
        return [
            'cuenta'          => $cuenta,
            'nombre'          => $nombre,
            'codigo_proyecto' => $cod,
            'debito'          => $deb,
            'credito'         => $cred,
        ];
    }

    /** Fila resumida para la pantalla de listado (sin recalcular todo el plano). */
    private function resumenFila(Homologacion $h): array
    {
        $anterior = $h->reemplaza_a ? Homologacion::sinFiltro()->find($h->reemplaza_a) : null;

        return [
            'id'           => $h->id,
            'cuenta_14'    => (string) $h->cuenta_14,
            'nombre'       => (string) $h->nombre,
            'c61_anterior' => $anterior ? (string) $anterior->cuenta_61 : '—',
            'c61_nueva'    => (string) $h->cuenta_61,
            'version'      => $h->version,
            'desde'        => Homologacion::nombrePeriodo($h->reclasificar_desde),
            'hasta'        => Homologacion::nombrePeriodo(Homologacion::periodoAnterior((int) $h->vigente_desde)),
            'vigente'      => Homologacion::nombrePeriodo($h->vigente_desde),
            'motivo'       => (string) $h->motivo,
            'hecha_at'     => optional($h->reclasificado_at)->format('d/m/Y H:i'),

            // La cuenta se volvió a versionar después de pedir esta reclasificación.
            // La corrección sigue siendo válida (el rango es del pasado), pero conviene avisarlo.
            'superada'     => $h->vigente_hasta !== null,
        ];
    }
}