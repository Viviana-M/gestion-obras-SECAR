@extends('layouts.app')

@section('title', 'Planos de reclasificación')

@section('content')
<x-page-banner title="Planos de reclasificación" icon="🔄">
    Genera los asientos correctivos que mueven los costos ya asentados a la cuenta 61 nueva, sin reescribir el histórico.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#15803D">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ session('error') }}</div>
@endif

<div style="background:#EEF2FF;border:1px solid #C7D2FE;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:12.5px;color:#4338CA;line-height:1.55">
    Cuando una cuenta 14 cambia de cuenta 61 destino, <b>los costos ya asentados se quedan en la cuenta anterior</b>.
    Aquí se genera el asiento correctivo que los mueve a la cuenta nueva: <b>débito a la 61 nueva, crédito a la 61 anterior</b>.
    El histórico no se reescribe — se corrige con un movimiento nuevo y trazable.
</div>

{{-- ═══════════ PENDIENTES ═══════════ --}}
<div class="card" style="margin-bottom:1.25rem">
    <h3 style="display:flex;align-items:center;gap:8px">
        Pendientes
        @if(count($pendientes) > 0)
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEF9C3;color:#854D0E">{{ count($pendientes) }}</span>
        @endif
    </h3>

    @if(count($pendientes) === 0)
        <div style="text-align:center;padding:1.5rem;color:#9CA3AF;font-size:13px">
            No hay reclasificaciones pendientes.
        </div>
    @else
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#F3F4F6">
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 14</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cambio de cuenta 61</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Rango a corregir</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Motivo</th>
                    <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pendientes as $p)
                <tr>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;white-space:nowrap">
                        <div style="font-family:monospace;font-weight:600;color:#1B3F6E">{{ $p['cuenta_14'] }}</div>
                        <div style="font-size:11px;color:#9CA3AF">{{ Str::limit($p['nombre'], 28) }}</div>
                    </td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;white-space:nowrap">
                        <span style="font-family:monospace;color:#DC2626;text-decoration:line-through">{{ $p['c61_anterior'] }}</span>
                        <span style="color:#D1D5DB;margin:0 5px">→</span>
                        <span style="font-family:monospace;font-weight:600;color:#15803D">{{ $p['c61_nueva'] }}</span>
                        <div style="font-size:10.5px;color:#9CA3AF">Rigió desde {{ $p['vigente'] }} (v{{ $p['version'] }})</div>
                        @if($p['superada'])
                            <div style="margin-top:3px"><span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:8px;background:#F3F4F6;color:#6B7280" title="La cuenta se volvió a versionar después. La corrección de este rango sigue siendo válida.">Versión superada</span></div>
                        @endif
                    </td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280;white-space:nowrap">
                        {{ $p['desde'] }} → {{ $p['hasta'] }}
                    </td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280;max-width:280px">
                        {{ Str::limit($p['motivo'], 90) }}
                    </td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;text-align:center;white-space:nowrap">
                        <a href="{{ route('contable.reclasificaciones.detalle', $p['id']) }}"
                           style="font-size:11px;padding:4px 12px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;text-decoration:none;display:inline-block">Ver detalle</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- ═══════════ HECHAS ═══════════ --}}
<div class="card">
    <h3>Reclasificaciones hechas</h3>

    @if(count($hechas) === 0)
        <div style="text-align:center;padding:1.5rem;color:#9CA3AF;font-size:13px">
            Todavía no se ha marcado ninguna reclasificación como hecha.
        </div>
    @else
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#F3F4F6">
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 14</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cambio</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Rango corregido</th>
                    <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Marcada</th>
                    <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($hechas as $p)
                <tr>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $p['cuenta_14'] }}</td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;color:#6B7280;white-space:nowrap">
                        {{ $p['c61_anterior'] }} → {{ $p['c61_nueva'] }}
                    </td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;color:#6B7280;white-space:nowrap">{{ $p['desde'] }} → {{ $p['hasta'] }}</td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;color:#15803D;white-space:nowrap">✓ {{ $p['hecha_at'] }}</td>
                    <td style="padding:9px 10px;border-bottom:1px solid #F3F4F6;text-align:center;white-space:nowrap">
                        <a href="{{ route('contable.reclasificaciones.detalle', $p['id']) }}"
                           style="font-size:11px;padding:4px 12px;border:1px solid #E5E7EB;border-radius:6px;background:white;color:#6B7280;text-decoration:none;display:inline-block">Ver</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection