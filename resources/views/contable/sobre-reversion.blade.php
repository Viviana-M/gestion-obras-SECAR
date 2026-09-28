@extends('layouts.app')

@section('title', 'Validación de sobre-reversión')

@section('content')
<x-page-banner title="Validación de sobre-reversión — cuenta 14" icon="⚖️">
    Agrupa por <b>obra · cuenta · tercero · valor</b> (sin período, porque el costo y su reversión
    caen en meses distintos) y compara costos contra reversiones. Cuando hay <b>más reversiones que
    costos</b>, el exceso son reversiones sin costo detrás: los duplicados reales. Un costo sin
    revertir todavía es normal, así que <b>nunca se borra un débito</b>: sólo puedes eliminar el
    exceso de crédito, con un clic y confirmando. Nada se borra en silencio al cargar.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

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
    <div>
        <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Obra (código)</label>
        <input type="text" name="obra" value="{{ $obra }}" placeholder="Ej: GM000021" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
    </div>
    <button type="submit" style="padding:7px 14px;background:#1B3F6E;border:none;border-radius:8px;font-size:13px;color:white;height:36px;cursor:pointer">Filtrar</button>
    @if($anio || $obra !== '')
    <a href="{{ route('contable.sobre-reversion.index') }}" style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:inline-flex;align-items:center">Limpiar</a>
    @endif
</form>

<div style="font-size:12px;color:#6B7280;margin-bottom:8px">
    <b>{{ $totalGrupos }}</b> grupo(s) con sobre-reversión · <b>{{ $totalSobran }}</b> reversión(es) de sobra en total.
    @if($totalGrupos >= 1000)<span style="color:#B45309"> (mostrando los primeros 1.000)</span>@endif
</div>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:1040px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Obra</th>
                <th style="padding:10px 14px;text-align:left">Cuenta</th>
                <th style="padding:10px 14px;text-align:left">Tercero</th>
                <th style="padding:10px 14px;text-align:right">Valor</th>
                <th style="padding:10px 14px;text-align:center">Costos (déb.)</th>
                <th style="padding:10px 14px;text-align:center">Reversiones (créd.)</th>
                <th style="padding:10px 14px;text-align:center">Conserva</th>
                <th style="padding:10px 14px;text-align:center">Sobran</th>
                <th style="padding:10px 14px;text-align:left">Documentos</th>
                <th style="padding:10px 14px;text-align:left">IDs crédito</th>
                <th style="padding:10px 14px;text-align:center">Acción</th>
            </tr>
        </thead>
        <tbody>
            @forelse($grupos as $g)
            @php
                $sobran   = max(0, (int) $g->n_cred - (int) $g->n_deb);
                $conserva = min((int) $g->n_deb, (int) $g->n_cred);
            @endphp
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
                <td style="padding:9px 14px;text-align:right;color:#374151">$ {{ number_format((float) $g->monto, 2, ',', '.') }}</td>
                <td style="padding:9px 14px;text-align:center;color:#374151">{{ (int) $g->n_deb }}</td>
                <td style="padding:9px 14px;text-align:center;color:#374151">{{ (int) $g->n_cred }}</td>
                <td style="padding:9px 14px;text-align:center;color:#15803D;font-weight:600">{{ $conserva }}</td>
                <td style="padding:9px 14px;text-align:center">
                    <span style="font-size:12px;font-weight:700;padding:2px 10px;border-radius:10px;background:#FEF2F2;color:#DC2626">{{ $sobran }}</span>
                </td>
                <td style="padding:9px 14px;font-family:monospace;font-size:11px;color:#6B7280;word-break:break-all">{{ $g->documentos ?: '—' }}</td>
                <td style="padding:9px 14px;font-family:monospace;font-size:11px;color:#6B7280;word-break:break-all">{{ $g->ids_credito ?: '—' }}</td>
                <td style="padding:9px 14px;text-align:center">
                    <form method="POST" action="{{ route('contable.sobre-reversion.eliminar') }}"
                          onsubmit="return confirm('¿Eliminar {{ $sobran }} reversión(es) de sobra de la obra {{ $g->codigo_proyecto }} (cuenta {{ $g->cuenta_contable }}, valor $ {{ number_format((float) $g->monto, 2, ',', '.') }})?\n\nSe conservarán {{ $conserva }} reversión(es) respaldada(s) por su costo. Los débitos no se tocan.');"
                          style="margin:0">
                        @csrf
                        <input type="hidden" name="obra" value="{{ $g->codigo_proyecto }}">
                        <input type="hidden" name="cuenta" value="{{ $g->cuenta_contable }}">
                        <input type="hidden" name="tercero" value="{{ $g->tercero_dcto }}">
                        <input type="hidden" name="monto" value="{{ $g->monto }}">
                        <input type="hidden" name="anio" value="{{ $anio }}">
                        <button type="submit" style="padding:5px 12px;background:#DC2626;border:none;border-radius:8px;font-size:12px;color:white;cursor:pointer;white-space:nowrap">Eliminar sobra</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="11" style="padding:1.5rem;text-align:center;color:#9CA3AF">No hay sobre-reversión: cada reversión está respaldada por un costo. 🎉</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
