@extends('layouts.app')

@section('title', 'Estados financieros')

@section('content')
<style>
    .er-card { padding: 0; overflow: hidden; }
    .er-head {
        display: flex; justify-content: space-between;
        padding: 9px 14px; background: #F3F4F6;
        font-size: 10.5px; color: #6B7280; text-transform: uppercase; letter-spacing: .05em;
    }
    .er-linea {
        display: flex; align-items: baseline;
        padding: 5px 14px; border-bottom: 1px solid #F4F5F7;
    }
    .er-click { cursor: pointer; }
    .er-click:hover { background: #FAFBFC; }
    .er-chev { width: 13px; flex: none; color: #B0B6BE; font-size: 9px; }
    .er-label { white-space: nowrap; color: #374151; font-size: 13px; }
    .er-code { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 11px; color: #AAB0B8; margin-right: 2px; }
    .er-dots { flex: 1 1 auto; min-width: 18px; border-bottom: 1px dotted #D7DBE0; margin: 0 10px; transform: translateY(-4px); }
    .er-valor { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 13px; color: #374151; }
    .er-valor.neg { color: #DC2626; }

    .er-sec { background: #F9FAFB; border-top: 2px solid #E8EAED; }
    .er-sec .er-label, .er-sec .er-valor { color: #1B3F6E; font-weight: 700; }
    .er-sec .er-code { color: #1B3F6E; font-weight: 700; }

    .er-g2 .er-label, .er-g2 .er-valor { font-weight: 600; color: #374151; }

    .er-leaf .er-label { color: #6B7280; }
    .er-leaf .er-valor { color: #6B7280; }

    .er-subtotal { display: flex; align-items: baseline; padding: 9px 14px; background: #EFF6FF; }
    .er-subtotal .er-label { color: #1B3F6E; font-weight: 700; font-size: 13px; white-space: nowrap; }
    .er-subtotal .er-dots { flex: 1 1 auto; border-bottom: 1px dotted #BBD3F0; margin: 0 10px; transform: translateY(-4px); }
    .er-subtotal .er-valor { color: #1B3F6E; font-weight: 700; font-size: 13.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .er-final { display: flex; align-items: baseline; padding: 12px 14px; background: #1B3F6E; }
    .er-final .er-label { color: #fff; font-weight: 700; font-size: 13.5px; white-space: nowrap; }
    .er-final .er-dots { flex: 1 1 auto; border-bottom: 1px dotted #5C7BA3; margin: 0 10px; transform: translateY(-4px); }
    .er-final .er-valor { color: #fff; font-weight: 700; font-size: 14.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }

    /* Comparativo con el anio anterior */
    .cmp { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .cmp th { text-align: right; padding: 7px 10px; font-size: 10.5px; color: #6B7280; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #E5E7EB; }
    .cmp th:first-child { text-align: left; }
    .cmp td { padding: 7px 10px; text-align: right; font-variant-numeric: tabular-nums; border-bottom: 1px solid #F4F5F7; }
    .cmp td:first-child { text-align: left; color: #374151; }
    .cmp tr:last-child td { border-bottom: none; font-weight: 700; color: #1B3F6E; }
    .var-up   { color: #15803D; font-weight: 600; }
    .var-down { color: #DC2626; font-weight: 600; }
    .var-na   { color: #C0C5CC; }
</style>

@php
    $nombresMes = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $mesNombre  = $nombresMes[$mes - 1] ?? '';
    $money = fn($n) => '$'.number_format($n, 0, ',', '.');

    // Un aumento de ingresos o utilidad es bueno; uno de costos o gastos, no.
    $pinta = function (?float $v, bool $subirEsBueno = true) {
        if ($v === null) return ['—', 'var-na'];
        $txt   = ($v > 0 ? '+' : '') . number_format($v, 1, ',', '.') . '%';
        $bueno = $subirEsBueno ? ($v >= 0) : ($v <= 0);
        return [$txt, $bueno ? 'var-up' : 'var-down'];
    };
@endphp

<h1 class="page-title">Estados financieros</h1>

{{-- FILTROS --}}
<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('financiero.estados') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Hasta mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach($nombresMes as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Modo</label>
            <select name="modo" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="acumulado" {{ $modo == 'acumulado' ? 'selected' : '' }}>Acumulado</option>
                <option value="mes" {{ $modo == 'mes' ? 'selected' : '' }}>Solo el mes</option>
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Filtrar</button>
    </form>
</div>

{{-- QUÉ PERÍODO SE ESTÁ VIENDO --}}
<div style="background:#EEF2FF;border:1px solid #C7D2FE;border-radius:8px;padding:9px 14px;margin-bottom:1rem;font-size:12.5px;color:#4338CA">
    @if($modo === 'acumulado')
        Acumulado de <b>enero a {{ mb_strtolower($mesNombre) }} de {{ $anio }}</b>.
        El estado de resultados no acumula entre años: las cuentas 4, 5 y 6 se reinician cada enero.
    @else
        Movimiento de <b>{{ $mesNombre }} de {{ $anio }}</b> únicamente.
    @endif
</div>

{{-- RESUMEN --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#F0FDF4;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#15803D;margin-bottom:4px">Ingresos</div>
        <div style="font-size:17px;font-weight:600;color:#15803D">{{ $money($totIngresos) }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Costos</div>
        <div style="font-size:17px;font-weight:600;color:#DC2626">{{ $money($totCostos) }}</div>
    </div>
    <div style="background:#FEF9C3;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#854D0E;margin-bottom:4px">Gastos</div>
        <div style="font-size:17px;font-weight:600;color:#854D0E">{{ $money($totGastos) }}</div>
    </div>
    <div style="background:{{ $utilNeta >= 0 ? '#F0FDF4' : '#FEF2F2' }};border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Utilidad neta {{ $margenNeto !== null ? '('.$margenNeto.'%)' : '' }}</div>
        <div style="font-size:17px;font-weight:600;color:{{ $utilNeta >= 0 ? '#15803D' : '#DC2626' }}">{{ $money($utilNeta) }}</div>
    </div>
</div>

{{-- COMPARATIVO CON EL AÑO ANTERIOR --}}
@if(!empty($comparativo) && $comparativo['hay_datos'])
<div class="card" style="margin-bottom:1rem">
    <h3 style="margin-bottom:10px">
        Comparativo con {{ $comparativo['anio'] }}
        <span style="font-weight:400;font-size:11.5px;color:#9CA3AF">
            (mismo corte: {{ $modo === 'acumulado' ? 'enero a '.mb_strtolower($mesNombre) : $mesNombre }})
        </span>
    </h3>

    <table class="cmp">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>{{ $anio }}</th>
                <th>{{ $comparativo['anio'] }}</th>
                <th>Variación</th>
            </tr>
        </thead>
        <tbody>
            @php
                $filas = [
                    ['Ingresos',      $totIngresos, $comparativo['ingresos'],   $comparativo['var_ingresos'], true],
                    ['Costos',        $totCostos,   $comparativo['costos'],     $comparativo['var_costos'],   false],
                    ['Gastos',        $totGastos,   $comparativo['gastos'],     $comparativo['var_gastos'],   false],
                    ['Utilidad neta', $utilNeta,    $comparativo['util_neta'],  $comparativo['var_neta'],     true],
                ];
            @endphp
            @foreach($filas as [$label, $act, $ant, $var, $subirEsBueno])
                @php [$vTxt, $vClase] = $pinta($var, $subirEsBueno); @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td>{{ $money($act) }}</td>
                    <td style="color:#9CA3AF">{{ $money($ant) }}</td>
                    <td class="{{ $vClase }}">{{ $vTxt }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ESTADO DE RESULTADOS POR NIVELES --}}
<div class="card" style="margin-bottom:1rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h3 style="margin:0">Estado de Resultados</h3>
        <div style="display:flex;gap:6px">
            <button type="button" onclick="expandirTodo()" style="font-size:11px;padding:5px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;cursor:pointer;color:#374151">Expandir todo</button>
            <button type="button" onclick="colapsarTodo()" style="font-size:11px;padding:5px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;cursor:pointer;color:#374151">Colapsar</button>
        </div>
    </div>

    <div class="er-card" style="border:1px solid #EBEDF0;border-radius:10px">
        <div class="er-head">
            <span>Concepto</span>
            <span>Valor</span>
        </div>

        <div id="er-arbol">
            @foreach($secciones['Ingresos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            @foreach($secciones['Costos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            <div class="er-subtotal">
                <span class="er-label">= Utilidad bruta {{ $margenBruto !== null ? '('.$margenBruto.'%)' : '' }}</span>
                <span class="er-dots"></span>
                <span class="er-valor" style="color:{{ $utilBruta >= 0 ? '#1B3F6E' : '#DC2626' }}">{{ $money($utilBruta) }}</span>
            </div>

            @foreach($secciones['Gastos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            <div class="er-final">
                <span class="er-label">= Utilidad neta {{ $margenNeto !== null ? '('.$margenNeto.'%)' : '' }}</span>
                <span class="er-dots"></span>
                <span class="er-valor">{{ $money($utilNeta) }}</span>
            </div>
        </div>
    </div>
</div>

{{-- ESTADO DE SITUACIÓN FINANCIERA (pendiente) --}}
<div class="card">
    <h3 style="margin-bottom:6px">Estado de Situación Financiera (balance)</h3>
    <p style="font-size:12px;color:#9CA3AF;margin:0">
        En preparación. Ya se está capturando activos, pasivos y patrimonio en una tabla aparte;
        falta integrar el archivo de cierre (período 13) y validar que cuadre antes de mostrarlo.
    </p>
</div>

<script>
const NEXT = {1:2, 2:4, 4:6, 6:8};
const expanded = new Set();

function nodos() { return Array.from(document.querySelectorAll('#er-arbol .nodo')); }
function descendientes(code) { return nodos().filter(f => f.dataset.code.length > code.length && f.dataset.code.startsWith(code)); }
function hijos(code) {
    const cl = NEXT[code.length];
    if (!cl) return [];
    return nodos().filter(f => f.dataset.code.length === cl && f.dataset.code.startsWith(code));
}
function toggleNodo(code) {
    if (expanded.has(code)) {
        descendientes(code).forEach(f => { f.style.display = 'none'; expanded.delete(f.dataset.code); });
        expanded.delete(code);
    } else {
        hijos(code).forEach(f => f.style.display = 'flex');
        expanded.add(code);
    }
    chevrons();
}
function chevrons() {
    nodos().forEach(f => {
        const ch = f.querySelector('.er-chev');
        if (!ch) return;
        const len = parseInt(f.dataset.len);
        if (!NEXT[len]) { ch.textContent = ''; return; }
        ch.textContent = expanded.has(f.dataset.code) ? '▾' : '▸';
    });
}
function expandirTodo() {
    nodos().forEach(f => { f.style.display = 'flex'; if (NEXT[parseInt(f.dataset.len)]) expanded.add(f.dataset.code); });
    chevrons();
}
function colapsarTodo() {
    expanded.clear();
    nodos().forEach(f => {
        const len = parseInt(f.dataset.len);
        f.style.display = (len <= 2) ? 'flex' : 'none';
        if (len === 1) expanded.add(f.dataset.code);
    });
    chevrons();
}
colapsarTodo();
</script>
@endsection