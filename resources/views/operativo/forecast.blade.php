@extends('layouts.app')

@section('title', 'Forecast de costos')

@section('content')
<h1 class="page-title">Forecast de costos — Módulo operativo</h1>

{{-- FILTROS --}}
<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('operativo.forecast') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">
            Filtrar
        </button>
    </form>
</div>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">
    {{ session('success') }}
</div>
@endif

<form method="POST" action="{{ route('operativo.forecast.guardar') }}" id="form-forecast">
@csrf
<input type="hidden" name="mes" value="{{ $mes }}">
<input type="hidden" name="anio" value="{{ $anio }}">

<div style="overflow-x:auto">
<table style="width:100%;border-collapse:collapse;font-size:11px;min-width:1400px">
    <thead>
        <tr style="background:#1B3F6E;color:white">
            <th style="padding:8px 10px;text-align:left;position:sticky;left:0;background:#1B3F6E;min-width:200px;z-index:2">Proyecto / OT</th>
            <th style="padding:8px 10px;text-align:right;min-width:100px">Ingreso mes</th>
            <th style="padding:8px 10px;text-align:right;min-width:100px">Ingreso acum</th>
            <th style="padding:8px 10px;text-align:center;min-width:80px">Costo tránsito</th>
            <th style="padding:8px 10px;text-align:right;min-width:100px">Costo aplicado</th>
            <th style="padding:8px 10px;text-align:right;min-width:80px">% MC Real</th>
            <th style="padding:8px 10px;text-align:right;min-width:80px">Margen mín %</th>
            <th style="padding:8px 10px;text-align:center;min-width:80px">Estado obra</th>
            <th style="padding:8px 10px;text-align:center;min-width:80px">% Avance</th>
            @foreach($cuentas14 as $c)
                <th style="padding:8px 6px;text-align:center;min-width:110px;font-size:10px;white-space:nowrap"
                    title="{{ $c->cuenta_contable }} - {{ $c->descripcion }}">
                    {{ $c->cuenta_contable }}<br>
                    <span style="font-weight:400;opacity:.8">{{ Str::limit($c->descripcion, 15) }}</span>
                </th>
            @endforeach
            <th style="padding:8px 10px;text-align:right;min-width:110px">Total 14 pendiente</th>
            <th style="padding:8px 10px;text-align:right;min-width:100px">Total a mover</th>
            <th style="padding:8px 10px;text-align:right;min-width:80px">% MC post</th>
            <th style="padding:8px 10px;text-align:center;min-width:60px">KPI</th>
        </tr>
    </thead>
    <tbody>
        @if(count($matriz) == 0)
            <tr><td colspan="20" style="padding:2rem;text-align:center;color:#9CA3AF">
                No hay proyectos con saldo en cuenta 14 para este período.
            </td></tr>
        @endif

        @foreach($matriz as $cod => $p)
        @php
    $totalSaldo14 = 0;
    foreach($p['cuentas'] as $cuenta) {
        $totalSaldo14 += abs($cuenta['saldo'] < 0 ? $cuenta['saldo'] : 0);
    }
    $totalCostos = $p['costo_aplicado'] + $totalSaldo14;
    $mcReal = $p['ingreso_acum'] != 0
        ? round(($p['ingreso_acum'] - $totalCostos) / $p['ingreso_acum'] * 100, 1)
        : null;
    $colorMC = $mcReal === null ? '#DC2626' : ($mcReal >= 15 ? '#16A34A' : ($mcReal >= 5 ? '#D97706' : '#DC2626'));
@endphp
        <tr class="fila-proyecto" data-codigo="{{ $cod }}"
            style="border-bottom:1px solid #E5E7EB;background:white"
            onmouseenter="this.style.background='#F9FAFB'"
            onmouseleave="this.style.background='white'">

            {{-- Proyecto --}}
            <td style="padding:6px 10px;position:sticky;left:0;background:inherit;font-weight:500;color:#1B3F6E;z-index:1">
                {{ $cod }}<br>
                <span style="font-size:10px;color:#6B7280;font-weight:400">{{ Str::limit($p['nombre'], 30) }}</span>
                <input type="hidden" name="nombre_proyecto[{{ $cod }}]" value="{{ $p['nombre'] }}">
            </td>

            {{-- Ingreso mes --}}
            <td style="padding:6px 10px;text-align:right;font-weight:600;color:{{ $p['tiene_ingreso_mes'] ? '#059669' : '#DC2626' }}">
                {{ $p['tiene_ingreso_mes'] ? '$'.number_format($p['ingreso_mes'], 0, ',', '.') : 'Sin ingreso' }}
            </td>

            {{-- Ingreso acumulado --}}
            <td style="padding:6px 10px;text-align:right;color:#059669">
                ${{ number_format($p['ingreso_acum'], 0, ',', '.') }}
            </td>

            {{-- Costo tránsito --}}
            <td style="padding:6px 10px;text-align:center">
                <input type="checkbox" name="costo_transito[{{ $cod }}]" value="1"
                    title="Marcar si hay costos en tránsito que deben provisionarse aunque no haya ingreso este mes"
                    onchange="toggleTransito('{{ $cod }}')">
            </td>

            {{-- Costo aplicado --}}
            <td style="padding:6px 10px;text-align:right;color:#DC2626">
                ${{ number_format($p['costo_aplicado'], 0, ',', '.') }}
            </td>

            {{-- % MC Real --}}
            <td style="padding:6px 10px;text-align:right;font-weight:600;color:{{ $colorMC }}">
                {{ $mcReal !== null ? $mcReal . '%' : 'N/A' }}
            </td>

            {{-- Margen mínimo --}}
            <td style="padding:6px 10px;text-align:center">
                <input type="number" name="margen_minimo[{{ $cod }}]"
                    value="{{ $p['margen_minimo'] }}"
                    min="0" max="100" step="0.1"
                    style="width:55px;padding:3px 5px;border:1px solid #E5E7EB;border-radius:4px;font-size:11px;text-align:center"
                    onchange="recalcular('{{ $cod }}')">
            </td>

            {{-- Estado obra --}}
            <td style="padding:6px 10px;text-align:center">
                <select name="estado_obra[{{ $cod }}]"
                    style="padding:3px 5px;border:1px solid #E5E7EB;border-radius:4px;font-size:10px">
                    <option value="abierta" {{ $p['estado_obra'] == 'abierta' ? 'selected' : '' }}>Abierta</option>
                    <option value="cerrada_parcial" {{ $p['estado_obra'] == 'cerrada_parcial' ? 'selected' : '' }}>Cerrada parcial</option>
                    <option value="cerrada_total" {{ $p['estado_obra'] == 'cerrada_total' ? 'selected' : '' }}>Cerrada total</option>
                    <option value="suspendida" {{ $p['estado_obra'] == 'suspendida' ? 'selected' : '' }}>Suspendida</option>
                </select>
            </td>

            {{-- % Avance --}}
            <td style="padding:6px 10px;text-align:center">
                <select name="avance_pct[{{ $cod }}]"
                    style="padding:3px 5px;border:1px solid #E5E7EB;border-radius:4px;font-size:10px">
                    <option value="0" {{ $p['avance_pct'] == 0 ? 'selected' : '' }}>—</option>
                    <option value="25" {{ $p['avance_pct'] == 25 ? 'selected' : '' }}>25%</option>
                    <option value="50" {{ $p['avance_pct'] == 50 ? 'selected' : '' }}>50%</option>
                    <option value="75" {{ $p['avance_pct'] == 75 ? 'selected' : '' }}>75%</option>
                    <option value="100" {{ $p['avance_pct'] == 100 ? 'selected' : '' }}>100%</option>
                </select>
            </td>

            {{-- Celdas cuentas 14 --}}
            @foreach($cuentas14 as $c)
            @php
                $saldo = $p['cuentas'][$c->cuenta_contable]['saldo'] ?? 0;
                $montoMover = $p['cuentas'][$c->cuenta_contable]['monto_mover'] ?? 0;
            @endphp
            <td style="padding:4px 6px;text-align:center">
               @if($saldo < 0)
    {{-- Saldo negativo = costo pendiente = editable --}}
    <div style="font-size:10px;color:#6B7280;margin-bottom:2px">${{ number_format(abs($saldo), 0, ',', '.') }}</div>
    <input type="number"
        name="movimientos[{{ $cod }}][{{ $c->cuenta_contable }}]"
        value="{{ $montoMover != 0 ? number_format($montoMover, 0, '', '') : '' }}"
        min="0" max="{{ abs($saldo) }}" step="1"
        placeholder="0"
        data-saldo="{{ abs($saldo) }}"
        data-proyecto="{{ $cod }}"
        {{ !$p['tiene_ingreso_mes'] ? 'disabled title="Sin ingreso en este mes"' : '' }}
        style="width:90px;padding:3px 5px;border:1px solid {{ !$p['tiene_ingreso_mes'] ? '#F3F4F6' : '#E5E7EB' }};border-radius:4px;font-size:11px;text-align:right;background:{{ !$p['tiene_ingreso_mes'] ? '#F9FAFB' : 'white' }};color:{{ !$p['tiene_ingreso_mes'] ? '#9CA3AF' : 'inherit' }};cursor:{{ !$p['tiene_ingreso_mes'] ? 'not-allowed' : 'text' }}"
        oninput="recalcular('{{ $cod }}')"
        onfocus="this.style.borderColor='#1B3F6E'"
        onblur="validarMonto(this)">
@elseif($saldo > 0)
    {{-- Saldo positivo = reversión excesiva = alerta --}}
    <span style="color:#DC2626;font-size:10px;font-weight:600" 
          title="Reversión excesiva: ${{ number_format($saldo, 0, ',', '.') }}">
        ⚠ ${{ number_format($saldo, 0, ',', '.') }}
    </span>
@else
    <span style="color:#D1D5DB;font-size:10px">—</span>
@endif
            </td>
            @endforeach

            <td style="padding:6px 10px;text-align:right;font-weight:600;color:#D97706">
    ${{ number_format(abs(array_sum(array_column($p['cuentas'], 'saldo'))), 0, ',', '.') }}
</td>
            {{-- Total a mover --}}
            <td style="padding:6px 10px;text-align:right;font-weight:600" id="total-mover-{{ $cod }}">$0</td>

            {{-- % MC post --}}
            <td style="padding:6px 10px;text-align:right;font-weight:600" id="mc-post-{{ $cod }}">—</td>

            {{-- KPI --}}
            <td style="padding:6px 10px;text-align:center" id="estado-{{ $cod }}">
                <span style="font-size:16px;color:#D1D5DB">●</span>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

{{-- BOTONES --}}
<div style="display:flex;gap:10px;justify-content:space-between;margin-top:1rem;align-items:center">
    <button type="button" onclick="abrirModalProvision()"
        style="padding:8px 16px;background:white;color:#1B3F6E;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;cursor:pointer">
        + Agregar provisión manual
    </button>
    <div style="display:flex;gap:10px">
        <button type="submit" style="padding:8px 20px;background:#6B7280;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
            Guardar borrador
        </button>
        <button type="button" onclick="enviarForecast()"
            style="padding:8px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
            Enviar a contabilidad
        </button>
    </div>
</div>
</form>

<form method="POST" action="{{ route('operativo.forecast.enviar') }}" id="form-enviar" style="display:none">
    @csrf
    <input type="hidden" name="mes" value="{{ $mes }}">
    <input type="hidden" name="anio" value="{{ $anio }}">
</form>

{{-- MODAL PROVISIÓN --}}
<div id="modal-provision" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:1.5rem;width:90%;max-width:600px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:15px;font-weight:600;color:#1B3F6E">Agregar provisión manual</h3>
            <button onclick="cerrarModalProvision()" style="font-size:20px;background:none;border:none;cursor:pointer;color:#6B7280">×</button>
        </div>
        <div style="margin-bottom:1rem">
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Proyecto</label>
            <select id="provision-proyecto" style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="">Seleccione un proyecto...</option>
                @foreach($todosProyectos as $tp)
                    <option value="{{ $tp->codigo_proyecto }}" data-nombre="{{ $tp->nombre_proyecto }}">
                        {{ $tp->codigo_proyecto }} - {{ $tp->nombre_proyecto }}
                    </option>
                @endforeach
            </select>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem">
            <div>
                <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Cuenta contable 14xx</label>
                <select id="provision-cuenta" style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                    @foreach($cuentas14 as $c)
                        <option value="{{ $c->cuenta_contable }}">{{ $c->cuenta_contable }} - {{ Str::limit($c->descripcion, 30) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Monto a provisionar</label>
                <input type="number" id="provision-monto" min="0" step="1" placeholder="0"
                    style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
            </div>
        </div>
        <div style="margin-bottom:1.5rem">
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Descripción / Justificación</label>
            <input type="text" id="provision-descripcion" placeholder="Ej: Costo en tránsito por factura pendiente..."
                style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button onclick="cerrarModalProvision()" style="padding:8px 16px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;cursor:pointer;background:white;color:#6B7280">Cancelar</button>
            <button onclick="agregarProvision()" style="padding:8px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Agregar</button>
        </div>
    </div>
</div>

<script>
const datosProyecto = @json($matriz);

function recalcular(cod) {
    const fila = document.querySelector(`[data-codigo="${cod}"]`);
    if (!fila) return;
    const inputs = fila.querySelectorAll('input[data-proyecto]');
    let totalMover = 0;
    inputs.forEach(inp => { totalMover += parseFloat(inp.value || 0); });

    const p = datosProyecto[cod];
    if (!p) return;
    const ingreso = parseFloat(p.ingreso_acum);
    const ingresoMes = parseFloat(p.ingreso_mes);
    const costoAplicado = parseFloat(p.costo_aplicado);
    const margenMinEl = fila.querySelector('input[name^="margen_minimo"]');
    const margenMin = margenMinEl ? parseFloat(margenMinEl.value || 0) : 0;

    // Validar que total a mover no supere ingreso del mes
    const totalMoverEl = document.getElementById('total-mover-' + cod);
    if (ingresoMes > 0 && totalMover > ingresoMes) {
        totalMoverEl.textContent = '$' + totalMover.toLocaleString('es-CO', {maximumFractionDigits:0});
        totalMoverEl.style.color = '#DC2626';
        totalMoverEl.title = 'El total supera el ingreso del mes ($' + ingresoMes.toLocaleString('es-CO', {maximumFractionDigits:0}) + ')';
    } else {
        totalMoverEl.textContent = '$' + totalMover.toLocaleString('es-CO', {maximumFractionDigits:0});
        totalMoverEl.style.color = '#1B3F6E';
        totalMoverEl.title = '';
    }

    const nuevoCosto = costoAplicado + totalMover;
    const mcPost = ingreso > 0 ? ((ingreso - nuevoCosto) / ingreso * 100) : null;

    const mcPostEl = document.getElementById('mc-post-' + cod);
    const estadoEl = document.getElementById('estado-' + cod);

    if (mcPost === null) {
        mcPostEl.textContent = 'N/A';
        mcPostEl.style.color = '#DC2626';
        estadoEl.innerHTML = '<span style="font-size:16px;color:#DC2626">●</span>';
    } else {
        mcPostEl.textContent = mcPost.toFixed(1) + '%';
        if (margenMin > 0 && mcPost >= margenMin) {
            mcPostEl.style.color = '#16A34A';
            estadoEl.innerHTML = '<span style="font-size:16px;color:#16A34A">●</span>';
        } else if (mcPost >= 5) {
            mcPostEl.style.color = '#D97706';
            estadoEl.innerHTML = '<span style="font-size:16px;color:#D97706">●</span>';
        } else {
            mcPostEl.style.color = '#DC2626';
            estadoEl.innerHTML = '<span style="font-size:16px;color:#DC2626">●</span>';
        }
    }
}

function validarMonto(input) {
    const saldo = parseFloat(input.dataset.saldo || 0);
    const cod = input.dataset.proyecto;
    const p = datosProyecto[cod];
    let val = Math.round(parseFloat(input.value || 0));
    input.value = val > 0 ? val : '';

    // Validar que no supere el saldo de la cuenta
    if (val > saldo) {
        input.value = Math.round(saldo);
        input.style.borderColor = '#DC2626';
        recalcular(cod);
        return;
    }

    // Validar que el total no supere el ingreso del mes
    if (p) {
        const ingresoMes = parseFloat(p.ingreso_mes);
        const fila = document.querySelector(`[data-codigo="${cod}"]`);
        const inputs = fila.querySelectorAll('input[data-proyecto]');
        let totalMover = 0;
        inputs.forEach(inp => { totalMover += parseFloat(inp.value || 0); });

        if (ingresoMes > 0 && totalMover > ingresoMes) {
            // Reducir este input al máximo permitido
            const otrosInputs = Array.from(inputs).filter(i => i !== input);
            let totalOtros = 0;
            otrosInputs.forEach(i => { totalOtros += parseFloat(i.value || 0); });
            const maxPermitido = Math.round(ingresoMes - totalOtros);
            input.value = Math.max(0, maxPermitido);
            input.style.borderColor = '#DC2626';
            input.title = 'Límite: ingreso del mes $' + ingresoMes.toLocaleString('es-CO', {maximumFractionDigits:0});
            alert('El total a mover no puede superar el ingreso del mes: $' + ingresoMes.toLocaleString('es-CO', {maximumFractionDigits:0}));
        } else {
            input.style.borderColor = '#E5E7EB';
            input.title = '';
        }
    }

    recalcular(cod);
}

function toggleTransito(cod) {
    const checkbox = document.querySelector(`input[name="costo_transito[${cod}]"]`);
    const fila = document.querySelector(`[data-codigo="${cod}"]`);
    const inputs = fila.querySelectorAll(`input[data-proyecto="${cod}"]`);
    const tieneTransito = checkbox.checked;
    inputs.forEach(inp => {
        if (tieneTransito) {
            inp.disabled = false;
            inp.style.background = 'white';
            inp.style.borderColor = '#E5E7EB';
            inp.style.color = 'inherit';
            inp.style.cursor = 'text';
        } else {
            inp.disabled = true;
            inp.value = '';
            inp.style.background = '#F9FAFB';
            inp.style.borderColor = '#F3F4F6';
            inp.style.color = '#9CA3AF';
            inp.style.cursor = 'not-allowed';
            recalcular(cod);
        }
    });
}

function abrirModalProvision() {
    document.getElementById('modal-provision').style.display = 'flex';
}

function cerrarModalProvision() {
    document.getElementById('modal-provision').style.display = 'none';
}

function agregarProvision() {
    const select = document.getElementById('provision-proyecto');
    const cod = select.value;
    const nombre = select.options[select.selectedIndex]?.dataset.nombre || '';
    const cuenta = document.getElementById('provision-cuenta').value;
    const monto = document.getElementById('provision-monto').value;
    const descripcion = document.getElementById('provision-descripcion').value;

    if (!cod || !cuenta || !monto) {
        alert('Complete todos los campos obligatorios.');
        return;
    }

    const tbody = document.querySelector('table tbody');
    const rowId = 'prov-' + cod + '-' + Date.now();
    const fila = document.createElement('tr');
    fila.style.borderBottom = '1px solid #E5E7EB';
    fila.style.background = '#FFFBEB';
    fila.dataset.codigo = rowId;
    fila.innerHTML = `
        <td style="padding:6px 10px;font-weight:500;color:#1B3F6E;position:sticky;left:0;background:#FFFBEB" colspan="2">
            ${cod} — ${nombre}
            <span style="font-size:10px;background:#FEF9C3;color:#854D0E;padding:1px 6px;border-radius:8px;margin-left:4px">Provisión manual</span>
            <input type="hidden" name="nombre_proyecto[${rowId}]" value="${nombre}">
            <input type="hidden" name="movimientos[${rowId}][${cuenta}]" value="${monto}">
        </td>
        <td style="padding:6px 10px;color:#6B7280;font-size:11px">${descripcion}</td>
        <td style="padding:6px 10px;text-align:right;color:#D97706;font-weight:600" colspan="2">
            $${Number(monto).toLocaleString('es-CO',{maximumFractionDigits:0})}
        </td>
        <td colspan="15"></td>
    `;
    tbody.insertBefore(fila, tbody.lastElementChild);
    cerrarModalProvision();
    document.getElementById('provision-proyecto').value = '';
    document.getElementById('provision-monto').value = '';
    document.getElementById('provision-descripcion').value = '';
}

document.getElementById('modal-provision').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalProvision();
});

document.addEventListener('DOMContentLoaded', () => {
    @foreach($matriz as $cod => $p)
        recalcular('{{ $cod }}');
    @endforeach
});
</script>
@endsection