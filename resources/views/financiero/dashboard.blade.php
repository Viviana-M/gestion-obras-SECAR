@extends('layouts.app')

@section('title', 'Estado de resultados')

@section('content')
<x-page-banner title="Estado de resultados por proyecto" icon="📊">
    Revisa ingresos, costos y margen de cada obra en el período que elijas.
</x-page-banner>

{{-- FILTROS --}}
<x-filtros-panel>
    <form method="GET" action="{{ route('financiero.dashboard') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="filtro-field" style="flex:1 1 90px">
            <label class="filtro-label">Año</label>
            <select name="anio" class="filtro-select">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Hasta mes</label>
            <select name="mes" class="filtro-select">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Modo</label>
            <select name="modo" class="filtro-select">
                <option value="acumulado" {{ $modo == 'acumulado' ? 'selected' : '' }}>Acumulado</option>
                <option value="mes" {{ $modo == 'mes' ? 'selected' : '' }}>Solo el mes</option>
            </select>
        </div>
        <div class="filtro-field" style="flex:2 1 220px">
            <label class="filtro-label">Proyecto</label>
            <select name="proyecto" class="filtro-select" style="min-width:220px">
                <option value="">Todos los proyectos</option>
                @foreach($proyectos as $p)
                    <option value="{{ $p->codigo_proyecto }}" {{ $proyecto == $p->codigo_proyecto ? 'selected' : '' }}>
                        {{ $p->codigo_proyecto }} - {{ $p->nombre_proyecto }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
        <a href="{{ route('financiero.dashboard') }}" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:flex;align-items:center">
            Limpiar
        </a>
    </form>
</x-filtros-panel>

{{-- ALERTA --}}
@if($proyectosAlerta->count() > 0)
<div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:10px;padding:12px 16px;margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
            <div style="font-size:14px;font-weight:600;color:#854D0E">
                {{ $proyectosAlerta->count() }} proyectos con costo pero sin ingreso registrado
            </div>
            <div style="font-size:12px;color:#9CA3AF;margin-top:2px">
                Costo acumulado pendiente de facturar: ${{ number_format($totalAlerta, 0, ',', '.') }}
            </div>
        </div>
        <button type="button" onclick="toggleAlerta()" id="btn-alerta"
            style="font-size:12px;padding:6px 14px;border:1px solid #E5E7EB;border-radius:8px;background:white;cursor:pointer;color:#374151;white-space:nowrap">
            Ver proyectos
        </button>
    </div>
    <div id="lista-alerta" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #FDE68A">
        <div style="display:flex;flex-direction:column;gap:4px;max-height:320px;overflow:auto">
            @foreach($proyectosAlerta as $pa)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;padding:4px 2px">
                <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <span style="font-family:monospace;font-weight:600;color:#374151">{{ $pa['codigo'] }}</span>
                    <span style="color:#9CA3AF"> · {{ \Illuminate\Support\Str::limit($pa['nombre'], 45) }}</span>
                </div>
                <span style="font-weight:600;color:#B45309;white-space:nowrap">${{ number_format($pa['costo_total'], 0, ',', '.') }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- TARJETAS RESUMEN --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#F0FDF4;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#15803D;margin-bottom:4px">Total ingresos</div>
        <div style="font-size:18px;font-weight:600;color:#15803D">${{ number_format($totalIngreso, 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Costos aplicados</div>
        <div style="font-size:18px;font-weight:600;color:#DC2626">${{ number_format(abs($totalCostoAplicado), 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF9C3;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#854D0E;margin-bottom:4px">Costos por aplicar</div>
        <div style="font-size:18px;font-weight:600;color:#854D0E">${{ number_format(abs($totalCostoPorAplicar), 0, ',', '.') }}</div>
    </div>
    <div style="background:{{ $totalMargen >= 15 ? '#F0FDF4' : ($totalMargen >= 0 ? '#FEF9C3' : '#FEF2F2') }};border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Margen bruto</div>
        <div style="font-size:18px;font-weight:600;color:{{ $totalMargen >= 15 ? '#15803D' : ($totalMargen >= 0 ? '#854D0E' : '#DC2626') }}">
            {{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}
        </div>
    </div>
</div>

{{-- CONTROLES --}}
@php
    $responsables = collect($proyectosData)
        ->pluck('responsable_obra')
        ->filter()
        ->unique()
        ->sort()
        ->values();
@endphp
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="buscador-er" type="text" oninput="filtrarER()" placeholder="Buscar código o nombre de OT…"
            style="padding:7px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:240px">
        <select id="resp-er" onchange="filtrarER()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Todos los responsables</option>
            @foreach($responsables as $r)
                <option value="{{ $r }}">{{ $r }}</option>
            @endforeach
            <option value="__sin__">— Sin responsable —</option>
        </select>
        <select id="orden-er" onchange="ordenarSelect()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Ordenar por…</option>
            <option value="util-desc">Mayor utilidad</option>
            <option value="util-asc">Menor utilidad</option>
            <option value="margen-desc">Mayor margen</option>
            <option value="margen-asc">Menor margen</option>
            <option value="codigo-asc">Código A–Z</option>
        </select>
        <span id="contador-er" style="font-size:12px;color:#9CA3AF"></span>
    </div>
    <div style="display:flex;gap:6px;align-items:center">
        <span style="font-size:12px;color:#6B7280;margin-right:4px">Vista:</span>
        <button type="button" id="btn-nivel-1" onclick="setNivel(1)">Nivel 1 · resumen</button>
        <button type="button" id="btn-nivel-2" onclick="setNivel(2)">Nivel 2 · detalle</button>
    </div>
</div>

{{-- TABLA PRINCIPAL --}}
<div class="card" style="overflow-x:auto">
    <table id="tabla-er" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th onclick="ordenarCol('codigo')" style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;min-width:200px;cursor:pointer;user-select:none">Proyecto / OT<span id="arr-codigo"></span></th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta mayor</th>
                <th onclick="ordenarCol('util')" style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer;user-select:none">Estado de resultados<span id="arr-util"></span></th>
                <th onclick="ordenarCol('margen')" style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer;user-select:none">% MC Real<span id="arr-margen"></span></th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">MC ofertado</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">KPI</th>
            </tr>
        </thead>

        @forelse($proyectosData as $cod => $p)
            @if($p['ingreso'] != 0 || $p['costo_aplicado'] != 0 || $p['costo_por_aplicar'] != 0)
            <tbody class="grupo"
                data-cod="{{ strtolower($p['codigo']) }}"
                data-nombre="{{ strtolower($p['nombre']) }}"
                data-util="{{ $p['utilidad'] }}"
                data-margen="{{ $p['margen_pct'] !== null ? $p['margen_pct'] : '' }}"
                data-resp="{{ $p['responsable_obra'] }}">

                {{-- Fila resumen --}}
                <tr class="fila-resumen" onclick="toggleProyecto('{{ $cod }}')" style="background:#F9FAFB;cursor:pointer">
                    <td style="padding:8px 10px;border-bottom:1px solid #E5E7EB;font-weight:600;color:#1B3F6E">
                        <span class="chev" data-cod="{{ $cod }}" style="display:inline-block;width:14px;color:#9CA3AF">▸</span>
                        {{ $p['codigo'] }} - {{ $p['nombre'] }}
                        @if($p['alerta'])
                            <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:6px">Sin ingreso</span>
                        @endif
                        @if(!empty($p['responsable_obra']))
                            <div style="font-size:10px;color:#9CA3AF;font-weight:400;margin-top:2px;margin-left:14px">Resp: {{ $p['responsable_obra'] }}</div>
                        @endif
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #E5E7EB;font-size:11px;font-weight:600;color:{{ $p['utilidad'] >= 0 ? '#16A34A' : '#DC2626' }}">
                        {{ $p['utilidad'] >= 0 ? 'Utilidad' : 'Pérdida' }}
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #E5E7EB;text-align:right;font-weight:600;color:{{ $p['utilidad'] >= 0 ? '#15803D' : '#DC2626' }}">
                        ${{ number_format($p['utilidad'], 0, ',', '.') }}
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #E5E7EB;text-align:right">
                        {{ $p['margen_pct'] !== null ? $p['margen_pct'] . '%' : 'N/A' }}
                    </td>
                    <td onclick="event.stopPropagation(); abrirComparativo('{{ $cod }}')"
                        style="padding:8px 10px;border-bottom:1px solid #E5E7EB;text-align:right;cursor:pointer">
                        @if($p['mc_ofertado'] !== null)
                            <span style="color:#6B7280">{{ $p['mc_ofertado'] }}%</span>
                            @if($p['cumple'] === true)
                                <span style="color:#16A34A;font-weight:700;margin-left:5px">✓</span>
                            @elseif($p['cumple'] === false)
                                <span style="color:#DC2626;font-weight:700;margin-left:5px">✗</span>
                            @endif
                        @else
                            <span style="color:#D1D5DB">—</span>
                        @endif
                    </td>
                    <td style="padding:8px 10px;border-bottom:1px solid #E5E7EB;text-align:center">
                        @if($p['margen_pct'] === null)
                            <span style="color:#DC2626;font-size:16px">●</span>
                        @elseif($p['margen_pct'] >= 15)
                            <span style="color:#16A34A;font-size:16px">●</span>
                        @elseif($p['margen_pct'] >= 5)
                            <span style="color:#D97706;font-size:16px">●</span>
                        @else
                            <span style="color:#DC2626;font-size:16px">●</span>
                        @endif
                    </td>
                </tr>

                {{-- Filas detalle (colapsables) --}}
                @if($p['ingreso'] != 0)
                <tr class="det det-{{ $cod }}" style="display:none;background:white">
                    <td style="padding:6px 10px 6px 30px;border-bottom:1px solid #F3F4F6;color:#6B7280;font-size:11px">↳ Ingreso</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#059669;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Ingreso', '{{ $p['nombre'] }}')">
                        Ingreso ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#059669">${{ number_format($p['ingreso'], 0, ',', '.') }}</td>
                    <td colspan="3" style="border-bottom:1px solid #F3F4F6"></td>
                </tr>
                @endif
                @if($p['costo_aplicado'] != 0)
                <tr class="det det-{{ $cod }}" style="display:none;background:white">
                    <td style="padding:6px 10px 6px 30px;border-bottom:1px solid #F3F4F6;color:#6B7280;font-size:11px">↳ Costos aplicados</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#DC2626;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Costos aplicados', '{{ $p['nombre'] }}')">
                        Costos aplicados ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#DC2626">-${{ number_format(abs($p['costo_aplicado']), 0, ',', '.') }}</td>
                    <td colspan="3" style="border-bottom:1px solid #F3F4F6"></td>
                </tr>
                @endif
                @if($p['costo_por_aplicar'] != 0)
                <tr class="det det-{{ $cod }}" style="display:none;background:white">
                    <td style="padding:6px 10px 6px 30px;border-bottom:1px solid #F3F4F6;color:#6B7280;font-size:11px">↳ Costos por aplicar</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#D97706;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Costos por aplicar', '{{ $p['nombre'] }}')">
                        Costos por aplicar ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#D97706">-${{ number_format(abs($p['costo_por_aplicar']), 0, ',', '.') }}</td>
                    <td colspan="3" style="border-bottom:1px solid #F3F4F6"></td>
                </tr>
                @endif
            </tbody>
            @endif
        @empty
            <tbody>
                <tr>
                    <td colspan="6" style="text-align:center;padding:2rem;color:#9CA3AF">
                        No hay datos para el período seleccionado
                    </td>
                </tr>
            </tbody>
        @endforelse

        <tbody id="tbody-total">
            <tr style="background:#1B3F6E">
                <td colspan="2" style="padding:10px;color:white;font-weight:600">Total general</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalUtilidad, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">{{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}</td>
                <td style="padding:10px"></td>
                <td style="padding:10px;text-align:center">
                    @if($totalMargen === null)
                        <span style="color:#FCA5A5;font-size:16px">●</span>
                    @elseif($totalMargen >= 15)
                        <span style="color:#86EFAC;font-size:16px">●</span>
                    @elseif($totalMargen >= 5)
                        <span style="color:#FDE68A;font-size:16px">●</span>
                    @else
                        <span style="color:#FCA5A5;font-size:16px">●</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{-- MODAL DRILL-DOWN --}}
<div id="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:1.5rem;width:90%;max-width:900px;max-height:80vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="modal-title" style="font-size:15px;font-weight:600;color:#1B3F6E"></h3>
            <button onclick="cerrarModal()" style="font-size:20px;background:none;border:none;cursor:pointer;color:#6B7280">×</button>
        </div>
        <div id="modal-body"></div>
    </div>
</div>

{{-- MODAL COMPARATIVO OFERTADO VS REAL --}}
<div id="modal-comp" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1001;align-items:center;justify-content:center" onclick="if(event.target===this)cerrarComp()">
    <div style="background:white;border-radius:12px;padding:1.5rem;width:90%;max-width:620px;max-height:80vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="comp-title" style="font-size:15px;font-weight:600;color:#1B3F6E"></h3>
            <button onclick="cerrarComp()" style="font-size:20px;background:none;border:none;cursor:pointer;color:#6B7280">×</button>
        </div>
        <div id="comp-body"></div>
    </div>
</div>

<script>
const filtros = { anio: '{{ $anio }}', mes: '{{ $mes }}', modo: '{{ $modo }}' };

let nivelActual = 1;

function estiloNivel() {
    const base = 'font-size:12px;padding:5px 12px;border-radius:8px;cursor:pointer;';
    const act  = 'background:#1B3F6E;color:white;border:1px solid #1B3F6E;';
    const ina  = 'background:white;color:#6B7280;border:1px solid #E5E7EB;';
    document.getElementById('btn-nivel-1').style.cssText = base + (nivelActual === 1 ? act : ina);
    document.getElementById('btn-nivel-2').style.cssText = base + (nivelActual === 2 ? act : ina);
}

function setNivel(n) {
    nivelActual = n;
    document.querySelectorAll('.det').forEach(r => r.style.display = (n === 2) ? 'table-row' : 'none');
    document.querySelectorAll('.chev').forEach(c => c.textContent = (n === 2) ? '▾' : '▸');
    estiloNivel();
}

function toggleProyecto(cod) {
    const dets = document.querySelectorAll('.det-' + cod);
    if (dets.length === 0) return;
    const abierto = dets[0].style.display !== 'none';
    dets.forEach(r => r.style.display = abierto ? 'none' : 'table-row');
    const chev = document.querySelector('.chev[data-cod="' + cod + '"]');
    if (chev) chev.textContent = abierto ? '▸' : '▾';
}

function toggleAlerta() {
    const l = document.getElementById('lista-alerta');
    const b = document.getElementById('btn-alerta');
    const abierto = l.style.display !== 'none';
    l.style.display = abierto ? 'none' : 'block';
    b.textContent = abierto ? 'Ver proyectos' : 'Ocultar';
}

// ── Buscar y filtrar (texto + responsable) ──
function filtrarER() {
    const q = document.getElementById('buscador-er').value.toLowerCase().trim();
    const resp = document.getElementById('resp-er').value;
    let n = 0;
    document.querySelectorAll('#tabla-er tbody.grupo').forEach(g => {
        const okTexto = g.dataset.cod.includes(q) || g.dataset.nombre.includes(q);
        const okResp = !resp
            ? true
            : (resp === '__sin__' ? !g.dataset.resp : g.dataset.resp === resp);
        const ok = okTexto && okResp;
        g.style.display = ok ? '' : 'none';
        if (ok) n++;
    });
    document.getElementById('contador-er').textContent = n + ' proyecto' + (n === 1 ? '' : 's');
}

let ordenActual = { clave: null, dir: null };

function aplicarOrden(clave, dir) {
    const tabla = document.getElementById('tabla-er');
    const total = document.getElementById('tbody-total');
    const grupos = Array.from(tabla.querySelectorAll('tbody.grupo'));
    grupos.sort((a, b) => {
        if (clave === 'codigo') {
            return dir === 'asc' ? a.dataset.cod.localeCompare(b.dataset.cod) : b.dataset.cod.localeCompare(a.dataset.cod);
        }
        const attr = clave === 'util' ? 'util' : 'margen';
        let va = a.dataset[attr] === '' ? null : parseFloat(a.dataset[attr]);
        let vb = b.dataset[attr] === '' ? null : parseFloat(b.dataset[attr]);
        if (va === null && vb === null) return 0;
        if (va === null) return 1;
        if (vb === null) return -1;
        return dir === 'asc' ? va - vb : vb - va;
    });
    grupos.forEach(g => tabla.insertBefore(g, total));
    ordenActual = { clave, dir };
    actualizarFlechas();
}

function actualizarFlechas() {
    ['codigo', 'util', 'margen'].forEach(k => {
        const el = document.getElementById('arr-' + k);
        if (!el) return;
        el.textContent = (ordenActual.clave === k) ? (ordenActual.dir === 'asc' ? ' ▲' : ' ▼') : '';
    });
}

function ordenarCol(clave) {
    let dir;
    if (ordenActual.clave === clave) dir = ordenActual.dir === 'asc' ? 'desc' : 'asc';
    else dir = (clave === 'codigo') ? 'asc' : 'desc';
    aplicarOrden(clave, dir);
    document.getElementById('orden-er').value = '';
}

function ordenarSelect() {
    const v = document.getElementById('orden-er').value;
    if (!v) return;
    const [clave, dir] = v.split('-');
    aplicarOrden(clave, dir);
}

// ── Comparativo ofertado vs real ──
function abrirComparativo(codigo) {
    document.getElementById('comp-title').textContent = 'Ofertado vs real (acumulado) — ' + codigo;
    document.getElementById('comp-body').innerHTML = '<p style="color:#9CA3AF;font-size:13px">Cargando…</p>';
    document.getElementById('modal-comp').style.display = 'flex';

    fetch('/financiero/comparativo?codigo=' + encodeURIComponent(codigo))
        .then(r => r.json())
        .then(d => {
            const r = d.real;
            const o = d.ofertado || { ingreso: null, costo: null, utilidad: null, margen: null };
            const money = v => (v === null || v === undefined || isNaN(v)) ? '—' : '$' + Math.round(v).toLocaleString('es-CO');
            const perc  = v => (v === null || v === undefined || isNaN(v)) ? '—' : (Math.round(v * 100) / 100) + '%';

            function fila(lbl, of, re, esMoney, mejorAlto) {
                const ofTxt = esMoney ? money(of) : perc(of);
                const reTxt = esMoney ? money(re) : perc(re);
                let difTxt = '—', col = '#9CA3AF';
                const valido = of !== null && of !== undefined && re !== null && re !== undefined && !isNaN(of) && !isNaN(re);
                if (valido) {
                    const dif = re - of;
                    const bueno = mejorAlto ? dif >= 0 : dif <= 0;
                    col = bueno ? '#16A34A' : '#DC2626';
                    if (esMoney) difTxt = (dif >= 0 ? '+' : '-') + '$' + Math.round(Math.abs(dif)).toLocaleString('es-CO');
                    else difTxt = (dif >= 0 ? '+' : '-') + (Math.round(Math.abs(dif) * 100) / 100) + 'pp';
                }
                return `<tr>
                    <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#374151">${lbl}</td>
                    <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#6B7280">${ofTxt}</td>
                    <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;color:#1B3F6E">${reTxt}</td>
                    <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:right;font-weight:600;color:${col}">${difTxt}</td>
                </tr>`;
            }

            let html = '';
            if (!d.ofertado) {
                html += '<div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:#854D0E">Este proyecto no está en el maestro comercial — solo se muestra el real.</div>';
            }
            html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
            html += '<thead><tr style="background:#F3F4F6">'
                  + '<th style="text-align:left;padding:7px 10px;color:#6B7280;font-size:11px">Concepto</th>'
                  + '<th style="text-align:right;padding:7px 10px;color:#6B7280;font-size:11px">Ofertado</th>'
                  + '<th style="text-align:right;padding:7px 10px;color:#6B7280;font-size:11px">Real acumulado</th>'
                  + '<th style="text-align:right;padding:7px 10px;color:#6B7280;font-size:11px">Diferencia</th>'
                  + '</tr></thead><tbody>';
            html += fila('Ingreso',  o.ingreso,  r.ingreso,  true,  true);
            html += fila('Costo',    o.costo,    r.costo,    true,  false);
            html += fila('Utilidad', o.utilidad, r.utilidad, true,  true);
            html += fila('Margen',   o.margen,   r.margen,   false, true);
            html += '</tbody></table>';
            html += '<div style="font-size:10px;color:#9CA3AF;margin-top:8px">El real es el acumulado de toda la vida del proyecto, sin filtro de mes. "—" donde comercial no cargó el dato.</div>';

            document.getElementById('comp-body').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('comp-body').innerHTML = '<p style="color:#DC2626;font-size:13px">Error cargando.</p>';
        });
}

function cerrarComp() { document.getElementById('modal-comp').style.display = 'none'; }

function abrirDetalle(codigo, cuentaMayor, nombreProyecto) {
    document.getElementById('modal-title').textContent = codigo + ' — ' + cuentaMayor;
    document.getElementById('modal-body').innerHTML = '<p style="color:#9CA3AF;font-size:13px">Cargando...</p>';
    document.getElementById('modal-overlay').style.display = 'flex';

    fetch(`/financiero/detalle?codigo=${codigo}&cuenta_mayor=${encodeURIComponent(cuentaMayor)}&anio=${filtros.anio}&mes=${filtros.mes}&modo=${filtros.modo}`)
        .then(r => r.json())
        .then(data => {
            if (!data.detalle || data.detalle.length === 0) {
                document.getElementById('modal-body').innerHTML = '<p style="color:#9CA3AF;font-size:13px">Sin registros.</p>';
                return;
            }

            let html = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
            html += '<thead><tr style="background:#F3F4F6">';
            html += '<th style="text-align:left;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta</th>';
            html += '<th style="text-align:left;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Descripción</th>';
            html += '<th style="text-align:right;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Débito</th>';
            html += '<th style="text-align:right;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Crédito</th>';
            html += '<th style="text-align:right;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Neto ER</th>';
            html += '<th style="text-align:center;padding:7px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Períodos</th>';
            html += '</tr></thead><tbody>';

            let totalER = 0;
            let alertasCuentas = [];

            data.detalle.forEach((row, index) => {
                const er = parseFloat(row.total_er);
                const debito = parseFloat(row.total_debito);
                const credito = parseFloat(row.total_credito);
                totalER += er;
                const color = er >= 0 ? '#059669' : '#DC2626';

                const desbalance = debito > 0 && credito === 0 ? 'Solo débito'
                    : credito > 0 && debito === 0 ? 'Solo crédito' : null;

                let alertaFila = '';
                if (desbalance) {
                    const bgColor = desbalance === 'Solo débito' ? '#FEF9C3' : '#FEF2F2';
                    const txtColor = desbalance === 'Solo débito' ? '#854D0E' : '#DC2626';
                    alertaFila = `<span style="background:${bgColor};color:${txtColor};font-size:10px;padding:1px 6px;border-radius:8px;margin-left:6px">${desbalance}</span>`;
                    alertasCuentas.push(row.cuenta_contable);
                }

                const rowId = 'detalle-' + index;

                html += `<tr style="${desbalance ? 'background:#FFFBEB' : ''}">
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-size:11px">${row.cuenta_contable}${alertaFila}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6">${row.descripcion || ''}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right">$${Number(debito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right">$${Number(credito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:${color};font-weight:500">$${Math.abs(er).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:center">
                        <button onclick="togglePeriodos('${rowId}', '${data.codigo}', '${encodeURIComponent(row.cuenta_contable)}')"
                            style="font-size:10px;padding:2px 8px;border:1px solid #E5E7EB;border-radius:6px;cursor:pointer;background:white;color:#6B7280">
                            Ver períodos
                        </button>
                    </td>
                </tr>
                <tr id="${rowId}" style="display:none;background:#F9FAFB">
                    <td colspan="6" style="padding:8px 16px;border-bottom:1px solid #E5E7EB">
                        <div id="${rowId}-content" style="font-size:11px;color:#9CA3AF">Cargando...</div>
                    </td>
                </tr>`;
            });

            html += `<tr style="background:#1B3F6E">
                <td colspan="5" style="padding:8px 10px;color:white;font-weight:600">Total</td>
                <td style="padding:8px 10px;text-align:right;color:white;font-weight:600">$${Math.abs(totalER).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
            </tr>`;
            html += '</tbody></table>';

            if (alertasCuentas.length > 0) {
                html = `<div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:12px;color:#854D0E">
                    <strong>Alerta:</strong> ${alertasCuentas.length} cuenta(s) con movimiento en un solo sentido — ${alertasCuentas.join(', ')}
                </div>` + html;
            }

            document.getElementById('modal-body').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('modal-body').innerHTML = '<p style="color:#DC2626;font-size:13px">Error cargando datos.</p>';
        });
}

function togglePeriodos(rowId, codigo, cuentaEncoded) {
    const row = document.getElementById(rowId);
    const content = document.getElementById(rowId + '-content');
    if (row.style.display !== 'none') { row.style.display = 'none'; return; }
    row.style.display = 'table-row';
    const cuenta = decodeURIComponent(cuentaEncoded);
    const meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

    fetch(`/financiero/detalle-cuenta?codigo=${codigo}&cuenta=${encodeURIComponent(cuenta)}&anio=${filtros.anio}&mes=${filtros.mes}&modo=${filtros.modo}`)
        .then(r => r.json())
        .then(data => {
            if (!data.periodos || data.periodos.length === 0) { content.innerHTML = 'Sin movimientos por período.'; return; }
            let html = '<table style="width:100%;border-collapse:collapse;font-size:11px">';
            html += '<tr style="background:#E5E7EB"><th style="padding:4px 8px;text-align:left;color:#6B7280">Período</th><th style="padding:4px 8px;text-align:right;color:#6B7280">Débito</th><th style="padding:4px 8px;text-align:right;color:#6B7280">Crédito</th><th style="padding:4px 8px;text-align:right;color:#6B7280">Neto ER</th></tr>';
            data.periodos.forEach(p => {
                const er = parseFloat(p.total_er);
                const color = er >= 0 ? '#059669' : '#DC2626';
                html += `<tr>
                    <td style="padding:4px 8px;border-bottom:1px solid #F3F4F6">${meses[parseInt(p.mes)]} ${p.anio}</td>
                    <td style="padding:4px 8px;border-bottom:1px solid #F3F4F6;text-align:right">$${Number(p.total_debito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:4px 8px;border-bottom:1px solid #F3F4F6;text-align:right">$${Number(p.total_credito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:4px 8px;border-bottom:1px solid #F3F4F6;text-align:right;color:${color};font-weight:500">$${Math.abs(er).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                </tr>`;
            });
            html += '</table>';
            content.innerHTML = html;
        });
}

function cerrarModal() { document.getElementById('modal-overlay').style.display = 'none'; }

document.getElementById('modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});

estiloNivel();
filtrarER();
</script>
@endsection