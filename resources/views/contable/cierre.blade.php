@extends('layouts.app')

@section('title', 'Cierre de mes')

@section('content')
@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
@endphp

<h1 class="page-title">Cierre de mes (edición de Distribución)</h1>
<p style="color:#6B7280;font-size:13px;margin:-6px 0 16px">
    Contabilidad <b>abre el cierre</b> de un mes para que Operaciones pueda editar la distribución de ese período.
    Mientras esté <b>cerrado</b> (o sin abrir), la distribución de ese mes es de <b>solo lectura</b>.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;color:#DC2626;border-radius:8px;padding:9px 14px;font-size:13px;margin-bottom:1rem">{{ session('error') }}</div>
@endif

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:520px">
        <thead>
            <tr style="color:#9CA3AF;text-align:left;border-bottom:1px solid #E5E7EB">
                <th style="padding:10px 16px">Período</th>
                <th style="padding:10px 16px">Estado</th>
                @if($puedeEditar)<th style="padding:10px 16px;text-align:right">Acción</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach($periodos as $p)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:10px 16px;font-weight:600;color:#1B3F6E">{{ $nombresMes[$p['mes']] }} {{ $p['anio'] }}</td>
                <td style="padding:10px 16px">
                    @if($p['abierto'])
                        <span style="font-weight:600;padding:2px 10px;border-radius:10px;background:#DCFCE7;color:#15803D">🔓 Cierre abierto — Operaciones edita</span>
                    @else
                        <span style="font-weight:600;padding:2px 10px;border-radius:10px;background:#F3F4F6;color:#6B7280">🔒 Cerrado — solo lectura</span>
                    @endif
                </td>
                @if($puedeEditar)
                <td style="padding:8px 16px;text-align:right">
                    <form method="POST" action="{{ route('contable.cierre.toggle') }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="mes" value="{{ $p['mes'] }}">
                        <input type="hidden" name="anio" value="{{ $p['anio'] }}">
                        <input type="hidden" name="accion" value="{{ $p['abierto'] ? 'cerrar' : 'abrir' }}">
                        @if($p['abierto'])
                            <button type="submit" style="padding:6px 14px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;font-size:12px;cursor:pointer">Cerrar</button>
                        @else
                            <button type="submit" style="padding:6px 14px;border:none;border-radius:8px;background:#15803D;color:white;font-size:12px;cursor:pointer">Abrir cierre</button>
                        @endif
                    </form>
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
