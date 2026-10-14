@extends('layouts.app')

@section('title', 'Mis distribuciones')

@section('content')
<x-page-banner title="Mis distribuciones" icon="📋">Consulta y edita los borradores de distribución que has guardado.
    <x-slot:actions>
        <a href="{{ route('operativo.distribucion') }}" style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border-radius:8px;text-decoration:none">+ Nuevo borrador</a>
    </x-slot:actions>
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin:1rem 0">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin:1rem 0">{{ session('error') }}</div>
@endif

@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
@endphp

{{-- Una sola lista: un registro por período + departamento (un plano = un guardado global). El origen
     de lo distribuido se distingue con etiquetas en la misma fila, no con secciones separadas. --}}
<div class="card" style="margin-top:1.25rem;padding:0;overflow:hidden">
    @if($filas->count() == 0)
        <div style="text-align:center;color:#9CA3AF;padding:2rem">Aún no hay distribuciones guardadas.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Período</th>
                <th style="padding:10px 14px;text-align:left">Guardado</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:right">Resumen</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($filas as $f)
            @php
                $o = $f['obras']; $a = $f['areas'];
                // Estado y acción principal según el plano (registro de obras). Si solo hay otros costos, borrador.
                if ($o) {
                    if ($o['estado'] === 'enviado' && !$o['habilitada'])      { $badge = ['#EFF6FF','#1B3F6E','Enviado']; $cta = 'Consultar'; }
                    elseif ($o['estado'] === 'enviado' && $o['habilitada'])   { $badge = ['#FEF9C3','#854D0E','Enviado · habilitado']; $cta = 'Editar'; }
                    else                                                      { $badge = ['#F3F4F6','#6B7280','Borrador']; $cta = 'Abrir / editar'; }
                } else {
                    $badge = ['#F3F4F6','#6B7280','Borrador']; $cta = null;
                }
                $btn = 'font-size:12px;padding:6px 12px;border-radius:6px;text-decoration:none;margin-left:4px';
            @endphp
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:10px 14px;font-weight:500;color:#1B3F6E">
                    {{ $meses[$f['mes']] ?? $f['mes'] }} {{ $f['anio'] }}
                    @if($f['departamento'])<span style="font-size:10px;padding:2px 8px;border-radius:8px;background:#EEF2FF;color:#4338CA;margin-left:6px">{{ ucfirst($f['departamento']) }}</span>@endif
                    <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
                        @if($f['tiene_costos'])<span style="font-size:10px;padding:2px 8px;border-radius:8px;background:#EFF6FF;color:#1B3F6E">📦 Distribución de costos</span>@endif
                        @if($f['tiene_otros'])<span style="font-size:10px;padding:2px 8px;border-radius:8px;background:#FFFBEB;color:#B45309">🏢 Otros costos</span>@endif
                        @if($o && $o['version'])<span style="font-size:10px;color:#9CA3AF">v{{ $o['version'] }}</span>@endif
                    </div>
                </td>
                <td style="padding:10px 14px;color:#6B7280">
                    {{ $f['guardado_at']?->format('d/m/Y H:i') }}
                    <div style="font-size:11px;color:#9CA3AF">por {{ $f['guardado_por'] }}</div>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:{{ $badge[0] }};color:{{ $badge[1] }};font-weight:500">{{ $badge[2] }}</span>
                </td>
                <td style="padding:10px 14px;text-align:right;white-space:nowrap">
                    @if($o)
                        <div>{{ $o['obras'] }} obra(s) · <b>{{ $fmt($o['aplicado']) }}</b></div>
                        @if($o['provision'] > 0)<div style="font-size:11px;color:#B45309">Provisión {{ $fmt($o['provision']) }}</div>@endif
                    @endif
                    @if($a)<div style="font-size:11px;color:#B45309">Otros costos {{ $fmt($a['a_distribuir']) }} · {{ $a['cuentas'] }} cta(s)</div>@endif
                    @if(!$o && !$a)<span style="color:#9CA3AF">—</span>@endif
                </td>
                <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                    @if($o)
                        <a href="{{ route('operativo.distribucion', ['dist' => $o['id']]) }}" style="{{ $btn }};border:1px solid #1B3F6E;color:#1B3F6E;margin-left:0">{{ $cta }}</a>
                        @if($o['version_final_id'])
                        <a href="{{ route('operativo.distribucion.version', $o['version_final_id']) }}" target="_blank" style="{{ $btn }};border:none;background:#15803D;color:#fff">🔒 Ver final (congelada)</a>
                        @endif
                        <a href="{{ route('operativo.distribucion.reporte-obras', $o['id']) }}" style="{{ $btn }};border:1px solid #15803D;color:#15803D">⬇ Excel por obra</a>
                        <a href="{{ route('operativo.distribucion.trazabilidad', $o['id']) }}" style="{{ $btn }};border:1px solid #6366F1;color:#4338CA">Trazabilidad</a>
                    @endif
                    @if($a)
                        <a href="{{ route('operativo.distribucion.areas-consultar', $a['id']) }}" style="{{ $btn }};border:1px solid #D97706;color:#B45309{{ $o ? '' : ';margin-left:0' }}">Otros costos</a>
                    @endif
                    @if($o && $o['estado'] !== 'enviado')
                        <form method="POST" action="{{ route('operativo.distribucion.eliminar', $o['id']) }}" style="display:inline"
                              onsubmit="return confirm('¿Eliminar este borrador? No se puede deshacer.')">
                            @csrf @method('DELETE')
                            <button type="submit" style="{{ $btn }};border:1px solid #DC2626;color:#DC2626;background:white;cursor:pointer">Eliminar</button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection