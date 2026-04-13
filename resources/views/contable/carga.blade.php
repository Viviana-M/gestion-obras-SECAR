@extends('layouts.app')

@section('title', 'Cargar información financiera')

@section('content')
    <h1 class="page-title">Carga de información financiera</h1>

    @if(session('success'))
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem;">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="card">
        <h3>Período a cargar</h3>
        <form method="POST" action="{{ route('contable.carga.store') }}" enctype="multipart/form-data">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem">
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Mes</label>
                    <select name="mes" style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                        <option value="1">Enero</option>
                        <option value="2">Febrero</option>
                        <option value="3">Marzo</option>
                        <option value="4">Abril</option>
                        <option value="5">Mayo</option>
                        <option value="6">Junio</option>
                        <option value="7">Julio</option>
                        <option value="8">Agosto</option>
                        <option value="9">Septiembre</option>
                        <option value="10">Octubre</option>
                        <option value="11">Noviembre</option>
                        <option value="12">Diciembre</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
                    <select name="anio" style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
    @for($y = 2022; $y <= date('Y') + 1; $y++)
        <option value="{{ $y }}" {{ $y == date('Y') ? 'selected' : '' }}>{{ $y }}</option>
    @endfor
</select>
                </div>
            </div>

            <div style="margin-bottom:1rem">
                <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Archivo Excel o CSV del BIABLE</label>
                <input type="file" name="archivo" accept=".xlsx,.xls,.csv"
                    style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                <small style="font-size:11px;color:#9CA3AF">Formatos aceptados: .xlsx, .xls, .csv — máximo 50MB</small>
            </div>

            <button type="submit"
                style="padding:9px 24px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer">
                Cargar archivo
            </button>
        </form>
    </div>

    @if($historial->count() > 0)
    <div class="card">
        <h3>Historial de cargas</h3>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Período</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Archivo</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Registros</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cargado por</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Fecha</th>
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Estado</th>
                    @if(auth()->user()->rol === 'admin')
                    <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($historial as $carga)
                <tr>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6;font-weight:500">
                        {{ \Carbon\Carbon::create()->month($carga->mes)->locale('es')->monthName }}
                        {{ $carga->anio }}
                    </td>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6;font-size:12px;color:#6B7280">
                        {{ $carga->archivo_original }}
                    </td>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6">
                        {{ number_format($carga->registros) }}
                    </td>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6;font-size:12px">
                        {{ $carga->usuario->name ?? 'N/A' }}
                    </td>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6;font-size:12px;color:#6B7280">
                        {{ $carga->created_at->format('d/m/Y H:i') }}
                    </td>
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6">
                        @if($carga->estado === 'completado')
                            <span style="background:#F0FDF4;color:#15803D;font-size:11px;padding:2px 8px;border-radius:10px">Completado</span>
                        @elseif($carga->estado === 'procesando')
                            <span style="background:#FEF9C3;color:#854D0E;font-size:11px;padding:2px 8px;border-radius:10px">Procesando...</span>
                        @else
                            <span style="background:#FEF2F2;color:#DC2626;font-size:11px;padding:2px 8px;border-radius:10px">Error</span>
                        @endif
                    </td>
                    @if(auth()->user()->rol === 'admin')
                    <td style="padding:8px;border-bottom:1px solid #F3F4F6">
                        <form method="POST" action="{{ route('contable.carga.eliminar', $carga->id) }}"
                            onsubmit="return confirm('¿Eliminar la carga de {{ $carga->mes }}/{{ $carga->anio }}? Esto borrará todos los registros de ese período.')">
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
    </div>
    @endif
@endsection