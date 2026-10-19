@extends('layouts.app')

@section('title', 'Provisiones')

@section('content')
@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $depLabel = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $depEfectivo = $depUsuario ?: $departamento;
@endphp

<x-page-banner title="Provisiones" icon="📑" :badge="$depEfectivo ? ($depLabel[$depEfectivo] ?? $depEfectivo) : null">
    Saldo de provisión por tercero (cuenta 26, Libro&nbsp;1 = débito − crédito), acumulado al corte del
    período. Vista <b>solo lectura</b>.
</x-page-banner>

<x-filtros-panel>
    <form method="GET" action="{{ route('operativo.consulta-provisiones') }}" id="form-filtros"
          style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <button type="submit" class="btn-filtrar" style="order:-1">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filtrar
        </button>
        @if(is_null($depUsuario))
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" class="filtro-select" onchange="document.getElementById('form-filtros').submit()">
                <option value="">Todos</option>
                <option value="mantenimiento" {{ $departamento == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $departamento == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Mes</label>
            <select name="mes" class="filtro-select">
                @for($i = 1; $i <= 12; $i++)
                    <option value="{{ $i }}" {{ $i == $mes ? 'selected' : '' }}>{{ $meses[$i] }}</option>
                @endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 90px">
            <label class="filtro-label">Año</label>
            <select name="anio" class="filtro-select">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:2 1 240px">
            <label class="filtro-label">Buscar tercero</label>
            <input type="text" id="buscador" autocomplete="off" placeholder="🔎 Nit o razón social…"
                onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('form-filtros').submit();}" class="filtro-input">
        </div>
    </form>
</x-filtros-panel>

<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin:1rem 0;flex-wrap:wrap">
    <div style="font-size:12.5px;color:#6B7280">{{ $meses[$mes] ?? $mes }} {{ $anio }} · <b>{{ count($filas) }}</b> tercero(s)</div>
    @if(count($filas) > 0)
    <a href="{{ route('operativo.consulta-provisiones.excel', ['mes' => $mes, 'anio' => $anio, 'departamento' => $departamento, 'q' => $q]) }}"
       style="padding:9px 16px;background:#15803D;color:white;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap">⬇ Descargar Excel</a>
    @endif
</div>

@if(count($filas) === 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">
    No hay provisiones (cuenta 26) para este filtro.
    <div style="font-size:11.5px;margin-top:6px">Si esperabas datos, revisa que el cierre del período incluya la cuenta 26 en el importador.</div>
</div>
@else
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:620px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Tercero</th>
                <th style="padding:10px 14px;text-align:right">Saldo provisión</th>
            </tr>
        </thead>
        <tbody>
            @foreach($filas as $i => $f)
            @php $neg = $f['saldo'] < 0; $tieneDesglose = count($f['proyectos']) > 0; @endphp
            <tr style="border-bottom:1px solid #F3F4F6;{{ $tieneDesglose ? 'cursor:pointer' : '' }}"
                @if($tieneDesglose) onclick="toggleProvDet({{ $i }})" title="Ver desglose por proyecto / U.N." @endif>
                <td style="padding:9px 14px">
                    @if($tieneDesglose)<span id="caret-prov-{{ $i }}" style="color:#6366F1;display:inline-block;width:12px">▸</span>@else<span style="display:inline-block;width:12px"></span>@endif
                    <span style="font-family:ui-monospace,monospace;font-weight:600;color:#1B3F6E">{{ $f['nit'] ?: '—' }}</span>
                    <span style="color:#374151"> · {{ $f['razon'] ?: 'Sin razón social' }}</span>
                </td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:{{ $neg ? '#DC2626' : '#1B3F6E' }}">{{ $fmt($f['saldo']) }}</td>
            </tr>
            @if($tieneDesglose)
            <tr id="prov-det-{{ $i }}" style="display:none;background:#F9FAFB">
                <td colspan="2" style="padding:0 14px 0 32px">
                    <table style="width:100%;border-collapse:collapse;font-size:11.5px">
                        @foreach($f['proyectos'] as $p)
                        <tr style="border-bottom:1px solid #F3F4F6">
                            <td style="padding:6px 8px;color:#6B7280">
                                <span style="font-family:ui-monospace,monospace;color:#1B3F6E">{{ $p['codigo'] }}</span>
                                @if($p['nombre'])<span> · {{ \Illuminate\Support\Str::limit($p['nombre'], 48) }}</span>@endif
                            </td>
                            <td style="padding:6px 8px;text-align:right;color:{{ $p['saldo'] < 0 ? '#DC2626' : '#374151' }}">{{ $fmt($p['saldo']) }}</td>
                        </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
            @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#EEF2FF;font-weight:800;border-top:2px solid #C7D2FE">
                <td style="padding:10px 14px;color:#1B3F6E">Total</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $total < 0 ? '#DC2626' : '#1B3F6E' }}">{{ $fmt($total) }}</td>
            </tr>
        </tfoot>
    </table>
</div>
@endif

<script>
// Expandir/colapsar el desglose por proyecto de un tercero.
function toggleProvDet(i) {
    var det = document.getElementById('prov-det-' + i);
    var car = document.getElementById('caret-prov-' + i);
    if (!det) return;
    var open = det.style.display !== 'none';
    det.style.display = open ? 'none' : 'table-row';
    if (car) car.textContent = open ? '▸' : '▾';
}
// Buscador en vivo (además del filtro del formulario): acota las filas de tercero por Nit o razón.
(function () {
    var inp = document.getElementById('buscador');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var term = this.value.trim().toLowerCase();
        document.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.id && tr.id.indexOf('prov-det-') === 0) return; // las filas de desglose siguen a su tercero
            var txt = (tr.textContent || '').toLowerCase();
            var show = term === '' || txt.indexOf(term) !== -1;
            tr.style.display = show ? '' : 'none';
        });
    });
})();
</script>
@endsection
