@extends('layouts.app')

@section('title', 'Obras en revisión')

@section('content')
@php
    $fmt = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $colEstado = ['abierta'=>['#F0FDF4','#15803D'],'parcial'=>['#FEF9C3','#854D0E'],'cerrada'=>['#EFF6FF','#1B3F6E']];
@endphp

<h1 class="page-title">Obras abiertas con costo sin saldo en cuenta 14</h1>
<p style="color:#6B7280;font-size:13px;margin:-6px 0 16px">
    Obras que están costando (costo reconocido en cuenta 6) pero ya no tienen saldo por distribuir en la cuenta 14.
    No hay nada que distribuir: revisa si conviene cerrarlas. <b style="color:#B91C1C">Las de margen negativo son las urgentes.</b>
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif

@if(count($obras) === 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay obras que revisar. 🎉</div>
@else
<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:820px">
        <thead>
            <tr style="color:#9CA3AF;text-align:left;border-bottom:1px solid #E5E7EB">
                <th style="padding:9px 10px">Código</th>
                <th style="padding:9px 10px">Cliente / Nombre</th>
                <th style="padding:9px 10px;text-align:right">Costo total</th>
                <th style="padding:9px 10px;text-align:right">Ingreso</th>
                <th style="padding:9px 10px;text-align:right">Margen acum. ($)</th>
                <th style="padding:9px 10px;text-align:right">Margen %</th>
                <th style="padding:9px 10px">Estado</th>
                <th style="padding:9px 10px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($obras as $o)
            @php
                $neg = $o['margen_pesos'] < 0;
                $ce = $colEstado[$o['estado']] ?? ['#F3F4F6','#6B7280'];
            @endphp
            <tr style="border-bottom:1px solid #F3F4F6;{{ $neg ? 'background:#FEF2F2' : '' }}">
                <td style="padding:9px 10px;font-family:monospace;font-weight:600;color:#1B3F6E">
                    @if($neg)<span title="Margen negativo — urgente">🔴</span> @endif{{ $o['codigo'] }}
                </td>
                <td style="padding:9px 10px;color:#374151">
                    @if($o['cliente'])<div>{{ $o['cliente'] }}</div>@endif
                    <div style="font-size:11px;color:#9CA3AF">{{ \Illuminate\Support\Str::limit($o['nombre'] ?? '—', 40) }}</div>
                </td>
                <td style="padding:9px 10px;text-align:right;color:#374151">{{ $fmt($o['costo_total']) }}</td>
                <td style="padding:9px 10px;text-align:right;color:#374151">{{ $fmt($o['ingreso']) }}</td>
                <td style="padding:9px 10px;text-align:right;font-weight:600;color:{{ $neg ? '#DC2626' : '#15803D' }}">{{ $fmt($o['margen_pesos']) }}</td>
                <td style="padding:9px 10px;text-align:right;color:{{ $neg ? '#DC2626' : '#374151' }}">{{ $o['margen_pct'] === null ? '—' : $o['margen_pct'].'%' }}</td>
                <td style="padding:9px 10px">
                    <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:{{ $ce[0] }};color:{{ $ce[1] }}">{{ ucfirst($o['estado']) }}</span>
                </td>
                <td style="padding:9px 10px">
                    <div style="display:flex;flex-direction:column;gap:6px;min-width:190px">
                        <a href="{{ route('contable.cierre-obras', ['codigo' => $o['codigo']]) }}"
                            style="font-size:11px;padding:5px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;text-decoration:none;text-align:center;background:white">Enviar a cierre →</a>

                        @if($puedeEditar)
                        <details>
                            <summary style="font-size:11px;color:#6B7280;cursor:pointer;padding:2px 0">✏️ Observación{{ $o['observacion'] ? ' (guardada)' : '' }}</summary>
                            <form method="POST" action="{{ route('operativo.obras-revision.observar') }}" style="margin-top:6px">
                                @csrf
                                <input type="hidden" name="codigo_proyecto" value="{{ $o['codigo'] }}">
                                <textarea name="observacion" rows="2" required placeholder="Justificación para dejarla abierta..."
                                    style="width:100%;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px;font-family:inherit;resize:vertical">{{ $o['observacion'] }}</textarea>
                                <button type="submit" style="margin-top:4px;font-size:11px;padding:5px 12px;background:#1B3F6E;color:white;border:none;border-radius:6px;cursor:pointer">Guardar observación</button>
                            </form>
                        </details>
                        @endif

                        @if($o['observacion'])
                        <div style="font-size:10px;color:#6B7280;line-height:1.4">
                            <i>“{{ \Illuminate\Support\Str::limit($o['observacion'], 80) }}”</i>
                            @if($o['obs_user']) — {{ $o['obs_user'] }}@endif
                            @if($o['obs_fecha']) · {{ $o['obs_fecha']->format('d/m/Y') }}@endif
                        </div>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
