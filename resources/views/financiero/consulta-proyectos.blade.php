@extends('layouts.app')

@section('title', 'Margen por proyecto')

@section('content')
@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $pct = fn($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
    // Esquema (orden pedido por gerencia) y su etiqueta.
    $orden = ['MOI','EQU-MAT-SUM','MOE','MOFIJAOPER','OTROS COSTO'];
    $part = fn($v, $ing) => abs($ing) > 0.5 ? number_format($v / $ing * 100, 1, ',', '.').'%' : '—';
    $colMc = fn($v) => $v === null ? '#6B7280' : ($v < 0 ? '#DC2626' : ($v >= 20 ? '#15803D' : '#B45309'));
@endphp

<x-page-banner title="Margen por proyecto" icon="📊" :badge="$departamento ? ucfirst($departamento) : null">
    Consulta de gerencia (<b>solo lectura</b>): margen real por obra y su esquema de costos acumulado al
    corte del período. Responsable tomado de la ficha del proyecto; compara el margen ofertado vs el real.
</x-page-banner>

<x-filtros-panel>
    <form method="GET" action="{{ route('financiero.proyectos') }}" id="form-filtros"
          style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <button type="submit" class="btn-filtrar" style="order:-1">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filtrar
        </button>
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Mes</label>
            <select name="mes" class="filtro-select">
                @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $i == $mes ? 'selected' : '' }}>{{ $meses[$i] }}</option>@endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 90px">
            <label class="filtro-label">Año</label>
            <select name="anio" class="filtro-select">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)<option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>@endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" class="filtro-select">
                <option value="">Todos</option>
                <option value="mantenimiento" {{ $departamento == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $departamento == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 130px">
            <label class="filtro-label">Tipo</label>
            <select name="tipo" class="filtro-select">
                @foreach(['todos'=>'Todos','obras'=>'Obras','contrato'=>'Contratos','reparacion'=>'Reparaciones','garantia'=>'Garantías'] as $k => $v)
                    <option value="{{ $k }}" {{ $tipo == $k ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 180px">
            <label class="filtro-label">Responsable</label>
            <select name="responsable" class="filtro-select">
                <option value="">Todos</option>
                @foreach($responsables as $r)
                    <option value="{{ $r }}" {{ $responsable == $r ? 'selected' : '' }}>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:2 1 220px">
            <label class="filtro-label">Buscar proyecto</label>
            <input type="text" id="buscador" autocomplete="off" placeholder="🔎 Código, obra, cliente o responsable…"
                onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('form-filtros').submit();}" class="filtro-input">
        </div>
    </form>
</x-filtros-panel>

<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin:1rem 0;flex-wrap:wrap">
    <div style="font-size:12.5px;color:#6B7280">{{ $meses[$mes] ?? $mes }} {{ $anio }} · <b>{{ count($obras) }}</b> obra(s)</div>
    @if(count($obras) > 0)
    <a href="{{ route('financiero.proyectos.excel', ['mes'=>$mes,'anio'=>$anio,'departamento'=>$departamento,'tipo'=>$tipo,'responsable'=>$responsable,'q'=>$q]) }}"
       style="padding:9px 16px;background:#15803D;color:white;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap">⬇ Descargar Excel</a>
    @endif
</div>

@if(count($obras) === 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay proyectos con ingreso o costo para este filtro.</div>
@else
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:860px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Proyecto</th>
                <th style="padding:10px 14px;text-align:left">Responsable</th>
                <th style="padding:10px 14px;text-align:left">Tipo</th>
                <th style="padding:10px 14px;text-align:right">Ingreso</th>
                <th style="padding:10px 14px;text-align:right">Total costo</th>
                <th style="padding:10px 14px;text-align:right">MC %</th>
                <th style="padding:10px 14px;text-align:right">Ofertado %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($obras as $i => $o)
            @php $ing = $o['ingreso']; @endphp
            <tr style="border-bottom:1px solid #F3F4F6;cursor:pointer" onclick="toggleEsq({{ $i }})" title="Ver esquema de costos">
                <td style="padding:9px 14px">
                    <span id="caret-esq-{{ $i }}" style="color:#6366F1;display:inline-block;width:12px">▸</span>
                    <span style="font-family:ui-monospace,monospace;font-weight:600;color:#1B3F6E">{{ $o['codigo'] }}</span>
                    <div style="font-size:11px;color:#6B7280;margin-left:12px">{{ \Illuminate\Support\Str::limit($o['nombre'] ?: '—', 46) }}@if($o['cliente']) · {{ \Illuminate\Support\Str::limit($o['cliente'], 28) }}@endif</div>
                </td>
                <td style="padding:9px 14px;color:#374151">{{ $o['responsable'] ?: '—' }}</td>
                <td style="padding:9px 14px;color:#6B7280">{{ ucfirst($o['tipo']) }}</td>
                <td style="padding:9px 14px;text-align:right;color:#1B3F6E">{{ $fmt($ing) }}</td>
                <td style="padding:9px 14px;text-align:right;color:#374151">{{ $fmt($o['total_costo']) }}</td>
                <td style="padding:9px 14px;text-align:right">
                    <span style="font-weight:700;padding:2px 9px;border-radius:9px;color:#fff;background:{{ $colMc($o['mc_pct']) }}">{{ $pct($o['mc_pct']) }}</span>
                </td>
                <td style="padding:9px 14px;text-align:right;color:#6B7280">{{ $pct($o['ofertado']) }}</td>
            </tr>
            <tr id="esq-{{ $i }}" style="display:none;background:#F9FAFB">
                <td colspan="7" style="padding:4px 14px 12px 32px">
                    <table style="width:100%;max-width:560px;border-collapse:collapse;font-size:11.5px">
                        <thead>
                            <tr style="color:#9CA3AF;text-align:right">
                                <th style="padding:5px 8px;text-align:left">Esquema de costos</th>
                                <th style="padding:5px 8px;text-align:right">Valor</th>
                                <th style="padding:5px 8px;text-align:right">Part. %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-top:1px solid #E5E7EB">
                                <td style="padding:5px 8px;font-weight:600;color:#1B3F6E">Ingreso</td>
                                <td style="padding:5px 8px;text-align:right;font-weight:600">{{ $fmt($ing) }}</td>
                                <td style="padding:5px 8px;text-align:right;color:#9CA3AF">{{ abs($ing) > 0.5 ? '100,0%' : '—' }}</td>
                            </tr>
                            @foreach($orden as $ck)
                            <tr>
                                <td style="padding:5px 8px 5px 18px;color:#374151">{{ $cats[$ck] }}</td>
                                <td style="padding:5px 8px;text-align:right;color:#374151">{{ $fmt($o['cat'][$ck] ?? 0) }}</td>
                                <td style="padding:5px 8px;text-align:right;color:#9CA3AF">{{ $part($o['cat'][$ck] ?? 0, $ing) }}</td>
                            </tr>
                            @endforeach
                            <tr style="border-top:1px solid #E5E7EB">
                                <td style="padding:5px 8px;font-weight:700;color:#374151">Total costo</td>
                                <td style="padding:5px 8px;text-align:right;font-weight:700">{{ $fmt($o['total_costo']) }}</td>
                                <td style="padding:5px 8px;text-align:right;color:#9CA3AF">{{ $part($o['total_costo'], $ing) }}</td>
                            </tr>
                            <tr style="border-top:2px solid #BEE3C9;background:#F3F8F4">
                                <td style="padding:6px 8px;font-weight:800;color:{{ $colMc($o['mc_pct']) }}">Margen de contribución</td>
                                <td style="padding:6px 8px;text-align:right;font-weight:800;color:{{ $colMc($o['mc_pct']) }}">{{ $fmt($o['mc']) }}</td>
                                <td style="padding:6px 8px;text-align:right;font-weight:800;color:{{ $colMc($o['mc_pct']) }}">{{ $pct($o['mc_pct']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    @if($o['resp_comercial'])<div style="font-size:11px;color:#9CA3AF;margin-top:6px">Responsable comercial: {{ $o['resp_comercial'] }}</div>@endif
                </td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#EEF2FF;font-weight:800;border-top:2px solid #C7D2FE">
                <td style="padding:10px 14px;color:#1B3F6E" colspan="3">Total</td>
                <td style="padding:10px 14px;text-align:right;color:#1B3F6E">{{ $fmt($totales['ingreso']) }}</td>
                <td style="padding:10px 14px;text-align:right;color:#1B3F6E">{{ $fmt($totales['total_costo']) }}</td>
                <td style="padding:10px 14px;text-align:right;color:{{ $colMc($totales['mc_pct']) }}">{{ $pct($totales['mc_pct']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
@endif

<script>
function toggleEsq(i) {
    var det = document.getElementById('esq-' + i);
    var car = document.getElementById('caret-esq-' + i);
    if (!det) return;
    var open = det.style.display !== 'none';
    det.style.display = open ? 'none' : 'table-row';
    if (car) car.textContent = open ? '▸' : '▾';
}
// Buscador en vivo sobre la fila principal (su fila de esquema la sigue).
(function () {
    var inp = document.getElementById('buscador');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var term = this.value.trim().toLowerCase();
        document.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.id && tr.id.indexOf('esq-') === 0) return;
            var show = term === '' || (tr.textContent || '').toLowerCase().indexOf(term) !== -1;
            tr.style.display = show ? '' : 'none';
        });
    });
})();
</script>
@endsection
