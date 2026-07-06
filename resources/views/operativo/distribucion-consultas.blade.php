@extends('layouts.app')

@section('title', 'Mis distribuciones')

@section('content')
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="page-title" style="margin:0">Mis distribuciones</h1>
    <a href="{{ route('operativo.distribucion') }}" style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border-radius:8px;text-decoration:none">+ Nuevo borrador</a>
</div>

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

<div class="card" style="margin-top:1rem;padding:0;overflow:hidden">
    @if($filas->count() == 0)
        <div style="text-align:center;color:#9CA3AF;padding:2rem">Aún no hay borradores guardados.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Período</th>
                <th style="padding:10px 14px;text-align:left">Guardado</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:right">Obras</th>
                <th style="padding:10px 14px;text-align:right">Aplicado</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($filas as $f)
            @php
                if ($f['estado'] === 'enviado' && !$f['habilitada']) {
                    $badge = ['#EFF6FF', '#1B3F6E', 'Enviado'];     $cta = 'Consultar';
                } elseif ($f['estado'] === 'enviado' && $f['habilitada']) {
                    $badge = ['#FEF9C3', '#854D0E', 'Enviado · habilitado']; $cta = 'Editar';
                } else {
                    $badge = ['#F3F4F6', '#6B7280', 'Borrador'];    $cta = 'Abrir / editar';
                }
            @endphp
            <tr style="border-bottom:1px solid #E5E7EB">
                @php
                    $prefDep = ['mantenimiento' => 'MT', 'instalaciones' => 'IN'][$f['departamento'] ?? ''] ?? '';
                @endphp
                <td style="padding:10px 14px;font-weight:500;color:#1B3F6E">
                    {{ $meses[$f['mes']] ?? $f['mes'] }} {{ $f['anio'] }} ·
                    <span style="color:#6B7280;font-weight:400">{{ $prefDep ? $prefDep.'-' : '' }}v{{ $f['version'] }}</span>
                    @if($f['departamento'])
                        <span style="font-size:10px;padding:2px 8px;border-radius:8px;background:#EEF2FF;color:#4338CA;margin-left:6px">{{ ucfirst($f['departamento']) }}</span>
                    @endif
                </td>
                <td style="padding:10px 14px;color:#6B7280">
                    {{ $f['guardado_at']?->format('d/m/Y H:i') }}
                    <div style="font-size:11px;color:#9CA3AF">por {{ $f['guardado_por'] }}</div>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:{{ $badge[0] }};color:{{ $badge[1] }};font-weight:500">{{ $badge[2] }}</span>
                </td>
                <td style="padding:10px 14px;text-align:right">{{ $f['obras'] }}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:600">{{ $fmt($f['aplicado']) }}</td>
                <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                    <a href="{{ route('operativo.distribucion', ['dist' => $f['id']]) }}"
                       style="font-size:12px;padding:6px 12px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;text-decoration:none">{{ $cta }}</a>
                    <a href="{{ route('operativo.distribucion.trazabilidad', $f['id']) }}"
                       style="font-size:12px;padding:6px 12px;border:1px solid #6366F1;border-radius:6px;color:#4338CA;text-decoration:none;margin-left:4px">Trazabilidad</a>
                    @if($f['estado'] !== 'enviado')
                    <form method="POST" action="{{ route('operativo.distribucion.eliminar', $f['id']) }}" style="display:inline"
                          onsubmit="return confirm('¿Eliminar este borrador? No se puede deshacer.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="font-size:12px;padding:6px 12px;border:1px solid #DC2626;border-radius:6px;color:#DC2626;background:white;cursor:pointer;margin-left:4px">Eliminar</button>
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