<?php

namespace App\Http\Controllers\Financiero;

use App\Http\Controllers\Controller;
use App\Models\RegistroFinanciero;
use Illuminate\Http\Request;

class EstadosFinancierosController extends Controller
{
    public function index(Request $request)
    {
        $anio = $request->get('anio', date('Y'));
        $mes  = $request->get('mes', date('n'));
        $modo = $request->get('modo', 'acumulado');

        $base = RegistroFinanciero::query()
            ->where(function ($q) use ($anio, $mes, $modo) {
                if ($modo === 'mes') {
                    $q->where('anio', $anio)->where('mes', $mes);
                } else {
                    $q->where('anio', '<', $anio)
                      ->orWhere(function ($q2) use ($anio, $mes) {
                          $q2->where('anio', $anio)->where('mes', '<=', $mes);
                      });
                }
            });

        // Hojas (cuenta de 8 dígitos) de las clases del Estado de Resultados
        $leaves = (clone $base)
            ->whereIn('cuenta_mayor', ['Ingreso', 'Costos aplicados', 'Gasto'])
            ->selectRaw('cuenta_contable, MAX(descripcion) as descripcion, SUM(estado_er) as total')
            ->groupBy('cuenta_contable')
            ->get();

        // Config por sección: clase contable + multiplicador para mostrar en positivo
        $config = [
            'Ingresos' => ['clase' => '4', 'mult' => 1],
            'Costos'   => ['clase' => '6', 'mult' => -1],
            'Gastos'   => ['clase' => '5', 'mult' => -1],
        ];

        $secciones = [];
        foreach ($config as $nombre => $cfg) {
            $hojas = $leaves->filter(fn($l) => substr($l->cuenta_contable, 0, 1) === $cfg['clase']);
            $secciones[$nombre] = $this->construirArbol($hojas, $nombre, $cfg['mult'], $cfg['clase']);
        }

        $totIngresos = $secciones['Ingresos']['total'];
        $totCostos   = $secciones['Costos']['total'];
        $totGastos   = $secciones['Gastos']['total'];

        $utilBruta   = $totIngresos - $totCostos;
        $utilNeta    = $utilBruta - $totGastos;
        $margenBruto = $totIngresos != 0 ? round($utilBruta / $totIngresos * 100, 2) : null;
        $margenNeto  = $totIngresos != 0 ? round($utilNeta / $totIngresos * 100, 2) : null;

        return view('financiero.estados-financieros', compact(
            'anio', 'mes', 'modo',
            'secciones',
            'totIngresos', 'totCostos', 'totGastos',
            'utilBruta', 'utilNeta', 'margenBruto', 'margenNeto'
        ));
    }

    /**
     * Construye el árbol por niveles a partir de las hojas (8 dígitos).
     * Niveles por longitud de código: 1=clase, 2=grupo, 4=cuenta, 6=subcuenta, 8=auxiliar.
     */
    private function construirArbol($hojas, string $nombreSeccion, int $mult, string $clase): array
    {
        $cortes = [1 => 0, 2 => 1, 4 => 2, 6 => 3, 8 => 4]; // longitud => nivel de indentación
        $nodos = [];

        foreach ($hojas as $l) {
            $code  = $l->cuenta_contable;
            $monto = (float) $l->total * $mult; // queda positivo
            foreach ($cortes as $len => $nivel) {
                $pref = substr($code, 0, $len);
                if (!isset($nodos[$pref])) {
                    $nodos[$pref] = ['code' => $pref, 'len' => $len, 'nivel' => $nivel, 'nombre' => '', 'monto' => 0];
                }
                $nodos[$pref]['monto'] += $monto;
                if ($len === 8) $nodos[$pref]['nombre'] = $l->descripcion;
            }
        }

        if (isset($nodos[$clase])) {
            $nodos[$clase]['nombre'] = strtoupper($nombreSeccion);
        }

        ksort($nodos);

        return [
            'total' => $nodos[$clase]['monto'] ?? 0,
            'nodos' => array_values($nodos),
        ];
    }
}