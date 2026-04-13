@extends('layouts.app')

@section('title', 'Estado de resultados')

@section('content')
<h1 class="page-title">Estado de resultados por proyecto</h1>

{{-- FILTROS --}}
<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('financiero.dashboard') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
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
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Proyecto</label>
            <select name="proyecto" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:220px">
                <option value="">Todos los proyectos</option>
                @foreach($proyectos as $p)
                    <option value="{{ $p->codigo_proyecto }}" {{ $proyecto == $p->codigo_proyecto ? 'selected' : '' }}>
                        {{ $p->codigo_proyecto }} - {{ $p->nombre_proyecto }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">
            Filtrar
        </button>
        <a href="{{ route('financiero.dashboard') }}" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:flex;align-items:center">
            Limpiar
        </a>
    </form>
</div>

{{-- ALERTAS --}}
@php $alertas = array_filter($proyectosData, fn($p) => $p['alerta']); @endphp
@if(count($alertas) > 0)
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">
    <strong>Alerta:</strong> {{ count($alertas) }} proyecto(s) con costos pero sin ingresos registrados —
    @foreach($alertas as $a)
        <strong>{{ $a['codigo'] }}</strong>{{ !$loop->last ? ', ' : '' }}
    @endforeach
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

{{-- TABLA PRINCIPAL --}}
<div class="card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px;min-width:200px">Proyecto / OT</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta mayor</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Estado de resultados</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">% MC Real</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">KPI</th>
            </tr>
        </thead>
        <tbody>
            @forelse($proyectosData as $cod => $p)
                @if($p['ingreso'] != 0)
                <tr style="background:white">
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#374151">
                        {{ $p['codigo'] }} - {{ $p['nombre'] }}
                        @if($p['alerta'])
                            <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:6px">Sin ingreso</span>
                        @endif
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#059669;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Ingreso', '{{ $p['nombre'] }}')">
                        Ingreso ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#059669">${{ number_format($p['ingreso'], 0, ',', '.') }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right" rowspan="3">
                        {{ $p['margen_pct'] !== null ? $p['margen_pct'] . '%' : 'N/A' }}
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:center" rowspan="3">
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
                @endif
                @if($p['costo_aplicado'] != 0)
                <tr style="background:white">
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280;font-size:11px;padding-left:20px">↳ {{ $p['codigo'] }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#DC2626;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Costos aplicados', '{{ $p['nombre'] }}')">
                        Costos aplicados ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#DC2626">-${{ number_format(abs($p['costo_aplicado']), 0, ',', '.') }}</td>
                </tr>
                @endif
                @if($p['costo_por_aplicar'] != 0)
                <tr style="background:white">
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280;font-size:11px;padding-left:20px">↳ {{ $p['codigo'] }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#D97706;cursor:pointer"
                        onclick="abrirDetalle('{{ $cod }}', 'Costos por aplicar', '{{ $p['nombre'] }}')">
                        Costos por aplicar ↗
                    </td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#D97706">-${{ number_format(abs($p['costo_por_aplicar']), 0, ',', '.') }}</td>
                </tr>
                @endif
                <tr style="background:#F9FAFB">
                    <td colspan="2" style="padding:6px 10px;border-bottom:2px solid #E5E7EB;font-weight:600;color:#1B3F6E">
                        Total {{ $p['codigo'] }} - {{ $p['nombre'] }}
                    </td>
                    <td style="padding:6px 10px;border-bottom:2px solid #E5E7EB;text-align:right;font-weight:600;color:{{ $p['utilidad'] >= 0 ? '#15803D' : '#DC2626' }}">
                        ${{ number_format($p['utilidad'], 0, ',', '.') }}
                    </td>
                    <td colspan="2" style="border-bottom:2px solid #E5E7EB"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;padding:2rem;color:#9CA3AF">
                        No hay datos para el período seleccionado
                    </td>
                </tr>
            @endforelse
            <tr style="background:#1B3F6E">
                <td colspan="2" style="padding:10px;color:white;font-weight:600">Total general</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalUtilidad, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">{{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}</td>
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

{{-- MODAL --}}
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
const filtros = {
    anio: '{{ $anio }}',
    mes: '{{ $mes }}',
    modo: '{{ $modo }}'
};

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

    if (row.style.display !== 'none') {
        row.style.display = 'none';
        return;
    }

    row.style.display = 'table-row';
    const cuenta = decodeURIComponent(cuentaEncoded);
    const meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

    fetch(`/financiero/detalle-cuenta?codigo=${codigo}&cuenta=${encodeURIComponent(cuenta)}&anio=${filtros.anio}&mes=${filtros.mes}&modo=${filtros.modo}`)
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
</script>
@endsection