@extends('layouts.app')

@section('title', 'Estados financieros')

@section('content')
<h1 class="page-title">Estados financieros</h1>

{{-- FILTROS --}}
<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('financiero.estados') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Hasta mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Modo</label>
            <select name="modo" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="acumulado" {{ $modo == 'acumulado' ? 'selected' : '' }}>Acumulado</option>
                <option value="mes" {{ $modo == 'mes' ? 'selected' : '' }}>Solo el mes</option>
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">
            Filtrar
        </button>
    </form>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

    {{-- P&G --}}
    <div class="card">
        <h3 style="margin-bottom:1rem">Estado de resultados (P&G)</h3>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <tr style="border-bottom:2px solid #E5E7EB">
                <td style="padding:8px 0;font-weight:600;color:#15803D">Ingresos operacionales</td>
                <td style="padding:8px 0;text-align:right;font-weight:600;color:#15803D">${{ number_format($ingresos, 0, ',', '.') }}</td>
            </tr>
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:8px 0 8px 16px;color:#6B7280">(-) Costos aplicados</td>
                <td style="padding:8px 0;text-align:right;color:#DC2626">-${{ number_format(abs($costosAplicados), 0, ',', '.') }}</td>
            </tr>
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:8px 0 8px 16px;color:#6B7280">(-) Costos por aplicar</td>
                <td style="padding:8px 0;text-align:right;color:#D97706">-${{ number_format(abs($costosPorAplicar), 0, ',', '.') }}</td>
            </tr>
            <tr style="border-bottom:2px solid #E5E7EB;background:#F9FAFB">
                <td style="padding:10px 0;font-weight:600">Utilidad bruta</td>
                <td style="padding:10px 0;text-align:right;font-weight:600;color:{{ $utilidadBruta >= 0 ? '#15803D' : '#DC2626' }}">
                    ${{ number_format($utilidadBruta, 0, ',', '.') }}
                    <span style="font-size:11px;color:#6B7280">({{ $margenBruto !== null ? $margenBruto.'%' : 'N/A' }})</span>
                </td>
            </tr>
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:8px 0 8px 16px;color:#6B7280">(-) Gastos operacionales</td>
                <td style="padding:8px 0;text-align:right;color:#DC2626">-${{ number_format(abs($gastos), 0, ',', '.') }}</td>
            </tr>
            <tr style="background:#1B3F6E">
                <td style="padding:12px;color:white;font-weight:600;border-radius:4px 0 0 4px">Utilidad neta</td>
                <td style="padding:12px;text-align:right;color:white;font-weight:600;border-radius:0 4px 4px 0">
                    ${{ number_format($utilidadNeta, 0, ',', '.') }}
                    <span style="font-size:11px;opacity:0.8">({{ $margenNeto !== null ? $margenNeto.'%' : 'N/A' }})</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- TOP PROYECTOS --}}
    <div class="card">
        <h3 style="margin-bottom:1rem">Top 10 proyectos por ingreso</h3>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
                <tr style="background:#F3F4F6">
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">#</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Proyecto</th>
                    <th style="text-align:right;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Ingreso</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topProyectos as $i => $tp)
                <tr style="border-bottom:1px solid #F3F4F6">
                    <td style="padding:6px 8px;color:#9CA3AF;font-size:11px">{{ $i + 1 }}</td>
                    <td style="padding:6px 8px">
                        <span style="font-size:11px;font-weight:500;color:#1B3F6E">{{ $tp->codigo_proyecto }}</span><br>
                        <span style="font-size:10px;color:#6B7280">{{ Str::limit($tp->nombre_proyecto, 35) }}</span>
                    </td>
                    <td style="padding:6px 8px;text-align:right;color:#059669;font-weight:500">
                        ${{ number_format($tp->total_ingreso, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>
@endsection