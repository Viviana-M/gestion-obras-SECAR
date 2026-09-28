@extends('layouts.app')

@section('title', 'Posibles duplicados')

@section('content')
<x-page-banner title="Posibles duplicados — cuenta 14" icon="🧭">
    Movimientos que se repiten con la misma combinación de <b>obra · cuenta · tercero · documento · valor · período</b>.
    Es <b>solo para revisar</b>: no se borra nada, porque dos facturas distintas del mismo valor son legítimas y solo el
    <b>documento</b> las diferencia. Revisa los IDs y decide manualmente.
</x-page-banner>

<form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem">
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Año</label>
        <select name="anio" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
            <option value="">Todos</option>
            @foreach($anios as $y)
            <option value="{{ $y }}" {{ (int) $anio === (int) $y ? 'selected' : '' }}>{{ $y }}</option>
            @endforeach
        </select>
    </div>
    @if($anio)
    <a href="{{ route('contable.duplicados.index') }}" style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:inline-flex;align-items:center">Limpiar</a>
    @endif
</form>

<div style="font-size:12px;color:#6B7280;margin-bottom:8px">
    <b>{{ $totalGrupos }}</b> grupo(s) con repeticiones · <b>{{ $totalFilas }}</b> movimientos involucrados.
    @if($totalGrupos >= 1000)<span style="color:#B45309"> (mostrando los primeros 1.000)</span>@endif
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:920px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:left">Cuenta</th>
                <th style="padding:10px 14px;text-align:left">Tercero</th>
                <th style="padding:10px 14px;text-align:left">Documento</th>
                <th style="padding:10px 14px;text-align:left">Período</th>
                <th style="padding:10px 14px;text-align:right">Valor débito</th>
                <th style="padding:10px 14px;text-align:center">Repeticiones</th>
                <th style="padding:10px 14px;text-align:left">IDs</th>
            </tr>
        </thead>
        <tbody>
            @forelse($grupos as $g)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:9px 14px">
                    <span style="font-family:monospace;font-weight:600;color:#1B3F6E">{{ $g->codigo_proyecto }}</span>
                    <div style="font-size:10px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($g->nombre_proyecto, 40) }}</div>
                </td>
                <td style="padding:9px 14px;font-family:monospace;color:#374151">{{ $g->cuenta_contable }}</td>
                <td style="padding:9px 14px;color:#374151">
                    {{ $g->tercero_dcto ?: '—' }}
                    @if($g->razon_social)<div style="font-size:10px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($g->razon_social, 30) }}</div>@endif
                </td>
                <td style="padding:9px 14px;font-family:monospace;color:#374151">{{ $g->documento ?: '—' }}</td>
                <td style="padding:9px 14px;color:#6B7280">{{ $g->periodo ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:right;color:#374151">$ {{ number_format((float) $g->valor_debito, 2, ',', '.') }}</td>
                <td style="padding:9px 14px;text-align:center">
                    <span style="font-size:12px;font-weight:700;padding:2px 10px;border-radius:10px;background:#FEF2F2;color:#DC2626">{{ $g->repeticiones }}</span>
                </td>
                <td style="padding:9px 14px;font-family:monospace;font-size:11px;color:#6B7280;word-break:break-all">{{ $g->ids }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay movimientos repetidos. 🎉</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
