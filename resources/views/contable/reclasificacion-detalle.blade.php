@extends('layouts.app')

@section('title', 'Detalle de reclasificación')

@section('content')
@php
    $fmt = fn($n) => $n == 0 ? '—' : '$'.number_format($n, 0, ',', '.');
    $cuadra = round($totalDebito - $totalCredito, 2) == 0;
@endphp

<h1 class="page-title" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    Reclasificación de <span style="font-family:monospace">{{ $h->cuenta_14 }}</span>
    @if($h->reclasificado_at)
        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:10px;background:#DCFCE7;color:#15803D">HECHA</span>
    @else
        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:10px;background:#FEF9C3;color:#854D0E">PENDIENTE</span>
    @endif
</h1>

@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ session('error') }}</div>
@endif

@if($h->vigente_hasta !== null)
<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:12.5px;color:#6B7280;line-height:1.5">
    <b>Nota:</b> la cuenta {{ $h->cuenta_14 }} se volvió a versionar después de pedir esta reclasificación,
    así que la v{{ $h->version }} ya no es la vigente. La corrección de este rango
    ({{ $desdeTexto }} → {{ $hastaTexto }}) <b>sigue siendo válida</b>: se refiere a costos del pasado.
</div>
@endif

<a href="{{ route('contable.reclasificaciones.index') }}" style="font-size:12px;color:#6B7280;text-decoration:none;display:inline-block;margin-bottom:1rem">← Volver a reclasificaciones</a>

{{-- RESUMEN DEL CAMBIO --}}
<div class="card" style="margin-bottom:1rem">
    <h3>El cambio</h3>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:12px">
        <div>
            <div style="font-size:10px;color:#9CA3AF">Cuenta 61 anterior</div>
            <div style="font-size:15px;font-weight:600;font-family:monospace;color:#DC2626">{{ $c61Anterior }}</div>
        </div>
        <div>
            <div style="font-size:10px;color:#9CA3AF">Cuenta 61 nueva</div>
            <div style="font-size:15px;font-weight:600;font-family:monospace;color:#15803D">{{ $c61Nueva }}</div>
        </div>
        <div>
            <div style="font-size:10px;color:#9CA3AF">La cuenta nueva rige desde</div>
            <div style="font-size:15px;font-weight:600;color:#1B3F6E">{{ $vigenteTexto }}</div>
        </div>
        <div>
            <div style="font-size:10px;color:#9CA3AF">Se corrige el rango</div>
            <div style="font-size:15px;font-weight:600;color:#1B3F6E">{{ $desdeTexto }} → {{ $hastaTexto }}</div>
        </div>
    </div>
    @if($h->motivo)
    <div style="background:#F9FAFB;border-radius:8px;padding:9px 12px;font-size:12.5px;color:#374151">
        <b style="color:#6B7280">Motivo:</b> {{ $h->motivo }}
    </div>
    @endif
</div>

{{-- TOTALES --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#F3F4F6;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Obras afectadas</div>
        <div style="font-size:18px;font-weight:600;color:#1B3F6E">{{ $obras }}</div>
    </div>
    <div style="background:#F0FDF4;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#15803D;margin-bottom:4px">Total débito</div>
        <div style="font-size:18px;font-weight:600;color:#15803D">{{ $fmt($totalDebito) }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Total crédito</div>
        <div style="font-size:18px;font-weight:600;color:#DC2626">{{ $fmt($totalCredito) }}</div>
    </div>
    <div style="background:{{ $cuadra ? '#F0FDF4' : '#FEF2F2' }};border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:{{ $cuadra ? '#15803D' : '#DC2626' }};margin-bottom:4px">Cuadre</div>
        <div style="font-size:18px;font-weight:600;color:{{ $cuadra ? '#15803D' : '#DC2626' }}">
            {{ $cuadra ? '✓ Cuadra' : '✗ Descuadrado' }}
        </div>
    </div>
</div>

@if(count($lineas) === 0)
<div class="card" style="text-align:center;padding:2rem;color:#9CA3AF">
    <div style="font-size:14px;margin-bottom:6px">No hay costos que reclasificar en este rango.</div>
    <div style="font-size:12px">
        No se encontraron movimientos de la cuenta <b>{{ $h->cuenta_14 }}</b> asentados en la
        <b>{{ $c61Anterior }}</b> entre {{ $desdeTexto }} y {{ $hastaTexto }}.
        Revisa el período desde el que pediste reclasificar.
    </div>
</div>
@else

{{-- LÍNEAS DEL PLANO --}}
<div class="card" style="margin-bottom:1rem">
    <h3>Líneas del plano ({{ count($lineas) }})</h3>
    <div style="overflow-x:auto;max-height:520px;overflow-y:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#F3F4F6;position:sticky;top:0">
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Nombre cuenta</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Proyecto</th>
                    <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Débito</th>
                    <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Crédito</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lineas as $l)
                @php $esNueva = (string) $l['cuenta'] === (string) $c61Nueva; @endphp
                <tr style="{{ $loop->index % 4 < 2 ? 'background:#FFFFFF' : 'background:#FAFAFA' }}">
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-weight:600;color:{{ $esNueva ? '#15803D' : '#DC2626' }}">{{ $l['cuenta'] }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280">{{ Str::limit($l['nombre'], 34) }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;color:#1B3F6E">{{ $l['codigo_proyecto'] }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#15803D">{{ $fmt($l['debito']) }}</td>
                    <td style="padding:6px 10px;border-bottom:1px solid #F3F4F6;text-align:right;color:#DC2626">{{ $fmt($l['credito']) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ACCIONES --}}
<div style="display:flex;justify-content:flex-end;gap:10px;align-items:center;flex-wrap:wrap">
    @if(!$h->reclasificado_at)
        <span style="font-size:12px;color:#9CA3AF;margin-right:auto">
            Descarga el plano, impórtalo en SIESA y solo entonces márcalo como hecho.
        </span>
    @endif

    <a href="{{ route('contable.reclasificaciones.descargar', $h->id) }}"
       style="padding:9px 22px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:8px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block">
        ⬇ Descargar plano
    </a>

    @if(!$h->reclasificado_at)
    <form method="POST" action="{{ route('contable.reclasificaciones.marcar', $h->id) }}" style="display:inline"
          onsubmit="return confirm('¿Confirmas que YA importaste este plano en SIESA?\n\nLa reclasificación quedará registrada como hecha, con tu nombre y la fecha.')">
        @csrf
        <button type="submit" style="padding:9px 22px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
            Marcar como reclasificada
        </button>
    </form>
    @endif
</div>

@endif
@endsection