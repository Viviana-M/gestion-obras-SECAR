@extends('layouts.app')

@section('title', 'Históricos de proyectos')

@section('content')
<h1 class="page-title">Históricos — Proyectos cerrados</h1>

{{-- FILTROS --}}
<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('financiero.historicos') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Proyecto</label>
            <select name="proyecto" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:240px">
                <option value="">Todos los proyectos cerrados</option>
                @foreach($proyectos as $p)
                    <option value="{{ $p->codigo_proyecto }}" {{ $proyecto == $p->codigo_proyecto ? 'selected' : '' }}>
                        {{ $p->codigo_proyecto }} - {{ $p->nombre_proyecto }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">
            Filtrar
        </button>
        <a href="{{ route('financiero.historicos') }}" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none;height:36px;display:flex;align-items:center">
            Limpiar
        </a>
    </form>
</div>

{{-- TARJETAS RESUMEN --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#F0FDF4;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#15803D;margin-bottom:4px">Total ingresos</div>
        <div style="font-size:18px;font-weight:600;color:#15803D">${{ number_format($totalIngreso, 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Costos aplicados</div>
        <div style="font-size:18px;font-weight:600;color:#DC2626">${{ number_format(abs($totalCostoAplicado), 0, ',', '.') }}</div>
    </div>
    <div style="background:#FEF9C3;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#854D0E;margin-bottom:4px">Costos por aplicar</div>
        <div style="font-size:18px;font-weight:600;color:#854D0E">${{ number_format(abs($totalCostoPorAplicar), 0, ',', '.') }}</div>
    </div>
    <div style="background:{{ $totalMargen >= 15 ? '#F0FDF4' : ($totalMargen >= 0 ? '#FEF9C3' : '#FEF2F2') }};border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Margen bruto</div>
        <div style="font-size:18px;font-weight:600;color:{{ $totalMargen >= 15 ? '#15803D' : ($totalMargen >= 0 ? '#854D0E' : '#DC2626') }}">
            {{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}
        </div>
    </div>
</div>

{{-- TABLA --}}
<div class="card" style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Proyecto / OT</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Fecha cierre</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Tipo</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Ingresos</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Costos aplicados</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Costos x aplicar</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Utilidad</th>
                <th style="text-align:right;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">% MC</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">KPI</th>
            </tr>
        </thead>
        <tbody>
            @forelse($proyectosData as $cod => $p)
            @php
                $colorMC = $p['margen_pct'] === null ? '#DC2626' : ($p['margen_pct'] >= 15 ? '#16A34A' : ($p['margen_pct'] >= 5 ? '#D97706' : '#DC2626'));
            @endphp
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:7px 10px;font-weight:500;color:#1B3F6E">
                    {{ $p['codigo'] }}<br>
                    <span style="font-size:10px;color:#6B7280;font-weight:400">{{ $p['nombre'] }}</span>
                </td>
                <td style="padding:7px 10px;font-size:11px;color:#6B7280">{{ $p['fecha_cierre'] }}</td>
                <td style="padding:7px 10px">
                    @if($p['tipo_cierre'] === 'total')
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 8px;border-radius:10px">Total</span>
                    @elseif($p['tipo_cierre'] === 'parcial')
                        <span style="background:#FEF9C3;color:#854D0E;font-size:10px;padding:2px 8px;border-radius:10px">Parcial</span>
                    @else
                        <span style="font-size:10px;color:#9CA3AF">—</span>
                    @endif
                </td>
                <td style="padding:7px 10px;text-align:right;color:#059669">${{ number_format($p['ingreso'], 0, ',', '.') }}</td>
                <td style="padding:7px 10px;text-align:right;color:#DC2626">-${{ number_format(abs($p['costo_aplicado']), 0, ',', '.') }}</td>
                <td style="padding:7px 10px;text-align:right;color:#D97706">-${{ number_format(abs($p['costo_por_aplicar']), 0, ',', '.') }}</td>
                <td style="padding:7px 10px;text-align:right;font-weight:600;color:{{ $p['utilidad'] >= 0 ? '#15803D' : '#DC2626' }}">
                    ${{ number_format($p['utilidad'], 0, ',', '.') }}
                </td>
                <td style="padding:7px 10px;text-align:right;font-weight:600;color:{{ $colorMC }}">
                    {{ $p['margen_pct'] !== null ? $p['margen_pct'] . '%' : 'N/A' }}
                </td>
                <td style="padding:7px 10px;text-align:center">
                    @if($p['margen_pct'] === null)
                        <span style="color:#DC2626;font-size:16px">●</span>
                    @elseif($p['margen_pct'] >= 15)
                        <span style="color:#16A34A;font-size:16px">●</span>
                    @elseif($p['margen_pct'] >= 5)
                        <span style="color:#D97706;font-size:16px">●</span>
                    @else
                        <span style="color:#DC2626;font-size:16px">●</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" style="text-align:center;padding:2rem;color:#9CA3AF">
                    No hay proyectos cerrados registrados. Ve a Contable → Cierre de obras para registrarlos.
                </td>
            </tr>
            @endforelse

            {{-- TOTAL --}}
            @if(count($proyectosData) > 0)
            <tr style="background:#1B3F6E">
                <td colspan="3" style="padding:10px;color:white;font-weight:600">Total general</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalIngreso, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">-${{ number_format(abs($totalCostoAplicado), 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">-${{ number_format(abs($totalCostoPorAplicar), 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">${{ number_format($totalUtilidad, 0, ',', '.') }}</td>
                <td style="padding:10px;text-align:right;color:white;font-weight:600">{{ $totalMargen !== null ? $totalMargen . '%' : 'N/A' }}</td>
                <td style="padding:10px;text-align:center">
                    @if($totalMargen === null)
                        <span style="color:#FCA5A5;font-size:16px">●</span>
                    @elseif($totalMargen >= 15)
                        <span style="color:#86EFAC;font-size:16px">●</span>
                    @elseif($totalMargen >= 5)
                        <span style="color:#FDE68A;font-size:16px">●</span>
                    @else
                        <span style="color:#FCA5A5;font-size:16px">●</span>
                    @endif
                </td>
            </tr>
            @endif
        </tbody>
    </table>
</div>
@endsection