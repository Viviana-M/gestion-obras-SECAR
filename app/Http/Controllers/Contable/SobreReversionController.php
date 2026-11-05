<?php

namespace App\Http\Controllers\Contable;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VALIDACIÓN DE SOBRE-REVERSIÓN
 *
 * Reemplaza al viejo candado ciego que, al cargar, botaba en silencio toda fila con el mismo
 * dedup_hash. Ese candado perdía reversiones legítimas de SECAR: cuando un costo se causa dos
 * veces (dos documentos distintos) y SECAR lo revierte en UN solo documento con dos líneas
 * idénticas, el candado veía las dos líneas iguales y botaba una → el sistema revertía la mitad
 * y quedaba corto contra el ERP.
 *
 * Ahora el importador carga TODAS las filas fiel 1:1. Este reporte hace, en cambio, una
 * validación contable: agrupa por (obra, cuenta, tercero, |valor|) SIN período —porque el costo
 * y su reversión caen en meses distintos— y cuenta:
 *
 *   n_deb  = líneas de débito (costo) con ese valor.
 *   n_cred = líneas de crédito (reversión) con ese valor.
 *
 * Reversiones legítimas = min(n_deb, n_cred) (cada reversión respaldada por un costo). Si
 * n_cred > n_deb, el exceso (n_cred − n_deb) son reversiones sin costo detrás = sobre-reversión,
 * los duplicados reales. Un débito nunca es sospechoso por sí solo (un costo sin revertir todavía
 * es normal), así que NUNCA se borra un débito automáticamente: sólo el exceso de crédito, y
 * únicamente con confirmación humana desde este reporte, jamás en silencio al cargar.
 */
class SobreReversionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->puedeVerModulo('contabilidad'), 403,
            'No tienes permiso para ver Contabilidad.');

        $anio = $request->filled('anio') ? (int) $request->get('anio') : null;
        $obra = trim((string) $request->get('obra', ''));

        // |valor| de la línea: una de las dos columnas está en cero, así que el monto es la que no
        // lo esté. Se agrupa por este valor absoluto para juntar el costo y su reversión.
        $monto = 'ROUND(ABS(CASE WHEN valor_debito <> 0 THEN valor_debito ELSE valor_credito END), 2)';

        $grupos = RegistroFinanciero::query()
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->when($obra !== '', fn ($q) => $q->where('codigo_proyecto', $obra))
            // Sólo movimientos con importe; una fila en cero no aporta ni costo ni reversión.
            ->where(fn ($q) => $q->where('valor_debito', '<>', 0)->orWhere('valor_credito', '<>', 0))
            ->selectRaw("
                codigo_proyecto,
                cuenta_contable,
                tercero_dcto,
                {$monto} as monto,
                MAX(nombre_proyecto) as nombre_proyecto,
                MAX(razon_social) as razon_social,
                SUM(CASE WHEN valor_debito  <> 0 THEN 1 ELSE 0 END) as n_deb,
                SUM(CASE WHEN valor_credito <> 0 THEN 1 ELSE 0 END) as n_cred,
                GROUP_CONCAT(CASE WHEN valor_credito <> 0 THEN id END) as ids_credito,
                GROUP_CONCAT(CASE WHEN valor_debito  <> 0 THEN id END) as ids_debito,
                GROUP_CONCAT(DISTINCT documento) as documentos
            ")
            ->groupByRaw("codigo_proyecto, cuenta_contable, tercero_dcto, {$monto}")
            // Sólo interesa el exceso de crédito: más reversiones que costos.
            ->havingRaw('SUM(CASE WHEN valor_credito <> 0 THEN 1 ELSE 0 END) > SUM(CASE WHEN valor_debito <> 0 THEN 1 ELSE 0 END)')
            ->orderByRaw('(SUM(CASE WHEN valor_credito <> 0 THEN 1 ELSE 0 END) - SUM(CASE WHEN valor_debito <> 0 THEN 1 ELSE 0 END)) DESC')
            ->limit(1000)
            ->get();

        $anios = RegistroFinanciero::selectRaw('DISTINCT anio')->orderByDesc('anio')->pluck('anio');

        return view('contable.sobre-reversion', [
            'grupos'      => $grupos,
            'anio'        => $anio,
            'obra'        => $obra,
            'anios'       => $anios,
            'totalGrupos' => $grupos->count(),
            // Total de créditos que sobran (los duplicados reales) en todos los grupos.
            'totalSobran' => (int) $grupos->sum(fn ($g) => max(0, (int) $g->n_cred - (int) $g->n_deb)),
        ]);
    }

    /**
     * Elimina el exceso de crédito de un grupo (obra · cuenta · tercero · |valor|), con
     * confirmación humana. Recalcula los conteos del lado del servidor —no confía en la vista— y
     * borra sólo (n_cred − n_deb) líneas de crédito, dejando intactas las reversiones legítimas.
     * Nunca toca los débitos.
     */
    public function eliminar(Request $request)
    {
        abort_unless($request->user()->puedeEditarModulo('contabilidad'), 403,
            'No tienes permiso para editar en Contabilidad.');

        $datos = $request->validate([
            'obra'    => 'required|string',
            'cuenta'  => 'required|string',
            'tercero' => 'nullable|string',
            'monto'   => 'required|numeric',
            'anio'    => 'nullable|integer',
        ]);

        $anio    = ! empty($datos['anio']) ? (int) $datos['anio'] : null;
        $tercero = ($datos['tercero'] ?? '') === '' ? null : $datos['tercero'];
        $monto   = round((float) $datos['monto'], 2);

        // IDs de las líneas de crédito del grupo (las que pueden sobrar) y número de débitos que
        // las respaldan. Se recalcula desde la BD para no depender de lo que muestre la pantalla.
        $creditos = $this->baseGrupo($anio, $datos['obra'], $datos['cuenta'], $tercero, $monto)
            ->where('valor_credito', '<>', 0)
            ->orderByDesc('id')
            ->pluck('id');

        $nDeb = $this->baseGrupo($anio, $datos['obra'], $datos['cuenta'], $tercero, $monto)
            ->where('valor_debito', '<>', 0)
            ->count();

        $sobran = $creditos->count() - $nDeb;

        if ($sobran <= 0) {
            return back()->with('error',
                'Ese grupo ya no tiene reversiones de sobra (cada reversión está respaldada por un costo). No se borró nada.');
        }

        $aBorrar = $creditos->take($sobran);
        DB::transaction(function () use ($aBorrar) {
            RegistroFinanciero::whereIn('id', $aBorrar->all())->delete();
        });

        $n = $aBorrar->count();

        return back()->with('success',
            "Se eliminaron {$n} reversión(es) de sobra de la obra {$datos['obra']} (cuenta {$datos['cuenta']}). Se conservan {$nDeb} respaldadas por su costo.");
    }

    /**
     * Consulta base de un grupo (obra · cuenta · tercero · |valor|), replicando el agrupamiento
     * del reporte. Se llama de nuevo cada vez (en vez de clonar) para no arrastrar estado.
     */
    private function baseGrupo(?int $anio, string $obra, string $cuenta, ?string $tercero, float $monto)
    {
        return RegistroFinanciero::query()
            ->when($anio, fn ($q) => $q->where('anio', $anio))
            ->where('codigo_proyecto', $obra)
            ->where('cuenta_contable', $cuenta)
            ->when(
                $tercero === null,
                fn ($q) => $q->whereNull('tercero_dcto'),
                fn ($q) => $q->where('tercero_dcto', $tercero)
            )
            // Se compara en centavos ENTEROS: PDO liga los float como texto y la comparación
            // numérica falla en SQLite; un entero se compara bien tanto en SQLite como en MySQL.
            ->whereRaw(
                'ROUND(ABS(CASE WHEN valor_debito <> 0 THEN valor_debito ELSE valor_credito END) * 100) = ?',
                [(int) round($monto * 100)]
            );
    }
}
