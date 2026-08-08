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

{{-- Sección 1: distribución de obras (inventario en tránsito) — la que se maneja hoy --}}
<div style="display:flex;align-items:center;gap:8px;margin-top:1.25rem">
    <span style="font-size:18px">📦</span>
    <h2 style="font-size:15px;font-weight:700;color:#1B3F6E;margin:0">Distribución de obras</h2>
    <span style="font-size:11px;color:#9CA3AF">· inventario en tránsito</span>
    <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#EFF6FF;color:#1B3F6E">{{ $filasObras->count() }}</span>
</div>
@include('operativo.partials.tabla-distribuciones', ['filas' => $filasObras, 'vacio' => 'Aún no hay distribuciones de obras guardadas.'])

{{-- Sección 2: otros costos (áreas / bolsas) — pendiente de su propio guardado --}}
<div style="display:flex;align-items:center;gap:8px;margin-top:1.75rem">
    <span style="font-size:18px">🏢</span>
    <h2 style="font-size:15px;font-weight:700;color:#1B3F6E;margin:0">Otros costos</h2>
    <span style="font-size:11px;color:#9CA3AF">· áreas / bolsas</span>
    <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#F3F4F6;color:#6B7280">{{ $filasAreas->count() }}</span>
</div>
@include('operativo.partials.tabla-distribuciones', ['filas' => $filasAreas, 'vacio' => 'Aún no se guardan distribuciones de otros costos (áreas). Esta sección se activará cuando esa distribución tenga su propio guardado.'])
@endsection