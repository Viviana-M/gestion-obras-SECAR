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

    /* Sección (clase, 1 dígito) */
    .er-sec { background: #F9FAFB; border-top: 2px solid #E8EAED; }
    .er-sec .er-label, .er-sec .er-valor { color: #1B3F6E; font-weight: 700; }
    .er-sec .er-code { color: #1B3F6E; font-weight: 700; }

    /* Grupo (2 dígitos) un poco más fuerte */
    .er-g2 .er-label, .er-g2 .er-valor { font-weight: 600; color: #374151; }

    /* Hoja (auxiliar, 8 dígitos) más tenue */
    .er-leaf .er-label { color: #6B7280; }
    .er-leaf .er-valor { color: #6B7280; }

    /* Subtotales */
    .er-subtotal { display: flex; align-items: baseline; padding: 9px 14px; background: #EFF6FF; }
    .er-subtotal .er-label { color: #1B3F6E; font-weight: 700; font-size: 13px; white-space: nowrap; }
    .er-subtotal .er-dots { flex: 1 1 auto; border-bottom: 1px dotted #BBD3F0; margin: 0 10px; transform: translateY(-4px); }
    .er-subtotal .er-valor { color: #1B3F6E; font-weight: 700; font-size: 13.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .er-final { display: flex; align-items: baseline; padding: 12px 14px; background: #1B3F6E; }
    .er-final .er-label { color: #fff; font-weight: 700; font-size: 13.5px; white-space: nowrap; }
    .er-final .er-dots { flex: 1 1 auto; border-bottom: 1px dotted #5C7BA3; margin: 0 10px; transform: translateY(-4px); }
    .er-final .er-valor { color: #fff; font-weight: 700; font-size: 14.5px; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>

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
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
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

{{-- RESUMEN --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#F0FDF4;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#15803D;margin-bottom:4px">Ingresos</div>
        <div style="font-size:17px;font-weight:600;color:#15803D">${{ number_format($totIngresos, 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Costos</div>
        <div style="font-size:17px;font-weight:600;color:#DC2626">${{ number_format($totCostos, 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF9C3;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#854D0E;margin-bottom:4px">Gastos</div>
        <div style="font-size:17px;font-weight:600;color:#854D0E">${{ number_format($totGastos, 0, ',', '.') }}</div>
    </div>
    <div style="background:{{ $utilNeta >= 0 ? '#F0FDF4' : '#FEF2F2' }};border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Utilidad neta {{ $margenNeto !== null ? '('.$margenNeto.'%)' : '' }}</div>
        <div style="font-size:17px;font-weight:600;color:{{ $utilNeta >= 0 ? '#15803D' : '#DC2626' }}">${{ number_format($utilNeta, 0, ',', '.') }}</div>
    </div>
</div>

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
            {{-- INGRESOS --}}
            @foreach($secciones['Ingresos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            {{-- COSTOS --}}
            @foreach($secciones['Costos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            {{-- UTILIDAD BRUTA --}}
            <div class="er-subtotal">
                <span class="er-label">= Utilidad bruta {{ $margenBruto !== null ? '('.$margenBruto.'%)' : '' }}</span>
                <span class="er-dots"></span>
                <span class="er-valor" style="color:{{ $utilBruta >= 0 ? '#1B3F6E' : '#DC2626' }}">${{ number_format($utilBruta, 0, ',', '.') }}</span>
            </div>

            {{-- GASTOS --}}
            @foreach($secciones['Gastos']['nodos'] as $n)
                @include('financiero.partials.fila-er', ['n' => $n])
            @endforeach

            {{-- UTILIDAD NETA --}}
            <div class="er-final">
                <span class="er-label">= Utilidad neta {{ $margenNeto !== null ? '('.$margenNeto.'%)' : '' }}</span>
                <span class="er-dots"></span>
                <span class="er-valor">${{ number_format($utilNeta, 0, ',', '.') }}</span>
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