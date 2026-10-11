<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\FichaProyecto;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use App\Models\User;
use App\Support\GeneraPlanoSiesa;
use Illuminate\Http\Request;

/**
 * PLANO DE RECLASIFICACIÓN DE SALDOS DE CUENTA 14 AL COSTO (cierre de proyectos).
 *
 * Toma el saldo pendiente de la cuenta 14 de cada proyecto, desglosado por (proyecto, cuenta,
 * tercero) en Libro 1 PCGA (débito − crédito, con pesos y centavos) y genera el asiento que lo
 * reclasifica al costo:
 *
 *     Crédito → cuenta 14            (sale el pendiente)
 *     Débito  → cuenta 61 homologada (entra al costo), con centro de costos del departamento
 *
 * Para los saldos contrarios (a favor, débito − crédito < 0) la partida se invierte (DB 14 / CR 61)
 * para que la cuenta 14 quede en $0 igual. El plano completo cuadra a $0 (cada saldo aporta un par
 * débito/crédito del mismo monto). Documento tipo CCC, descargable en el formato de 4 hojas de SIESA.
 *
 * Se puede acotar por departamento, por proyectos concretos (los que contabilidad elige cerrar) y
 * por un monto mínimo de saldo por proyecto. La lógica es la misma, ya probada, del plano de
 * residuos; aquí queda incorporada como función del sistema.
 */
class PlanoCierre14Controller extends Controller
{
    use GeneraPlanoSiesa;

    /** NIT de SECAR: tercero de la cabecera del documento. */
    private const NIT_SECAR = '890319324';

    /** Tipo de documento en SIESA. Fijo. */
    private const TIPO_DOC = 'CCC';

    /** Centro de costos por departamento. Solo va en las líneas de cuenta 6. */
    private const CENTRO_COSTOS = [
        'mantenimiento' => '30020105',
        'instalaciones' => '30010103',
    ];

    private const MESES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO',
        7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    /**
     * Descarga el plano SIESA. Filtros (todos opcionales salvo el período):
     *   mes, anio         → corte acumulado del saldo.
     *   departamento      → mantenimiento | instalaciones.
     *   codigos[]         → proyectos concretos a cerrar (si se omite, todos los inactivos con saldo).
     *   monto             → saldo mínimo (|débito − crédito| del proyecto) para incluirlo.
     *   documento         → número del documento contable (CCC). Por defecto 1.
     *   observacion       → observación del asiento.
     */
    public function descargar(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para generar el plano de reclasificación.');

        [$mesDef, $anioDef] = $this->ultimoPeriodoConDatos();
        $mes   = (int) $request->get('mes', $mesDef);
        $anio  = (int) $request->get('anio', $anioDef);
        $depto = $request->get('departamento');
        $codigos = array_values(array_filter(array_map(
            fn ($c) => strtoupper(trim((string) $c)),
            (array) $request->get('codigos', [])
        )));
        $montoMin = max(0.0, (float) $request->get('monto', 0));

        $resultado = $this->construirMovimientos($mes, $anio, $u, $depto, $codigos, $montoMin);
        $movimientos = $resultado['movimientos'];

        if (empty($movimientos)) {
            $motivo = $resultado['sin_homologar']
                ? 'Los proyectos seleccionados solo tienen saldo en cuentas 14 sin homologar a una cuenta 61. '
                    . 'Homológalas primero en «Homologaciones» para poder reclasificarlas.'
                : 'No hay saldos de cuenta 14 que reclasificar con los filtros indicados.';
            return back()->with('error', $motivo);
        }

        $numeroDoc   = max(1, (int) $request->get('documento', 1));
        $fecha       = $this->ultimoDiaDelMesSiesa($anio, $mes);
        $observacion = trim((string) $request->get('observacion', ''))
            ?: ('RECLASIFICACION SALDOS CUENTA 14 A COSTO - CIERRE PROYECTOS '
                . ($depto ? mb_strtoupper($depto) . ' ' : '')
                . (self::MESES[$mes] ?? '') . ' ' . $anio);

        // Reescribe el número de documento en todas las líneas (filaPlano lo fija por fila).
        foreach ($movimientos as &$m) {
            $m['numero_doc'] = $numeroDoc;
        }
        unset($m);

        $archivo = $this->generarPlanoSiesa($movimientos, self::TIPO_DOC, self::NIT_SECAR, $numeroDoc, $fecha, $observacion);

        $nombre = 'PLANO_CIERRE_14_' . ($depto ? mb_strtoupper($depto) . '_' : '')
            . (self::MESES[$mes] ?? '') . '_' . $anio . '_' . date('Ymd_His') . '.xlsx';

        return response()->download($archivo, $nombre)->deleteFileAfterSend(true);
    }

    /**
     * Construye las líneas (movimientos) del plano a partir de los saldos pendientes de cuenta 14.
     * Devuelve ['movimientos' => array, 'sin_homologar' => int, 'total_debito' => float,
     *           'total_credito' => float]. Público para poder probarlo.
     *
     * @param  array<int,string>  $codigos  proyectos concretos (vacío = todos los inactivos con saldo)
     */
    public function construirMovimientos(int $mes, int $anio, ?User $u, ?string $departamento, array $codigos = [], float $montoMin = 0.0): array
    {
        $periodo = Homologacion::periodo($anio, $mes);
        $homol   = Homologacion::mapaEn($periodo);

        // Saldo pendiente por (proyecto, cuenta 14, tercero) en Libro 1 = débito − crédito,
        // al corte acumulado del mes/año. Se conservan pesos y centavos.
        $filas = RegistroFinanciero::where('cuenta_mayor', 'Costos por aplicar')
            ->where(function ($q) use ($anio, $mes) {
                $q->where('anio', '<', $anio)
                  ->orWhere(fn ($x) => $x->where('anio', $anio)->where('mes', '<=', $mes));
            })
            ->when(! empty($codigos), fn ($q) => $q->whereIn('codigo_proyecto', $codigos))
            ->selectRaw('codigo_proyecto, cuenta_contable, tercero_dcto, '
                . 'MAX(razon_social) as razon_social, '
                . 'SUM(valor_debito) as deb, SUM(valor_credito) as cred')
            ->groupBy('codigo_proyecto', 'cuenta_contable', 'tercero_dcto')
            ->havingRaw('ABS(SUM(valor_debito) - SUM(valor_credito)) > 0.005')
            ->get();

        // Proyectos permitidos: filtro por departamento (elegido o del perfil). Si no se eligieron
        // códigos, además se limita a proyectos INACTIVOS con saldo (lo que lista la pantalla).
        $permitidos = $this->proyectosPermitidos($filas->pluck('codigo_proyecto')->unique()->all(), $u, $departamento);
        if (empty($codigos)) {
            $permitidos = array_values(array_intersect($permitidos, $this->proyectosInactivos($permitidos)));
        }
        $permitidos = array_flip($permitidos);

        // Saldo total por proyecto (para el filtro de monto mínimo).
        $totalProyecto = [];
        foreach ($filas as $f) {
            $cod = (string) $f->codigo_proyecto;
            if (! isset($permitidos[$cod])) continue;
            $totalProyecto[$cod] = ($totalProyecto[$cod] ?? 0.0) + ((float) $f->deb - (float) $f->cred);
        }

        $movimientos  = [];
        $sinHomologar = 0;
        $totalDeb = 0.0; $totalCred = 0.0;

        foreach ($filas as $f) {
            $cod = (string) $f->codigo_proyecto;
            if (! isset($permitidos[$cod])) continue;
            if ($montoMin > 0 && abs((float) ($totalProyecto[$cod] ?? 0)) + 0.005 < $montoMin) continue;

            $saldo = round((float) $f->deb - (float) $f->cred, 2); // Libro 1 PCGA
            if (abs($saldo) < 0.005) continue;

            $c14 = (string) $f->cuenta_contable;
            $c61 = (string) ($homol[$c14]->cuenta_61 ?? '');
            if ($c61 === '' || $c61 === 'SIN HOMOLOGAR') {
                $sinHomologar++;
                continue;
            }

            $tercero = trim((string) $f->tercero_dcto) ?: null;
            $cc      = self::CENTRO_COSTOS[User::departamentoDeCodigo($cod)] ?? null;
            $monto   = abs($saldo);

            if ($saldo > 0) {
                // Costo pendiente: saca de la 14 (crédito) y reconócelo en la 61 (débito).
                $movimientos[] = $this->filaPlano(0, $c14, $tercero, $cod, null, 0, $monto);
                $movimientos[] = $this->filaPlano(0, $c61, $tercero, $cod, $cc, $monto, 0);
            } else {
                // Saldo a favor (contrario): invierte para dejar la 14 en $0.
                $movimientos[] = $this->filaPlano(0, $c14, $tercero, $cod, null, $monto, 0);
                $movimientos[] = $this->filaPlano(0, $c61, $tercero, $cod, $cc, 0, $monto);
            }
            $totalDeb += $monto;
            $totalCred += $monto;
        }

        // Orden estable: por proyecto y cuenta, para que el plano sea legible y reproducible.
        usort($movimientos, fn ($a, $b) => [$a['unidad'], $a['cuenta']] <=> [$b['unidad'], $b['cuenta']]);

        return [
            'movimientos'   => $movimientos,
            'sin_homologar' => $sinHomologar,
            'total_debito'  => round($totalDeb, 2),
            'total_credito' => round($totalCred, 2),
        ];
    }

    // ═════════════════════════ Interno ═════════════════════════

    /** Acota una lista de códigos al departamento elegido / permitido del usuario (por prefijo). */
    private function proyectosPermitidos(array $codigos, ?User $u, ?string $departamentoElegido): array
    {
        $depUsuario  = $u?->departamentoUnico();
        $depEfectivo = $depUsuario ?: ($departamentoElegido ?: null);

        if ($depEfectivo && in_array($depEfectivo, ['mantenimiento', 'instalaciones'], true)) {
            $prefijos = User::prefijosDeDepartamento($depEfectivo);
        } elseif ($u && $u->tieneFiltroDepartamento()) {
            $prefijos = $u->departamentosPermitidos();
        } else {
            return array_values($codigos); // admin sin filtro: todos
        }
        $prefijos = array_filter(array_map('strtoupper', $prefijos));

        return array_values(array_filter($codigos, function ($cod) use ($prefijos) {
            $cod = strtoupper((string) $cod);
            foreach ($prefijos as $p) {
                if (str_starts_with($cod, $p)) return true;
            }
            return false;
        }));
    }

    /** De una lista de códigos, los que tienen ficha INACTIVA (activa = false). */
    private function proyectosInactivos(array $codigos): array
    {
        if (empty($codigos)) return [];

        return FichaProyecto::whereIn('codigo_proyecto', $codigos)
            ->where('activa', false)
            ->pluck('codigo_proyecto')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    /** Último período (mes, año) con información cargada en RegistroFinanciero (BIABLE). */
    private function ultimoPeriodoConDatos(): array
    {
        $row = RegistroFinanciero::orderByDesc('anio')->orderByDesc('mes')->first(['anio', 'mes']);

        return $row ? [(int) $row->mes, (int) $row->anio] : [(int) date('n'), (int) date('Y')];
    }
}
