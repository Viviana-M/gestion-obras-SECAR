<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\Homologacion;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

class HomologacionController extends Controller
{
    /**
     * Estructuras válidas. DEBEN coincidir exactamente con las claves de
     * $categorias en DistribucionCostosController; si no coinciden, la cuenta
     * se clasifica como "OTROS COSTO" en la distribución sin avisar.
     */
    public const ESTRUCTURAS = [
        'EQU-MAT-SUM' => 'Equipos y materiales',
        'MOI'         => 'M.O. interna',
        'MOE'         => 'M.O. externa',
        'OTROS COSTO' => 'Otros costos',
        'MOFIJAOPER'  => 'M.O. fija (supervisores)',
    ];

    public function index()
    {
        // El global scope del modelo deja solo las versiones vigentes.
        $homologaciones = Homologacion::orderBy('cuenta_14')->get();

        // Histórico completo, agrupado por cuenta, para el modal de trazabilidad.
        $historial = Homologacion::sinFiltro()
            ->orderBy('cuenta_14')
            ->orderByDesc('version')
            ->get()
            ->groupBy(fn($h) => (string) $h->cuenta_14)
            ->map(fn($grupo) => $grupo->map(fn($h) => [
                'version'      => $h->version,
                'cuenta_61'    => $h->cuenta_61,
                'nombre'       => $h->nombre,
                'estructura'   => $h->estructura,
                'rango'        => $h->rangoVigencia(),
                'vigente'      => $h->esVigente(),
                'motivo'       => $h->motivo,
                'reclasif'     => (bool) $h->requiere_reclasificacion,
                'reclas_desde' => $h->reclasificar_desde ? Homologacion::nombrePeriodo($h->reclasificar_desde) : null,
                'creado'       => optional($h->created_at)->format('d/m/Y H:i'),
            ])->values());

        $ultimoEnviado = Homologacion::ultimoPeriodoEnviado();
        $periodoMinimo = Homologacion::periodoMinimoPermitido();

        return view('contable.homologaciones', [
            'homologaciones'  => $homologaciones,
            'historial'       => $historial,
            'cuentas14'       => $this->cuentasConocidas('Costos por aplicar'),
            'cuentas61'       => $this->cuentasConocidas('Costos aplicados', $homologaciones),
            'estructuras'     => self::ESTRUCTURAS,
            'periodoMinMes'   => Homologacion::mesDe($periodoMinimo),
            'periodoMinAnio'  => Homologacion::anioDe($periodoMinimo),
            'periodoMinTexto' => Homologacion::nombrePeriodo($periodoMinimo),
            'ultimoEnvTexto'  => $ultimoEnviado ? Homologacion::nombrePeriodo($ultimoEnviado) : null,
        ]);
    }

    /**
     * Cuentas que el sistema ya ha visto en los movimientos cargados de SIESA.
     * No son lista cerrada: sirven para sugerir y para avisar si se escribe
     * una cuenta desconocida.
     *
     * @return array<string,string>  cuenta => descripción
     */
    private function cuentasConocidas(string $cuentaMayor, $homologaciones = null): array
    {
        $lista = RegistroFinanciero::where('cuenta_mayor', $cuentaMayor)
            ->selectRaw('cuenta_contable, MAX(descripcion) as descripcion')
            ->groupBy('cuenta_contable')
            ->orderBy('cuenta_contable')
            ->get()
            ->mapWithKeys(fn($r) => [
                (string) $r->cuenta_contable => trim((string) $r->descripcion),
            ])
            ->toArray();

        if ($homologaciones !== null) {
            foreach ($homologaciones as $h) {
                $c61 = (string) $h->cuenta_61;
                if ($c61 !== '' && !isset($lista[$c61])) {
                    $lista[$c61] = (string) $h->nombre;
                }
            }
            ksort($lista);
        }

        return $lista;
    }

    private function normalizarEstructura($v): ?string
    {
        if ($v === null) return null;
        $s = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $v)));
        return $s === '' ? null : $s;
    }

    /** Valida el período de vigencia contra los planos ya enviados a contabilidad. */
    private function validarPeriodo(int $vigenteDesde, ?Homologacion $actual = null): ?string
    {
        $minimo = Homologacion::periodoMinimoPermitido();

        if ($vigenteDesde < $minimo) {
            $ult = Homologacion::ultimoPeriodoEnviado();
            return 'No puedes aplicar el cambio desde ' . Homologacion::nombrePeriodo($vigenteDesde)
                 . ': el período ' . Homologacion::nombrePeriodo($ult)
                 . ' ya fue enviado a contabilidad. El cambio puede aplicar desde '
                 . Homologacion::nombrePeriodo($minimo) . ' en adelante.';
        }

        if ($actual !== null && $vigenteDesde <= $actual->vigente_desde) {
            return 'El nuevo período de vigencia (' . Homologacion::nombrePeriodo($vigenteDesde)
                 . ') debe ser posterior al de la versión actual ('
                 . Homologacion::nombrePeriodo($actual->vigente_desde) . ').';
        }

        return null;
    }

    // ═════════════════════════════ CREAR ═════════════════════════════

    public function guardar(Request $request)
    {
        $data = $request->validate(
            [
                'cuenta_14'  => ['required', 'string', 'max:255', 'regex:/^14\d+$/'],
                'cuenta_61'  => ['required', 'string', 'max:255', 'regex:/^6\d+$/'],
                'nombre'     => ['nullable', 'string'],
                'estructura' => ['required', Rule::in(array_keys(self::ESTRUCTURAS))],
            ],
            [
                'cuenta_14.regex' => 'La cuenta de inventario en obra debe empezar por 14 y contener solo números.',
                'cuenta_61.regex' => 'La cuenta de costo debe empezar por 6 y contener solo números.',
                'estructura.in'   => 'La estructura debe ser una de las cinco válidas del sistema.',
            ]
        );

        $existe = Homologacion::sinFiltro()->where('cuenta_14', $data['cuenta_14'])->exists();
        if ($existe) {
            return back()
                ->with('error', 'La cuenta ' . $data['cuenta_14'] . ' ya está homologada. Usa el botón Editar en su fila para crear una versión nueva.')
                ->withInput();
        }

        // Una cuenta nueva se homologa "desde el inicio": así clasifica todo su histórico.
        Homologacion::create([
            'cuenta_14'     => $data['cuenta_14'],
            'cuenta_61'     => $data['cuenta_61'],
            'nombre'        => $data['nombre'] ?? null,
            'estructura'    => $data['estructura'],
            'vigente_desde' => Homologacion::PERIODO_INICIAL,
            'vigente_hasta' => null,
            'version'       => 1,
            'motivo'        => 'Homologación inicial',
            'origen'        => 'manual',
            'user_id'       => $request->user()?->id,
        ]);

        return back()->with('success',
            'Homologación creada: ' . $data['cuenta_14'] . ' → ' . $data['cuenta_61'] . ' (v1, aplica desde el inicio).');
    }

    // ═══════════════════════════ VERSIONAR ═══════════════════════════

    /**
     * NO sobrescribe. Cierra la versión vigente y crea una nueva a partir del
     * período indicado. El histórico queda intacto.
     */
    public function actualizar(Request $request, Homologacion $homologacion)
    {
        $data = $request->validate(
            [
                'cuenta_61'                => ['required', 'string', 'max:255', 'regex:/^6\d+$/'],
                'nombre'                   => ['nullable', 'string'],
                'estructura'               => ['required', Rule::in(array_keys(self::ESTRUCTURAS))],
                'vigente_mes'              => ['required', 'integer', 'between:1,12'],
                'vigente_anio'             => ['required', 'integer', 'between:2000,2100'],
                'motivo'                   => ['required', 'string', 'min:5', 'max:1000'],
                'requiere_reclasificacion' => ['nullable', 'boolean'],
                'reclas_mes'               => ['nullable', 'integer', 'between:1,12'],
                'reclas_anio'              => ['nullable', 'integer', 'between:2000,2100'],
            ],
            [
                'cuenta_61.regex' => 'La cuenta de costo debe empezar por 6 y contener solo números.',
                'estructura.in'   => 'La estructura debe ser una de las cinco válidas del sistema.',
                'motivo.required' => 'Explica por qué se cambia la homologación: queda en la trazabilidad.',
                'motivo.min'      => 'El motivo es muy corto. Escribe algo que se entienda dentro de seis meses.',
            ]
        );

        $vigenteDesde = Homologacion::periodo((int) $data['vigente_anio'], (int) $data['vigente_mes']);

        if ($err = $this->validarPeriodo($vigenteDesde, $homologacion)) {
            return back()->with('error', $err)->withInput();
        }

        $sinCambios = (string) $homologacion->cuenta_61  === (string) $data['cuenta_61']
                   && (string) $homologacion->estructura === (string) $data['estructura']
                   && (string) $homologacion->nombre     === (string) ($data['nombre'] ?? '');

        if ($sinCambios) {
            return back()->with('error', 'No hay nada que cambiar: los datos son idénticos a la versión vigente.');
        }

        $requiereReclas    = (bool) ($data['requiere_reclasificacion'] ?? false);
        $reclasificarDesde = null;

        if ($requiereReclas) {
            if (empty($data['reclas_mes']) || empty($data['reclas_anio'])) {
                return back()->with('error', 'Marcaste que requiere plano de reclasificación: indica desde qué mes y año.')->withInput();
            }
            $reclasificarDesde = Homologacion::periodo((int) $data['reclas_anio'], (int) $data['reclas_mes']);

            if ($reclasificarDesde >= $vigenteDesde) {
                return back()->with('error',
                    'La reclasificación corrige el pasado: debe empezar ANTES de ' . Homologacion::nombrePeriodo($vigenteDesde) . '.'
                )->withInput();
            }
        }

        $nuevaVersion = $homologacion->version + 1;
        $cuenta       = $homologacion->cuenta_14;
        $c61Anterior  = $homologacion->cuenta_61;

        DB::transaction(function () use ($homologacion, $data, $vigenteDesde, $requiereReclas, $reclasificarDesde, $request, $nuevaVersion) {
            // Paso 1: cerrar la versión vigente el período anterior al nuevo.
            $homologacion->vigente_hasta = Homologacion::periodoAnterior($vigenteDesde);
            $homologacion->save();

            // Paso 2: crear la versión nueva.
            Homologacion::create([
                'cuenta_14'                => $homologacion->cuenta_14,
                'cuenta_61'                => $data['cuenta_61'],
                'nombre'                   => $data['nombre'] ?? null,
                'estructura'               => $data['estructura'],
                'vigente_desde'            => $vigenteDesde,
                'vigente_hasta'            => null,
                'version'                  => $nuevaVersion,
                'reemplaza_a'              => $homologacion->id,
                'motivo'                   => $data['motivo'],
                'requiere_reclasificacion' => $requiereReclas,
                'reclasificar_desde'       => $reclasificarDesde,
                'origen'                   => 'manual',
                'user_id'                  => $request->user()?->id,
            ]);
        });

        $msg = 'Nueva versión de ' . $cuenta . ' (v' . $nuevaVersion . '): '
             . $c61Anterior . ' → ' . $data['cuenta_61'] . ', '
             . 'vigente desde ' . Homologacion::nombrePeriodo($vigenteDesde) . '. '
             . 'El histórico anterior no se modificó.';

        if ($requiereReclas) {
            $msg .= ' Queda marcada para plano de reclasificación desde '
                  . Homologacion::nombrePeriodo($reclasificarDesde) . '.';
        }

        return back()->with('success', $msg);
    }

    // ═══════════════════════════ ELIMINAR ═══════════════════════════

    /**
     * Borra TODAS las versiones de la cuenta. Solo para cuentas creadas por error:
     * si la cuenta ya se usó en distribuciones pasadas, se pierde su clasificación.
     */
    public function eliminar(Homologacion $homologacion)
    {
        $cuenta = $homologacion->cuenta_14;

        $n = Homologacion::sinFiltro()->where('cuenta_14', $cuenta)->count();
        Homologacion::sinFiltro()->where('cuenta_14', $cuenta)->delete();

        return back()->with('success',
            'Homologación eliminada: ' . $cuenta . ' (' . $n . ' versi' . ($n === 1 ? 'ón' : 'ones') . ').');
    }

    // ═══════════════════════════ IMPORTAR ═══════════════════════════

    public function importar(Request $request)
    {
        $request->validate([
            'archivo'      => 'required|file|mimes:xlsx,xls|max:51200',
            'vigente_mes'  => 'required|integer|between:1,12',
            'vigente_anio' => 'required|integer|between:2000,2100',
        ], [
            'vigente_mes.required'  => 'Indica desde qué mes aplican los cambios del archivo.',
            'vigente_anio.required' => 'Indica desde qué año aplican los cambios del archivo.',
        ]);

        $vigenteDesde = Homologacion::periodo((int) $request->vigente_anio, (int) $request->vigente_mes);

        if ($err = $this->validarPeriodo($vigenteDesde)) {
            return back()->with('error', $err);
        }

        $dir = storage_path('app/homologaciones');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nombre = uniqid() . '.' . $request->file('archivo')->getClientOriginalExtension();
        $request->file('archivo')->move($dir, $nombre);
        $full = $dir . '/' . $nombre;

        $reader = IOFactory::createReaderForFile($full);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($full);

        $hojaObjetivo = null;
        foreach ($spreadsheet->getSheetNames() as $sn) {
            if (stripos($sn, 'plan de cuenta') !== false) { $hojaObjetivo = $sn; break; }
        }
        if ($hojaObjetivo === null) {
            $hojaObjetivo = $spreadsheet->getSheetNames()[0];
        }

        $rows = $spreadsheet->getSheetByName($hojaObjetivo)->toArray(null, true, false, false);

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

        $creadas = 0; $versionadas = 0; $sinCambios = 0;
        $repetidos = []; $vistas = []; $estructurasInvalidas = []; $cambios = []; $bloqueadas = [];

        DB::transaction(function () use (
            $rows, $headerIdx, $map, $vigenteDesde, $request,
            &$creadas, &$versionadas, &$sinCambios, &$repetidos, &$vistas,
            &$estructurasInvalidas, &$cambios, &$bloqueadas
        ) {
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $r   = $rows[$i];
                $c14 = $this->cta($r[$map['c14']] ?? null);
                $c61 = $this->cta($r[$map['c61']] ?? null);

                if ($c14 === null || !str_starts_with($c14, '14')) continue;
                if ($c61 === null) continue;

                if (isset($vistas[$c14])) { $repetidos[] = $c14; }
                $vistas[$c14] = true;

                $estructura = isset($map['estructura'])
                    ? $this->normalizarEstructura($r[$map['estructura']] ?? null)
                    : null;

                if ($estructura !== null && !array_key_exists($estructura, self::ESTRUCTURAS)) {
                    $estructurasInvalidas[] = $c14 . ' (' . $estructura . ')';
                }

                $nombreCta = isset($map['nombre']) ? trim((string)($r[$map['nombre']] ?? '')) : null;

                $actual = Homologacion::actual($c14);

                // Cuenta nueva: se homologa desde el inicio.
                if (!$actual) {
                    Homologacion::create([
                        'cuenta_14'     => $c14,
                        'cuenta_61'     => $c61,
                        'nombre'        => $nombreCta,
                        'estructura'    => $estructura,
                        'vigente_desde' => Homologacion::PERIODO_INICIAL,
                        'vigente_hasta' => null,
                        'version'       => 1,
                        'motivo'        => 'Homologación inicial (carga de plan de cuentas)',
                        'origen'        => 'excel',
                        'user_id'       => $request->user()?->id,
                    ]);
                    $creadas++;
                    continue;
                }

                $igual = (string) $actual->cuenta_61  === (string) $c61
                      && (string) $actual->estructura === (string) $estructura
                      && (string) $actual->nombre     === (string) $nombreCta;

                if ($igual) { $sinCambios++; continue; }

                // No se puede versionar hacia atrás ni sobre el mismo período de inicio.
                if ($vigenteDesde <= $actual->vigente_desde) {
                    $bloqueadas[] = $c14 . ' (su versión vigente empieza en '
                                  . Homologacion::nombrePeriodo($actual->vigente_desde) . ')';
                    continue;
                }

                $actual->vigente_hasta = Homologacion::periodoAnterior($vigenteDesde);
                $actual->save();

                Homologacion::create([
                    'cuenta_14'     => $c14,
                    'cuenta_61'     => $c61,
                    'nombre'        => $nombreCta,
                    'estructura'    => $estructura,
                    'vigente_desde' => $vigenteDesde,
                    'vigente_hasta' => null,
                    'version'       => $actual->version + 1,
                    'reemplaza_a'   => $actual->id,
                    'motivo'        => 'Carga de plan de cuentas',
                    'origen'        => 'excel',
                    'user_id'       => $request->user()?->id,
                ]);

                $versionadas++;
                if ((string) $actual->cuenta_61 !== (string) $c61) {
                    $cambios[] = $c14 . ': ' . $actual->cuenta_61 . ' → ' . $c61;
                }
            }
        });

        @unlink($full);

        $desdeTxt  = Homologacion::nombrePeriodo($vigenteDesde);
        $repetidos = array_values(array_unique($repetidos));

        $msg = "Plan de cuentas procesado (hoja: {$hojaObjetivo}). "
             . "{$creadas} nuevas · {$versionadas} con versión nueva desde {$desdeTxt} · {$sinCambios} sin cambios.";

        if (count($repetidos) > 0) {
            $msg .= ' Cuentas repetidas en el archivo (quedó la última): ' . implode(', ', $repetidos) . '.';
        }

        $avisos = [];

        if (count($cambios) > 0) {
            $lista = array_slice($cambios, 0, 12);
            $extra = count($cambios) > 12 ? ' y ' . (count($cambios) - 12) . ' más…' : '';
            $avisos[] = 'CAMBIARON DE CUENTA 61: ' . implode(' · ', $lista) . $extra
                      . '. Si alguna necesita PLANO DE RECLASIFICACIÓN de los costos ya asentados, márcala desde el botón Editar de su fila.';
        }

        if (count($bloqueadas) > 0) {
            $lista = array_slice($bloqueadas, 0, 10);
            $avisos[] = 'No se versionaron (el período elegido no es posterior al de su versión vigente): '
                      . implode(', ', $lista) . '.';
        }

        if (count($estructurasInvalidas) > 0) {
            $lista = array_slice(array_unique($estructurasInvalidas), 0, 12);
            $extra = count(array_unique($estructurasInvalidas)) > 12 ? ' y otras…' : '';
            $avisos[] = 'Estructuras no reconocidas (la distribución las contará como "Otros costos"): '
                      . implode(', ', $lista) . $extra
                      . '. Válidas: ' . implode(', ', array_keys(self::ESTRUCTURAS)) . '.';
        }

        $redir = back()->with('success', $msg);
        if (count($avisos) > 0) {
            $redir = $redir->with('error', implode(' ', $avisos));
        }

        return $redir;
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