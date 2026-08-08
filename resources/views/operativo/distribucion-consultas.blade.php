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

{{-- Sección 1: distribución de costos (inventario en tránsito) --}}
<div style="display:flex;align-items:center;gap:8px;margin-top:1.25rem">
    <span style="font-size:18px">📦</span>
    <h2 style="font-size:15px;font-weight:700;color:#1B3F6E;margin:0">Distribución de costos</h2>
    <span style="font-size:11px;color:#9CA3AF">· inventario en tránsito</span>
    <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#EFF6FF;color:#1B3F6E">{{ $filasObras->count() }}</span>
</div>
@include('operativo.partials.tabla-distribuciones', ['filas' => $filasObras, 'vacio' => 'Aún no hay distribuciones de costos guardadas.'])

{{-- Sección 2: otros costos (áreas / bolsas) — se guarda al guardar los montos de la bolsa --}}
<div style="display:flex;align-items:center;gap:8px;margin-top:1.75rem">
    <span style="font-size:18px">🏢</span>
    <h2 style="font-size:15px;font-weight:700;color:#1B3F6E;margin:0">Otros costos</h2>
    <span style="font-size:11px;color:#9CA3AF">· áreas / bolsas</span>
    <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#F3F4F6;color:#6B7280">{{ $filasAreas->count() }}</span>
</div>
<div class="card" style="margin-top:.75rem;padding:0;overflow:hidden">
    @if($filasAreas->count() == 0)
        <div style="text-align:center;color:#9CA3AF;padding:2rem">Aún no hay distribuciones de otros costos. Se guardan al dar "Guardar montos" en una bolsa de área.</div>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Período</th>
                <th style="padding:10px 14px;text-align:left">Guardado</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:right">Cuentas</th>
                <th style="padding:10px 14px;text-align:right">A cargar en el mes</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($filasAreas as $f)
            @php $prefDep = ['mantenimiento' => 'MT', 'instalaciones' => 'IN'][$f['departamento'] ?? ''] ?? ''; @endphp
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:10px 14px;font-weight:500;color:#1B3F6E">
                    {{ $meses[$f['mes']] ?? $f['mes'] }} {{ $f['anio'] }}
                    @if($f['departamento'])
                        <span style="font-size:10px;padding:2px 8px;border-radius:8px;background:#EEF2FF;color:#4338CA;margin-left:6px">{{ ucfirst($f['departamento']) }}</span>
                    @endif
                </td>
                <td style="padding:10px 14px;color:#6B7280">
                    {{ $f['guardado_at']?->format('d/m/Y H:i') }}
                    <div style="font-size:11px;color:#9CA3AF">por {{ $f['guardado_por'] }}</div>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:#F3F4F6;color:#6B7280;font-weight:500">Borrador</span>
                </td>
                <td style="padding:10px 14px;text-align:right">{{ $f['cuentas'] }}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:600">{{ $fmt($f['a_distribuir']) }}</td>
                <td style="padding:10px 14px;text-align:center;white-space:nowrap">
                    <a href="{{ route('operativo.distribucion.areas-consultar', $f['id']) }}"
                       style="font-size:12px;padding:6px 12px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;text-decoration:none">Consultar</a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection