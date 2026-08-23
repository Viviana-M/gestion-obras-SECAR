@extends('layouts.app')

@section('title', 'Seguridad social por persona')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $maxTotal = (float) ($personas->max('total') ?? 0);
@endphp

<x-page-banner title="Seguridad social por persona" icon="🛡️">
    Costo de aportes (<b>Aporte empresa</b>) por persona en el período, con el desglose por concepto PILA.
</x-page-banner>

{{-- ══════════ FILTROS ══════════ --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <form method="GET" action="{{ route('contable.autoliquidacion.personas') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:0">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Período</label>
            <select name="periodo" onchange="const [a,m]=this.value.split('-');this.form.mes.value=m;this.form.anio.value=a;this.form.submit()"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
                @forelse($periodos as $p)
                    <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($p->mes==$mes && $p->anio==$anio) ? 'selected' : '' }}>
                        {{ $nombresMes[$p->mes] ?? $p->mes }} {{ $p->anio }}
                    </option>
                @empty
                    <option>{{ $nombresMes[$mes] ?? $mes }} {{ $anio }}</option>
                @endforelse
            </select>
            <input type="hidden" name="mes" value="{{ $mes }}">
            <input type="hidden" name="anio" value="{{ $anio }}">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Unidad de negocio</label>
            <select name="un" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
                <option value="">Todas</option>
                @foreach($unidades as $u)
                    <option value="{{ $u }}" {{ $un === $u ? 'selected' : '' }}>{{ $u }}</option>
                @endforeach
            </select>
        </div>
    </form>
    <div style="flex:1;min-width:180px">
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Buscar persona</label>
        <input id="buscar" type="text" placeholder="🔎 Nombre o cédula…" oninput="filtrar()" autocomplete="off"
            style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
    </div>
    <a href="{{ route('contable.autoliquidacion.personas.excel', ['mes'=>$mes,'anio'=>$anio,'un'=>$un]) }}"
       style="padding:8px 14px;background:#15803D;color:white;border-radius:8px;font-size:12px;text-decoration:none;font-weight:500;white-space:nowrap">⬇ Excel</a>
</div>

{{-- ══════════ KPIs ══════════ --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:1rem">
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280">Total Aporte empresa</div>
        <div style="font-size:22px;font-weight:700;color:#15803D" id="kpi-total">{{ $fmt($total) }}</div>
    </div>
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280">Personas</div>
        <div style="font-size:22px;font-weight:700;color:#1B3F6E">{{ number_format($numPersonas, 0, ',', '.') }}</div>
    </div>
    <div class="card" style="padding:14px 16px">
        <div style="font-size:11px;color:#6B7280">Promedio por persona</div>
        <div style="font-size:22px;font-weight:700;color:#1B3F6E">{{ $fmt($promedio) }}</div>
    </div>
</div>

@if($numPersonas === 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay aportes cargados para este período/filtro.</div>
@else

{{-- ══════════ MAESTRO-DETALLE ══════════ --}}
<div class="ss-layout" style="display:grid;grid-template-columns:minmax(300px,400px) 1fr;gap:16px;align-items:start">

    {{-- Panel izquierdo: lista --}}
    <div class="card" style="padding:0;display:flex;flex-direction:column;max-height:72vh">
        <div style="padding:12px 14px;border-bottom:1px solid #E5E7EB;font-size:12px;font-weight:700;color:#1B3F6E">
            Personas <span style="font-weight:400;color:#9CA3AF">(mayor a menor)</span>
        </div>
        <div id="ss-lista" style="overflow-y:auto;flex:1">
            @foreach($personas as $i => $p)
            <div class="ss-item" data-i="{{ $i }}"
                data-buscar="{{ \Illuminate\Support\Str::ascii(mb_strtolower(($p['nombre'] ?? '').' '.($p['cedula'] ?? ''))) }}"
                onclick="seleccionar({{ $i }})"
                style="padding:9px 14px;border-bottom:1px solid #F3F4F6;cursor:pointer">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
                    <div style="min-width:0">
                        <div style="font-size:12.5px;font-weight:600;color:#1B3F6E;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $p['nombre'] }}</div>
                        <div style="font-size:10.5px;color:#9CA3AF;font-family:monospace">{{ $p['cedula'] }} · {{ $p['un'] }}</div>
                    </div>
                    <div style="font-size:12.5px;font-weight:700;color:#15803D;white-space:nowrap">{{ $fmt($p['total']) }}</div>
                </div>
                <div style="height:5px;border-radius:3px;background:#EEF2FF;overflow:hidden;margin-top:5px">
                    <div style="height:100%;width:{{ $maxTotal > 0 ? round($p['total'] / $maxTotal * 100, 1) : 0 }}%;background:#2a78d6"></div>
                </div>
            </div>
            @endforeach
            <div id="ss-sinresultados" style="display:none;padding:16px;text-align:center;color:#9CA3AF;font-size:12px">Sin resultados para la búsqueda.</div>
        </div>
    </div>

    {{-- Panel derecho: detalle --}}
    <div id="ss-detalle" class="card ss-detalle" style="padding:16px;min-height:300px">
        <div id="ss-detalle-vacio" style="color:#9CA3AF;text-align:center;padding:3rem 1rem;font-size:13px">
            Selecciona una persona de la lista para ver su desglose de aportes.
        </div>
        <div id="ss-detalle-cont" style="display:none">
            <button type="button" class="ss-cerrar" onclick="cerrarDetalle()"
                style="display:none;position:absolute;top:10px;right:12px;background:#F3F4F6;border:none;border-radius:8px;width:32px;height:32px;font-size:16px;cursor:pointer;color:#374151">✕</button>
            <div style="font-size:16px;font-weight:700;color:#1B3F6E" id="d-nombre"></div>
            <div style="font-size:12px;color:#9CA3AF;margin-bottom:12px"><span id="d-cedula" style="font-family:monospace"></span> · UN <span id="d-un"></span></div>

            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
                <div style="position:relative;width:170px;height:170px;flex:none">
                    <div id="d-dona"></div>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none">
                        <div style="font-size:9px;color:#9CA3AF;letter-spacing:.3px">TOTAL</div>
                        <div id="d-total" style="font-size:15px;font-weight:700;color:#15803D"></div>
                    </div>
                </div>
                <div style="flex:1;min-width:220px">
                    <table style="width:100%;border-collapse:collapse;font-size:12px" id="d-conceptos"></table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PERS = @json($personas);
// Paleta categórica validada (dataviz): orden fijo, sin ciclar.
const PALETA = ['#2a78d6','#eb6834','#1baf7a','#eda100','#e87ba4','#008300','#4a3aa7','#e34948'];
const fmt = n => '$' + Math.round(n).toLocaleString('es-CO');

function norm(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

function filtrar(){
    const q = norm(document.getElementById('buscar').value.trim());
    let visibles = 0;
    document.querySelectorAll('#ss-lista .ss-item').forEach(function(row){
        const match = !q || (row.dataset.buscar || '').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visibles++;
    });
    document.getElementById('ss-sinresultados').style.display = visibles ? 'none' : 'block';
}

function dona(conceptos){
    let items = conceptos.slice();
    if (items.length > 8) {
        const head = items.slice(0, 7);
        const resto = items.slice(7).reduce((s,c)=>s+c.aporte, 0);
        head.push({concepto:'Otros', aporte:resto});
        items = head;
    }
    const total = items.reduce((s,c)=>s+c.aporte, 0);
    let acc = 0, arcos = '';
    items.forEach(function(c, idx){
        let p = total > 0 ? c.aporte / total * 100 : 0;
        const gap = p > 2 ? 0.8 : 0;   // separación de 2px aprox entre segmentos
        const color = PALETA[idx % PALETA.length];
        arcos += `<circle cx="21" cy="21" r="15.915" fill="none" stroke="${color}" stroke-width="6"
            stroke-dasharray="${Math.max(p-gap,0)} ${100-Math.max(p-gap,0)}" stroke-dashoffset="${(25 - acc + 100) % 100}"></circle>`;
        acc += p;
    });
    return {
        svg: `<svg viewBox="0 0 42 42" width="170" height="170" style="transform:rotate(-90deg)">
                <circle cx="21" cy="21" r="15.915" fill="none" stroke="#F1F3F5" stroke-width="6"></circle>${arcos}
              </svg>`,
        items, total
    };
}

function seleccionar(i){
    const p = PERS[i]; if (!p) return;
    document.querySelectorAll('#ss-lista .ss-item').forEach(el => el.style.background =
        (el.dataset.i == i) ? '#EFF6FF' : '');
    document.getElementById('ss-detalle-vacio').style.display = 'none';
    document.getElementById('ss-detalle-cont').style.display = 'block';
    document.getElementById('d-nombre').textContent = p.nombre;
    document.getElementById('d-cedula').textContent = p.cedula;
    document.getElementById('d-un').textContent = p.un;
    document.getElementById('d-total').textContent = fmt(p.total);

    const d = dona(p.conceptos);
    document.getElementById('d-dona').innerHTML = d.svg;

    let filas = '<tr style="color:#9CA3AF;text-align:left"><td style="padding:4px 6px">Concepto PILA</td><td style="padding:4px 6px;text-align:right">Aporte empresa</td><td style="padding:4px 6px;text-align:right">%</td></tr>';
    d.items.forEach(function(c, idx){
        const pct = d.total > 0 ? (c.aporte / d.total * 100) : 0;
        filas += `<tr style="border-top:1px solid #F3F4F6">
            <td style="padding:5px 6px;color:#374151"><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:${PALETA[idx % PALETA.length]};margin-right:6px"></span>${c.concepto}</td>
            <td style="padding:5px 6px;text-align:right;font-weight:600;color:#15803D">${fmt(c.aporte)}</td>
            <td style="padding:5px 6px;text-align:right;color:#9CA3AF">${pct.toFixed(1)}%</td></tr>`;
    });
    document.getElementById('d-conceptos').innerHTML = filas;

    // En pantallas angostas, abrir como modal.
    document.getElementById('ss-detalle').classList.add('abierto');
}

function cerrarDetalle(){ document.getElementById('ss-detalle').classList.remove('abierto'); }
</script>

<style>
.ss-item:hover { background:#F9FAFB; }
.ss-detalle { position:relative; }
@media (max-width: 860px) {
    .ss-layout { grid-template-columns: 1fr !important; }
    /* El detalle se abre como modal para no romper el layout en angosto. */
    .ss-detalle { display:none; }
    .ss-detalle.abierto {
        display:block; position:fixed; top:70px; left:12px; right:12px; bottom:12px;
        z-index:60; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.25);
    }
    .ss-detalle.abierto .ss-cerrar { display:block !important; }
}
</style>
@endif
@endsection
