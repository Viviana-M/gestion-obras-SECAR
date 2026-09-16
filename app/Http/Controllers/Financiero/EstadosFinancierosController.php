<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

/**
 * ESTADO DE RESULTADOS
 *
 * OJO CON EL ACUMULADO: un estado de resultados NO acumula entre anios. Las cuentas de
 * resultado (clases 4, 5 y 6) se reinician cada enero. El acumulado va de enero del anio
 * seleccionado hasta el mes seleccionado, y nada mas.
 *
 * Antes se sumaban TODOS los anios anteriores, lo que inflaba las cifras enormemente
 * (el acumulado a diciembre 2025 mostraba 2022+2023+2024+2025 juntos).
 *
 * Esto pasa porque el importador excluye los asientos de cierre de SIESA (periodo 13),
 * asi que en nuestra base las cuentas de resultado nunca se cancelan a fin de anio.
 * El corte hay que hacerlo aqui.
 */
class EstadosFinancierosController extends Controller
{
    public function index(Request $request)
    {
        $anio = (int) $request->get('anio', date('Y'));
        $mes  = (int) $request->get('mes', date('n'));
        $modo = $request->get('modo', 'acumulado');
        if (!in_array($modo, ['mes', 'acumulado'], true)) $modo = 'acumulado';

        $secciones   = $this->armarSecciones($anio, $mes, $modo);
        $totIngresos = $secciones['Ingresos']['total'];
        $totCostos   = $secciones['Costos']['total'];
        $totGastos   = $secciones['Gastos']['total'];

        $utilBruta   = $totIngresos - $totCostos;
        $utilNeta    = $utilBruta - $totGastos;
        $margenBruto = $totIngresos != 0 ? round($utilBruta / $totIngresos * 100, 2) : null;
        $margenNeto  = $totIngresos != 0 ? round($utilNeta  / $totIngresos * 100, 2) : null;

        // Comparativo con el mismo corte del anio anterior.
        $sAnt   = $this->armarSecciones($anio - 1, $mes, $modo);
        $ingAnt = $sAnt['Ingresos']['total'];
        $cosAnt = $sAnt['Costos']['total'];
        $gasAnt = $sAnt['Gastos']['total'];

        $comparativo = [
            'anio'         => $anio - 1,
            'hay_datos'    => ($ingAnt != 0 || $cosAnt != 0 || $gasAnt != 0),
            'ingresos'     => $ingAnt,
            'costos'       => $cosAnt,
            'gastos'       => $gasAnt,
            'util_bruta'   => $ingAnt - $cosAnt,
            'util_neta'    => $ingAnt - $cosAnt - $gasAnt,
            'var_ingresos' => $this->variacion($totIngresos, $ingAnt),
            'var_costos'   => $this->variacion($totCostos,   $cosAnt),
            'var_gastos'   => $this->variacion($totGastos,   $gasAnt),
            'var_neta'     => $this->variacion($utilNeta,    $ingAnt - $cosAnt - $gasAnt),
        ];

        return view('financiero.estados-financieros', compact(
            'anio', 'mes', 'modo',
            'secciones',
            'totIngresos', 'totCostos', 'totGastos',
            'utilBruta', 'utilNeta', 'margenBruto', 'margenNeto',
            'comparativo'
        ));
    }

    /** Arma las tres secciones del ER para un anio/mes/modo. */
    private function armarSecciones(int $anio, int $mes, string $modo): array
    {
        $base = RegistroFinanciero::query()
            ->where('anio', $anio)   // <-- SIEMPRE dentro del mismo anio
            ->where(function ($q) use ($mes, $modo) {
                if ($modo === 'mes') {
                    $q->where('mes', $mes);          // solo el mes
                } else {
                    $q->where('mes', '<=', $mes);    // de enero al mes seleccionado
                }
            });

        $hojas = $base
            ->whereIn('cuenta_mayor', ['Ingreso', 'Costos aplicados', 'Gasto'])
            ->selectRaw('cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as total')
            ->groupBy('cuenta_contable')
            ->get();

        // Clase contable + multiplicador para mostrar en positivo
        $config = [
            'Ingresos' => ['clase' => '4', 'mult' =>  1],
            'Costos'   => ['clase' => '6', 'mult' => -1],
            'Gastos'   => ['clase' => '5', 'mult' => -1],
        ];

        $secciones = [];
        foreach ($config as $nombre => $cfg) {
            $deLaClase = $hojas->filter(fn($l) => substr((string) $l->cuenta_contable, 0, 1) === $cfg['clase']);
            $secciones[$nombre] = $this->construirArbol($deLaClase, $nombre, $cfg['mult'], $cfg['clase']);
        }

        return $secciones;
    }

    private function variacion(float $actual, float $anterior): ?float
    {
        if (abs($anterior) < 0.5) return null;
        return round(($actual - $anterior) / abs($anterior) * 100, 1);
    }

    /**
     * Construye el arbol por niveles a partir de las hojas (8 digitos).
     * Niveles por longitud de codigo: 1=clase, 2=grupo, 4=cuenta, 6=subcuenta, 8=auxiliar.
     */
    private function construirArbol($hojas, string $nombreSeccion, int $mult, string $clase): array
    {
        $cortes = [1 => 0, 2 => 1, 4 => 2, 6 => 3, 8 => 4]; // longitud => nivel de indentacion
        $nodos  = [];

        foreach ($hojas as $l) {
            $code  = (string) $l->cuenta_contable;
            $monto = (float) $l->total * $mult; // queda positivo

            // Para cuentas de menos de 8 dígitos, substr(code,0,6) y substr(code,0,8)
            // devuelven el mismo prefijo; sin deduplicar, el nodo hoja recibía el monto
            // dos veces. $vistos evita el doble conteo por cada código.
            $vistos = [];
            foreach ($cortes as $len => $nivel) {
                $pref = substr($code, 0, $len);
                if ($pref === '' || isset($vistos[$pref])) continue;
                $vistos[$pref] = true;

                if (!isset($nodos[$pref])) {
                    $nodos[$pref] = ['code' => $pref, 'len' => $len, 'nivel' => $nivel, 'nombre' => '', 'monto' => 0];
                }
                $nodos[$pref]['monto'] += $monto;
                // El nombre se pone en el nodo hoja (el prefijo que es el código completo).
                if ($pref === $code) $nodos[$pref]['nombre'] = $l->descripcion;
            }
        }

        if (isset($nodos[$clase])) {
            $nodos[$clase]['nombre'] = mb_strtoupper($nombreSeccion);
        }

        ksort($nodos);

        return [
            'total' => $nodos[$clase]['monto'] ?? 0,
            'nodos' => array_values($nodos),
        ];
    }
}