@extends('layouts.app')

@section('title', 'Históricos de proyectos')

@section('content')
<x-page-banner title="Históricos — Proyectos cerrados" icon="📁">
    Consulta el resultado final de las obras ya cerradas y detecta inconsistencias contables.
</x-page-banner>

{{-- FILTROS --}}
<x-filtros-panel>
    <form method="GET" action="{{ route('financiero.historicos') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div class="filtro-field" style="flex:2 1 240px">
            <label class="filtro-label">Proyecto</label>
            <select name="proyecto" class="filtro-select" style="min-width:240px">
                <option value="">Todos los proyectos cerrados</option>
                @foreach($proyectos as $p)
                    <option value="{{ $p->codigo_proyecto }}" {{ $proyecto == $p->codigo_proyecto ? 'selected' : '' }}>
                        {{ $p->codigo_proyecto }} - {{ $p->nombre_proyecto }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
        <a href="{{ route('financiero.historicos') }}" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:flex;align-items:center">
            Limpiar
        </a>
    </form>
</x-filtros-panel>

{{-- ALERTAS GLOBALES (compacto) --}}
@php
    $conAlerta = array_filter($proyectosData, fn($p) =>
        $p['alerta_saldo14_negativo'] || $p['alerta_saldo14_positivo'] || $p['alerta_sin_ingreso']
    );
@endphp
@if(count($conAlerta) > 0)
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div style="font-size:13px;color:#DC2626">
        <strong>⚠ Alerta contable:</strong> {{ count($conAlerta) }} proyecto(s) cerrado(s) con inconsistencias.
    </div>
    <button type="button" onclick="verAfectados()"
        style="font-size:12px;padding:6px 14px;border:1px solid #FECACA;border-radius:8px;background:white;cursor:pointer;color:#DC2626;white-space:nowrap">
        Ver afectados
    </button>
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

{{-- CONTROLES: buscador + filtro de alerta + orden --}}
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="buscador-hist" type="text" oninput="filtrarHist()" placeholder="Buscar código o nombre…"
            style="padding:7px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:220px">
        <select id="alerta-hist" onchange="filtrarHist()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Todas las alertas</option>
            <option value="con">Con cualquier alerta</option>
            <option value="s14neg">Saldo 14 pendiente</option>
            <option value="s14pos">Reversión excesiva</option>
            <option value="sining">Sin ingreso</option>
            <option value="ok">Sin alerta (OK)</option>
        </select>
        <select id="orden-hist" onchange="ordenarHistSelect()"
            style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Ordenar por…</option>
            <option value="util-desc">Mayor utilidad</option>
            <option value="util-asc">Menor utilidad</option>
            <option value="margen-desc">Mayor margen</option>
            <option value="margen-asc">Menor margen</option>
            <option value="codigo-asc">Código A–Z</option>
        </select>
        <span id="contador-hist" style="font-size:12px;color:#9CA3AF"></span>
    </div>
    <a href="{{ route('contable.plano-reversion.index') }}"
        style="padding:7px 16px;background:white;color:#1B3F6E;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;text-decoration:none;white-space:nowrap">
        🔁 Cuentas 14 con saldo contrario →
    </a>
</div>

{{-- TABLA --}}
<div class="card" style="overflow-x:auto">
    <table id="tabla-historicos" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(0)">
                    Proyecto / OT <span id="sort-0">↕</span>
                </th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(1)">
                    Fecha cierre <span id="sort-1">↕</span>
                </th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(2)">
                    Tipo <span id="sort-2">↕</span>
                </th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">
                    Alertas
                </th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(4)">
                    Ingresos <span id="sort-4">↕</span>
                </th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(5)">
                    Costos aplicados <span id="sort-5">↕</span>
                </th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(6)">
                    Costos x aplicar <span id="sort-6">↕</span>
                </th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(7)">
                    Utilidad <span id="sort-7">↕</span>
                </th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;cursor:pointer" onclick="ordenarTabla(8)">
                    % MC <span id="sort-8">↕</span>
                </th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">
                    KPI
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse($proyectosData as $cod => $p)
            @php
                $colorMC = $p['margen_pct'] === null ? '#DC2626' : ($p['margen_pct'] >= 15 ? '#16A34A' : ($p['margen_pct'] >= 5 ? '#D97706' : '#DC2626'));
                $tieneAlerta = $p['alerta_saldo14_negativo'] || $p['alerta_saldo14_positivo'] || $p['alerta_sin_ingreso'];
            @endphp
            <tr class="fila-hist"
                data-cod="{{ strtolower($p['codigo']) }}"
                data-nombre="{{ strtolower($p['nombre']) }}"
                data-util="{{ $p['utilidad'] }}"
                data-margen="{{ $p['margen_pct'] !== null ? $p['margen_pct'] : '' }}"
                data-s14neg="{{ $p['alerta_saldo14_negativo'] ? 1 : 0 }}"
                data-s14pos="{{ $p['alerta_saldo14_positivo'] ? 1 : 0 }}"
                data-sining="{{ $p['alerta_sin_ingreso'] ? 1 : 0 }}"
                data-alerta="{{ $tieneAlerta ? 1 : 0 }}"
                style="border-bottom:1px solid #F3F4F6;background:{{ $tieneAlerta ? '#FFFBEB' : 'white' }}">
                <td style="padding:7px 10px;font-weight:500;color:#1B3F6E">
                    {{ $p['codigo'] }}<br>
                    <span style="font-size:10px;color:#6B7280;font-weight:400">{{ $p['nombre'] }}</span>
                </td>
                <td style="padding:7px 10px;font-size:11px;color:#6B7280">{{ $p['fecha_cierre'] }}</td>
                <td style="padding:7px 10px">
                    @if($p['tipo_cierre'] === 'total')
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 8px;border-radius:10px">Total</span>
                    @elseif($p['tipo_cierre'] === 'parcial')
                        <span style="background:#FEF9C3;color:#854D0E;font-size:10px;padding:2px 8px;border-radius:10px">Parcial</span>
                    @else
                        <span style="font-size:10px;color:#9CA3AF">—</span>
                    @endif
                </td>
                <td style="padding:7px 10px">
                    @if($p['alerta_saldo14_negativo'])
                        <span style="background:#FEF9C3;color:#854D0E;font-size:10px;padding:2px 6px;border-radius:8px;display:inline-block;margin-bottom:2px"
                              title="Costos pendientes sin aplicar: ${{ number_format(abs($p['saldo14']), 0, ',', '.') }}">
                            ⚠ Saldo 14 pendiente: ${{ number_format(abs($p['saldo14']), 0, ',', '.') }}
                        </span>
                    @endif
                    @if($p['alerta_saldo14_positivo'])
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 6px;border-radius:8px;display:inline-block;margin-bottom:2px"
                              title="Reversión excesiva: ${{ number_format($p['saldo14'], 0, ',', '.') }}">
                            ⚠ Reversión excesiva: ${{ number_format($p['saldo14'], 0, ',', '.') }}
                        </span>
                    @endif
                    @if($p['alerta_sin_ingreso'])
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 6px;border-radius:8px;display:inline-block">
                            ⚠ Sin ingreso registrado
                        </span>
                    @endif
                    @if(!$tieneAlerta)
                        <span style="color:#15803D;font-size:11px">✓ OK</span>
                    @endif
                </td>
                <td style="padding:7px 10px;text-align:right;color:#059669;cursor:pointer"
                    onclick="abrirDetalle('{{ $cod }}', 'Ingreso', '{{ $p['nombre'] }}')">
                    ${{ number_format($p['ingreso'], 0, ',', '.') }} ↗
                </td>
                <td style="padding:7px 10px;text-align:right;color:#DC2626;cursor:pointer"
                    onclick="abrirDetalle('{{ $cod }}', 'Costos aplicados', '{{ $p['nombre'] }}')">
                    {{ abs($p['costo_aplicado']) > 0 ? '-$'.number_format(abs($p['costo_aplicado']), 0, ',', '.') : '$0' }} ↗
                </td>
                <td style="padding:7px 10px;text-align:right;color:#D97706;cursor:pointer"
                    onclick="abrirDetalle('{{ $cod }}', 'Costos por aplicar', '{{ $p['nombre'] }}')">
                    {{ abs($p['costo_por_aplicar']) > 0 ? '-$'.number_format(abs($p['costo_por_aplicar']), 0, ',', '.') : '$0' }} ↗
                </td>
                <td style="padding:7px 10px;text-align:right;font-weight:600;color:{{ $p['utilidad'] >= 0 ? '#15803D' : '#DC2626' }}">
                    ${{ number_format($p['utilidad'], 0, ',', '.') }}
                </td>
                <td style="padding:7px 10px;text-align:right;font-weight:600;color:{{ $colorMC }}">
                    {{ $p['margen_pct'] !== null ? $p['margen_pct'] . '%' : 'N/A' }}
                </td>
                <td style="padding:7px 10px;text-align:center">
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
            @empty
            <tr>
                <td colspan="10" style="text-align:center;padding:2rem;color:#9CA3AF">
                    No hay proyectos cerrados registrados. Ve a Contable → Cierre de obras para registrarlos.
                </td>
            </tr>
            @endforelse

            @if(count($proyectosData) > 0)
            <tr id="fila-total-hist" style="background:#1B3F6E">
                <td colspan="4" style="padding:10px;color:white;font-weight:600">Total general</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalIngreso, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">-${{ number_format(abs($totalCostoAplicado), 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">{{ abs($totalCostoPorAplicar) > 0 ? '-$'.number_format(abs($totalCostoPorAplicar), 0, ',', '.') : '$0' }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalUtilidad, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">{{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}</td>
                <td style="padding:10px;text-align:center">
                    @if($totalMargen >= 15)
                        <span style="color:#86EFAC;font-size:16px">●</span>
                    @elseif($totalMargen >= 5)
                        <span style="color:#FDE68A;font-size:16px">●</span>
                    @else
                        <span style="color:#FCA5A5;font-size:16px">●</span>
                    @endif
                </td>
            </tr>
            @endif
        </tbody>
    </table>
</div>

{{-- MODAL DETALLE --}}
<div id="modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:1.5rem;width:90%;max-width:900px;max-height:80vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 id="modal-title" style="font-size:15px;font-weight:600;color:#1B3F6E"></h3>
            <button onclick="cerrarModal()" style="font-size:20px;background:none;border:none;cursor:pointer;color:#6B7280">×</button>
        </div>
        <div id="modal-body"></div>
    </div>
</div>

<script>
// ── Buscar + filtro de alerta ──
function filtrarHist() {
    const q  = document.getElementById('buscador-hist').value.toLowerCase().trim();
    const al = document.getElementById('alerta-hist').value;
    let n = 0;
    document.querySelectorAll('#tabla-historicos tbody tr.fila-hist').forEach(f => {
        const okTexto = f.dataset.cod.includes(q) || f.dataset.nombre.includes(q);
        let okAlerta = true;
        if (al === 'con')         okAlerta = f.dataset.alerta === '1';
        else if (al === 's14neg') okAlerta = f.dataset.s14neg === '1';
        else if (al === 's14pos') okAlerta = f.dataset.s14pos === '1';
        else if (al === 'sining') okAlerta = f.dataset.sining === '1';
        else if (al === 'ok')     okAlerta = f.dataset.alerta === '0';
        const ok = okTexto && okAlerta;
        f.style.display = ok ? '' : 'none';
        if (ok) n++;
    });
    document.getElementById('contador-hist').textContent = n + ' proyecto' + (n === 1 ? '' : 's');
}

// ── Botón "Ver afectados" del banner ──
function verAfectados() {
    const sel = document.getElementById('alerta-hist');
    sel.value = 'con';
    filtrarHist();
    document.getElementById('tabla-historicos').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Orden por selector (data-attributes, confiable) ──
function aplicarOrdenHist(clave, dir) {
    const tbody = document.querySelector('#tabla-historicos tbody');
    const total = document.getElementById('fila-total-hist');
    const filas = Array.from(tbody.querySelectorAll('tr.fila-hist'));
    filas.sort((a, b) => {
        if (clave === 'codigo') {
            return dir === 'asc' ? a.dataset.cod.localeCompare(b.dataset.cod) : b.dataset.cod.localeCompare(a.dataset.cod);
        }
        let va = a.dataset[clave] === '' ? null : parseFloat(a.dataset[clave]);
        let vb = b.dataset[clave] === '' ? null : parseFloat(b.dataset[clave]);
        if (va === null && vb === null) return 0;
        if (va === null) return 1;
        if (vb === null) return -1;
        return dir === 'asc' ? va - vb : vb - va;
    });
    filas.forEach(f => tbody.insertBefore(f, total || null));
}

function ordenarHistSelect() {
    const v = document.getElementById('orden-hist').value;
    if (!v) return;
    const [clave, dir] = v.split('-');
    aplicarOrdenHist(clave, dir);
    document.querySelectorAll('[id^="sort-"]').forEach(el => el.textContent = '↕');
}

// ── Orden por clic en encabezados (texto, ya existía) ──
let sortDir = {};
function ordenarTabla(col) {
    const tabla = document.querySelector('#tabla-historicos tbody');
    const filas = Array.from(tabla.querySelectorAll('tr.fila-hist'));

    document.querySelectorAll('[id^="sort-"]').forEach(el => el.textContent = '↕');
    sortDir[col] = sortDir[col] === 'asc' ? 'desc' : 'asc';
    const dir = sortDir[col];
    const flecha = document.getElementById('sort-' + col);
    if (flecha) flecha.textContent = dir === 'asc' ? '↑' : '↓';

    filas.sort((a, b) => {
        const celdaA = a.querySelectorAll('td')[col];
        const celdaB = b.querySelectorAll('td')[col];
        if (!celdaA || !celdaB) return 0;

        let valA = celdaA.textContent.trim().replace(/[$,.%↗\-]/g, '').trim();
        let valB = celdaB.textContent.trim().replace(/[$,.%↗\-]/g, '').trim();

        const numA = parseFloat(valA);
        const numB = parseFloat(valB);

        if (!isNaN(numA) && !isNaN(numB)) {
            return dir === 'asc' ? numA - numB : numB - numA;
        }
        return dir === 'asc' ? valA.localeCompare(valB, 'es') : valB.localeCompare(valA, 'es');
    });

    const total = document.getElementById('fila-total-hist');
    filas.forEach(f => tabla.insertBefore(f, total || null));
}

function abrirDetalle(codigo, cuentaMayor, nombreProyecto) {
    document.getElementById('modal-title').textContent = codigo + ' — ' + cuentaMayor;
    document.getElementById('modal-body').innerHTML = '<p style="color:#9CA3AF;font-size:13px">Cargando...</p>';
    document.getElementById('modal-overlay').style.display = 'flex';

    fetch(`/financiero/historicos/detalle?codigo=${codigo}&cuenta_mayor=${encodeURIComponent(cuentaMayor)}`)
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

                const desbalance = debito > 0 && credito === 0
                    ? 'Solo débito'
                    : credito > 0 && debito === 0
                    ? 'Solo crédito'
                    : null;

                let alertaFila = '';
                if (desbalance) {
                    const bgColor = desbalance === 'Solo débito' ? '#FEF9C3' : '#FEF2F2';
                    const txtColor = desbalance === 'Solo débito' ? '#854D0E' : '#DC2626';
                    alertaFila = `<span style="background:${bgColor};color:${txtColor};font-size:10px;padding:1px 6px;border-radius:8px;margin-left:6px">${desbalance}</span>`;
                    alertasCuentas.push(row.cuenta_contable);
                }

                const rowId = 'det-' + index;
                html += `<tr style="${desbalance ? 'background:#FFFBEB' : ''}">
                    <td style="padding:6px 10px;font-family:monospace;font-size:11px">${row.cuenta_contable}${alertaFila}</td>
                    <td style="padding:6px 10px">${row.descripcion || ''}</td>
                    <td style="padding:6px 10px;text-align:right">$${Number(debito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;text-align:right">$${Number(credito).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;text-align:right;color:${color};font-weight:500">$${Math.abs(er).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                    <td style="padding:6px 10px;text-align:center">
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
                <td colspan="4" style="padding:8px 10px;color:white;font-weight:600">Total</td>
                <td style="padding:8px 10px;text-align:right;color:white;font-weight:600">$${Math.abs(totalER).toLocaleString('es-CO',{maximumFractionDigits:0})}</td>
                <td></td>
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

    if (row.style.display !== 'none') {
        row.style.display = 'none';
        return;
    }

    row.style.display = 'table-row';
    const cuenta = decodeURIComponent(cuentaEncoded);
    const meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

    fetch(`/financiero/historicos/detalle-cuenta?codigo=${codigo}&cuenta=${encodeURIComponent(cuenta)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.periodos || data.periodos.length === 0) {
                content.innerHTML = 'Sin movimientos por período.';
                return;
            }
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

function cerrarModal() {
    document.getElementById('modal-overlay').style.display = 'none';
}

document.getElementById('modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});

filtrarHist();
</script>
@endsection