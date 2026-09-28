@extends('layouts.app')

@section('title', 'Distribución de mano de obra')

@section('content')
@php
    $fmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    // Prefill: por tercero → [obra => monto] (suma de sus cuentas).
    $prefill = [];
    foreach ($guardadas as $ter => $filas) {
        foreach ($filas as $f) {
            $prefill[$ter][$f->obra_destino] = ($prefill[$ter][$f->obra_destino] ?? 0) + (float) $f->monto;
        }
    }
    $rowIdx = 0;
@endphp

<x-page-banner title="Distribución de mano de obra (14 → 61)" icon="🧑‍🔧">
    Reparte la mano de obra de cada persona (tercero) de una <b>bolsa</b> a las <b>obras</b> destino.
    El saldo sale de la cuenta 14 de la bolsa en el período. Asigna, genera el plano y aplícalo: el
    costo baja en la bolsa y queda en cada obra, sin recargar BIABLE.
</x-page-banner>

@if(session('success'))<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>@endif
@if(session('error'))<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>@endif

{{-- Origen: bolsa + período --}}
<div class="card" style="padding:14px 16px;margin-bottom:1rem;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0">
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Bolsa (origen)</label>
            <select name="bolsa" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                @foreach($bolsas as $b)
                <option value="{{ $b->codigo }}" {{ $bolsa === $b->codigo ? 'selected' : '' }}>{{ $b->codigo }} — {{ $b->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Período</label>
            <select name="periodo" onchange="const [a,m]=this.value.split('-');this.form.mes.value=m;this.form.anio.value=a;this.form.submit()"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                @foreach($periodos as $p)
                <option value="{{ $p->anio }}-{{ $p->mes }}" {{ ($p->mes==$mes && $p->anio==$anio) ? 'selected' : '' }}>{{ $meses[$p->mes] ?? $p->mes }} {{ $p->anio }}</option>
                @endforeach
            </select>
            <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
        </div>
    </form>
    @if($cerrado)<span style="font-size:12px;font-weight:700;padding:5px 12px;border-radius:10px;background:#FEF3C7;color:#854D0E">🔒 Período cerrado</span>@endif
    <a href="{{ route('operativo.mano-obra.resumen', ['bolsa'=>$bolsa,'mes'=>$mes,'anio'=>$anio]) }}"
       style="padding:7px 14px;background:white;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;color:#1B3F6E;text-decoration:none;height:36px;display:inline-flex;align-items:center">📊 Resumen por obra</a>
    @if($hayMesAnterior)
    <form method="POST" action="{{ route('operativo.mano-obra.precargar') }}" style="margin:0"
          onsubmit="return confirm('¿Precargar la distribución de {{ $meses[$mesAnt] ?? $mesAnt }} {{ $anioAnt }}? Se repartirá el saldo actual de cada persona en las mismas obras y proporciones del mes anterior. Podrás ajustar antes de aplicar. Reemplaza lo que tengas cargado en este período.');">
        @csrf
        <input type="hidden" name="bolsa" value="{{ $bolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
        <button type="submit" style="padding:7px 14px;background:#EFF6FF;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;color:#1B3F6E;cursor:pointer">⤵ Precargar distribución del mes anterior</button>
    </form>
    @endif
</div>

@if(empty($saldos))
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay saldo de mano de obra por repartir en esta bolsa y período.</div>
@else

{{-- Datos para el JS de las obras --}}
<datalist id="obras-list">
    @foreach($obras as $o)<option value="{{ $o->codigo_proyecto }}">{{ $o->codigo_proyecto }} — {{ $o->nombre_obra }}</option>@endforeach
</datalist>

<form method="POST" action="{{ route('operativo.mano-obra.guardar') }}" id="formMO">
    @csrf
    <input type="hidden" name="bolsa" value="{{ $bolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">

    @foreach($saldos as $t)
    @php $filasPre = $prefill[$t['tercero']] ?? []; @endphp
    <div class="card" style="padding:0;margin-bottom:10px" data-tercero-card data-saldo="{{ $t['saldo'] }}">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#F9FAFB;border-bottom:1px solid #E5E7EB;flex-wrap:wrap">
            <div>
                <span style="font-weight:600;color:#1B3F6E">{{ $t['nombre'] ?: $t['tercero'] }}</span>
                <span style="font-size:11px;color:#9CA3AF;font-family:monospace"> · {{ $t['doc'] ?: $t['tercero'] }}</span>
            </div>
            <div style="font-size:12px;color:#374151">
                Saldo: <b>{{ $fmt($t['saldo']) }}</b> ·
                Por asignar: <b data-pendiente style="color:#15803D">{{ $fmt($t['saldo']) }}</b>
            </div>
        </div>
        <div style="padding:8px 14px">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px">
                <tbody data-rows>
                    @php $pre = !empty($filasPre) ? $filasPre : ['' => 0]; @endphp
                    @foreach($pre as $obra => $monto)
                    <tr>
                        <td style="padding:4px 6px 4px 0;width:45%">
                            <input list="obras-list" name="asignaciones[{{ $rowIdx }}][obra]" value="{{ $obra }}" placeholder="Código de obra"
                                   style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                            <input type="hidden" name="asignaciones[{{ $rowIdx }}][tercero]" value="{{ $t['tercero'] }}">
                        </td>
                        <td style="padding:4px 6px;width:25%">
                            <input type="number" step="0.01" min="0" name="asignaciones[{{ $rowIdx }}][monto]" value="{{ $monto ? round($monto,2) : '' }}" placeholder="Monto"
                                   data-monto oninput="recalc(this)" style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right">
                        </td>
                        <td style="padding:4px 6px">
                            <input type="text" name="asignaciones[{{ $rowIdx }}][observacion]" placeholder="Observación (opcional)"
                                   style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                        </td>
                        <td style="padding:4px 0 4px 6px;width:28px;text-align:center">
                            <button type="button" onclick="quitarFila(this)" title="Quitar" style="border:none;background:#FEF2F2;color:#DC2626;width:24px;height:24px;border-radius:6px;cursor:pointer">×</button>
                        </td>
                    </tr>
                    @php $rowIdx++; @endphp
                    @endforeach
                </tbody>
            </table>
            <button type="button" onclick="agregarFila(this)" data-tercero="{{ $t['tercero'] }}"
                    style="margin-top:4px;padding:4px 10px;background:white;border:1px dashed #1B3F6E;border-radius:6px;font-size:12px;color:#1B3F6E;cursor:pointer">+ agregar obra</button>
        </div>
    </div>
    @endforeach

    <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
        <button type="submit" style="padding:9px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">💾 Guardar asignaciones</button>
    </div>
</form>

{{-- Generar / aplicar (usan lo GUARDADO) --}}
<div class="card" style="padding:14px 16px;margin-top:1rem;background:#F8FAFC;border:1px solid #E5E7EB">
    <div style="font-size:12px;color:#374151;margin-bottom:8px"><b>Plano 14→61</b> de lo guardado: CR la cuenta 14 en la bolsa (conserva el tercero) y DB la 61 en la obra destino.</div>
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <form method="GET" action="{{ route('operativo.mano-obra.plano') }}" style="display:flex;gap:8px;align-items:flex-end;margin:0">
            <input type="hidden" name="bolsa" value="{{ $bolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <div>
                <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">N° documento</label>
                <input type="number" name="documento" min="1" value="1" style="width:100px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px">
            </div>
            <button type="submit" style="padding:8px 16px;background:#16A34A;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">⬇ Descargar plano</button>
        </form>
        <form method="POST" action="{{ route('operativo.mano-obra.aplicar') }}" style="margin:0"
              onsubmit="return confirm('¿Aplicar en el sistema la mano de obra guardada de esta bolsa? Se crea la partida doble 14→61 (origen distribucion_plano). Idempotente: reemplaza lo aplicado antes para esta bolsa y período. Podrás deshacerlo en «Planos aplicados».');">
            @csrf
            <input type="hidden" name="bolsa" value="{{ $bolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <button type="submit" style="padding:8px 16px;background:#15803D;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;height:36px">✓ Aplicar en el sistema</button>
        </form>
    </div>
</div>

<script>
let moIdx = {{ $rowIdx }};
const fmtCO = (n) => '$' + Math.round(n).toLocaleString('es-CO');

function recalc(el) {
    const card = el.closest('[data-tercero-card]');
    const saldo = parseFloat(card.dataset.saldo) || 0;
    let suma = 0;
    card.querySelectorAll('[data-monto]').forEach(i => suma += (parseFloat(i.value) || 0));
    const pend = card.querySelector('[data-pendiente]');
    const restante = saldo - suma;
    pend.textContent = fmtCO(restante);
    pend.style.color = restante < -0.5 ? '#DC2626' : '#15803D';
}
function quitarFila(btn) {
    const tr = btn.closest('tr');
    const card = tr.closest('[data-tercero-card]');
    tr.remove();
    card.querySelector('[data-monto]') ? recalc(card.querySelector('[data-monto]')) : (card.querySelector('[data-pendiente]').textContent = fmtCO(parseFloat(card.dataset.saldo)||0));
}
function agregarFila(btn) {
    const tercero = btn.dataset.tercero;
    const tbody = btn.previousElementSibling.querySelector('[data-rows]');
    const i = moIdx++;
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td style="padding:4px 6px 4px 0;width:45%">'+
          '<input list="obras-list" name="asignaciones['+i+'][obra]" placeholder="Código de obra" style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">'+
          '<input type="hidden" name="asignaciones['+i+'][tercero]" value="'+tercero.replace(/"/g,'&quot;')+'">'+
        '</td>'+
        '<td style="padding:4px 6px;width:25%"><input type="number" step="0.01" min="0" name="asignaciones['+i+'][monto]" placeholder="Monto" data-monto oninput="recalc(this)" style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right"></td>'+
        '<td style="padding:4px 6px"><input type="text" name="asignaciones['+i+'][observacion]" placeholder="Observación (opcional)" style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"></td>'+
        '<td style="padding:4px 0 4px 6px;width:28px;text-align:center"><button type="button" onclick="quitarFila(this)" style="border:none;background:#FEF2F2;color:#DC2626;width:24px;height:24px;border-radius:6px;cursor:pointer">×</button></td>';
    tbody.appendChild(tr);
}
document.querySelectorAll('[data-tercero-card] [data-monto]').forEach(i => recalc(i));
</script>
@endif
@endsection
