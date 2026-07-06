@extends('layouts.app')

@section('title', 'Terceros de mano de obra')

@section('content')
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="page-title" style="margin:0">Terceros de mano de obra · reparto por porcentaje</h1>
    <button type="button" onclick="document.getElementById('form-nueva').style.display='block'"
        style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;cursor:pointer">+ Nueva persona</button>
</div>

<p style="font-size:12px;color:#6B7280;margin:8px 0 1rem">
    Personas de mantenimiento cuya mano de obra se reparte por porcentajes entre proyectos. Aquí solo se administran las cédulas y nombres; los porcentajes los define operaciones al distribuir.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
</div>
@endif

{{-- Formulario nueva persona (oculto por defecto) --}}
<div id="form-nueva" style="display:none;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:1rem">
    <form method="POST" action="{{ route('admin.terceros-mano-obra.store') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Cédula</label>
            <input type="text" name="cedula" placeholder="1234567890" required
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div style="flex:1;min-width:220px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Nombre completo</label>
            <input type="text" name="nombre" placeholder="APELLIDOS NOMBRES" required
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;text-transform:uppercase">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Departamento</label>
            <select name="departamento" required style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="mantenimiento">Mantenimiento</option>
                <option value="instalaciones">Instalaciones</option>
            </select>
        </div>
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Guardar</button>
        <button type="button" onclick="document.getElementById('form-nueva').style.display='none'"
            style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer;height:36px">Cancelar</button>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Cédula</th>
                <th style="padding:10px 14px;text-align:left">Nombre</th>
                <th style="padding:10px 14px;text-align:center">Departamento</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($terceros as $t)
            <tr style="border-bottom:1px solid #E5E7EB;{{ $t->activo ? '' : 'opacity:.5' }}">
                <td style="padding:10px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $t->cedula }}</td>
                <td style="padding:10px 14px">
                    <form method="POST" action="{{ route('admin.terceros-mano-obra.update', $t->id) }}" style="display:flex;gap:6px;align-items:center">
                        @csrf @method('PUT')
                        <input type="text" name="nombre" value="{{ $t->nombre }}" style="flex:1;min-width:200px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-transform:uppercase">
                        <select name="departamento" style="padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                            <option value="mantenimiento" {{ ($t->departamento ?? 'mantenimiento')=='mantenimiento'?'selected':'' }}>Mantenimiento</option>
                            <option value="instalaciones" {{ ($t->departamento ?? '')=='instalaciones'?'selected':'' }}>Instalaciones</option>
                        </select>
                        <button type="submit" style="font-size:11px;padding:5px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;background:white;cursor:pointer">Guardar</button>
                    </form>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    @php $tc = ($t->departamento ?? 'mantenimiento')=='instalaciones' ? ['#F0FDF4','#15803D'] : ['#EFF6FF','#1B3F6E']; @endphp
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:{{ $tc[0] }};color:{{ $tc[1] }};font-weight:500">{{ ucfirst($t->departamento ?? 'mantenimiento') }}</span>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;color:{{ $t->activo ? '#15803D' : '#9CA3AF' }}">{{ $t->activo ? 'Activo' : 'Inactivo' }}</span>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <form method="POST" action="{{ route('admin.terceros-mano-obra.toggle', $t->id) }}" style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" style="font-size:11px;padding:5px 12px;border:1px solid {{ $t->activo ? '#DC2626' : '#15803D' }};border-radius:6px;color:{{ $t->activo ? '#DC2626' : '#15803D' }};background:white;cursor:pointer">
                            {{ $t->activo ? 'Desactivar' : 'Activar' }}
                        </button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection