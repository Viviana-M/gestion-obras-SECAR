@extends('layouts.app')

@section('title', 'Cierre de obras')

@section('content')
<h1 class="page-title">Cierre de obras</h1>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">
    {{ session('success') }}
</div>
@endif

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">

    {{-- CARGA EXCEL --}}
    <div class="card">
        <h3>Carga masiva desde Excel</h3>
        <p style="font-size:12px;color:#6B7280;margin-bottom:1rem">
            El archivo debe tener columnas en este orden:<br>
            <strong>A:</strong> Código proyecto &nbsp;
            <strong>B:</strong> Nombre (opcional) &nbsp;
            <strong>C:</strong> Fecha cierre (opcional) &nbsp;
            <strong>D:</strong> Tipo (total/parcial) &nbsp;
            <strong>E:</strong> Observación (opcional)
        </p>
        <form method="POST" action="{{ route('contable.cierre-obras.excel') }}" enctype="multipart/form-data">
            @csrf
            <div style="margin-bottom:10px">
                <input type="file" name="archivo" accept=".xlsx,.xls,.csv"
                    style="width:100%;padding:7px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
            </div>
            <button type="submit"
                style="padding:8px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
                Importar Excel
            </button>
        </form>
    </div>

    {{-- CIERRE MANUAL --}}
    <div class="card">
        <h3>Cerrar proyecto manualmente</h3>
        <form method="POST" action="{{ route('contable.cierre-obras.manual') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Código proyecto</label>
                    <input type="text" name="codigo_proyecto" placeholder="Ej: C1101401"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Fecha de cierre</label>
                    <input type="date" name="fecha_cierre" value="{{ date('Y-m-d') }}"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Tipo de cierre</label>
                    <select name="tipo_cierre"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                        <option value="total">Total</option>
                        <option value="parcial">Parcial</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Observación</label>
                    <input type="text" name="observacion" placeholder="Opcional"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>
            </div>
            <button type="submit"
                style="padding:8px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">
                Registrar cierre
            </button>
        </form>
    </div>
</div>

{{-- TABLA DE PROYECTOS CERRADOS --}}
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3>Proyectos cerrados — {{ $totalCerrados }} registros</h3>
    </div>

    @if($proyectosCerrados->count() == 0)
        <p style="color:#9CA3AF;font-size:13px;text-align:center;padding:2rem">
            No hay proyectos cerrados registrados aún.
        </p>
    @else
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Código</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Nombre proyecto</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Fecha cierre</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Tipo</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Origen</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Registrado por</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Observación</th>
                @if(auth()->user()->rol === 'admin')
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($proyectosCerrados as $p)
            <tr style="border-bottom:1px solid #F3F4F6">
                <td style="padding:7px 10px;font-family:monospace;font-size:11px">{{ $p->codigo_proyecto }}</td>
                <td style="padding:7px 10px">{{ $p->nombre_proyecto }}</td>
                <td style="padding:7px 10px;color:#6B7280">
                    {{ $p->fecha_cierre ? $p->fecha_cierre->format('d/m/Y') : '—' }}
                </td>
                <td style="padding:7px 10px">
                    @if($p->tipo_cierre === 'total')
                        <span style="background:#FEF2F2;color:#DC2626;font-size:10px;padding:2px 8px;border-radius:10px">Total</span>
                    @else
                        <span style="background:#FEF9C3;color:#854D0E;font-size:10px;padding:2px 8px;border-radius:10px">Parcial</span>
                    @endif
                </td>
                <td style="padding:7px 10px">
                    @if($p->origen === 'excel')
                        <span style="background:#F0FDF4;color:#15803D;font-size:10px;padding:2px 8px;border-radius:10px">Excel</span>
                    @elseif($p->origen === 'forecast')
                        <span style="background:#EFF6FF;color:#1D4ED8;font-size:10px;padding:2px 8px;border-radius:10px">Forecast</span>
                    @else
                        <span style="background:#F3F4F6;color:#6B7280;font-size:10px;padding:2px 8px;border-radius:10px">Manual</span>
                    @endif
                </td>
                <td style="padding:7px 10px;font-size:11px;color:#6B7280">
                    {{ $p->usuario->name ?? 'N/A' }}
                </td>
                <td style="padding:7px 10px;font-size:11px;color:#6B7280">
                    {{ $p->observacion ?? '—' }}
                </td>
                @if(auth()->user()->rol === 'admin')
                <td style="padding:7px 10px">
                    <form method="POST" action="{{ route('contable.cierre-obras.eliminar', $p->id) }}"
                        onsubmit="return confirm('¿Eliminar el cierre del proyecto {{ $p->codigo_proyecto }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            style="font-size:11px;padding:3px 10px;color:#DC2626;border:1px solid #FECACA;border-radius:6px;background:white;cursor:pointer">
                            Eliminar
                        </button>
                    </form>
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection