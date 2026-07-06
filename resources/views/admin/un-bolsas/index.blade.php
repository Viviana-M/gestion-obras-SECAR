@extends('layouts.app')

@section('title', 'Unidades de negocio (bolsas)')

@section('content')
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="page-title" style="margin:0">Unidades de negocio · bolsas de área</h1>
    <button type="button" onclick="document.getElementById('form-nueva').style.display='block'"
        style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;cursor:pointer">+ Nueva bolsa</button>
</div>

<p style="font-size:12px;color:#6B7280;margin:8px 0 1rem">
    Estas son las Unidades de Negocio “bolsa” cuyo costo operaciones reparte entre los proyectos. El sistema las trata aparte de los proyectos reales.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
</div>
@endif

{{-- Formulario nueva bolsa (oculto por defecto) --}}
<div id="form-nueva" style="display:none;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:1rem">
    <form method="POST" action="{{ route('admin.un-bolsas.store') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Código UN</label>
            <input type="text" name="codigo" placeholder="INS00099" required
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;text-transform:uppercase">
        </div>
        <div style="flex:1;min-width:200px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Nombre / descripción</label>
            <input type="text" name="nombre" placeholder="Instalaciones administración"
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
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

@php $depColor = ['mantenimiento' => ['#EFF6FF','#1B3F6E'], 'instalaciones' => ['#F0FDF4','#15803D']]; @endphp

<div class="card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Código</th>
                <th style="padding:10px 14px;text-align:left">Nombre</th>
                <th style="padding:10px 14px;text-align:center">Departamento</th>
                <th style="padding:10px 14px;text-align:center">Estado</th>
                <th style="padding:10px 14px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bolsas as $b)
            @php $dc = $depColor[$b->departamento] ?? ['#F3F4F6','#6B7280']; @endphp
            <tr style="border-bottom:1px solid #E5E7EB;{{ $b->activo ? '' : 'opacity:.5' }}">
                <td style="padding:10px 14px;font-family:monospace;font-weight:600;color:#1B3F6E">{{ $b->codigo }}</td>
                <td style="padding:10px 14px">
                    <form method="POST" action="{{ route('admin.un-bolsas.update', $b->id) }}" style="display:flex;gap:6px;align-items:center">
                        @csrf @method('PUT')
                        <input type="text" name="nombre" value="{{ $b->nombre }}" style="flex:1;min-width:160px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                        <select name="departamento" style="padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                            <option value="mantenimiento" {{ $b->departamento=='mantenimiento'?'selected':'' }}>Mantenimiento</option>
                            <option value="instalaciones" {{ $b->departamento=='instalaciones'?'selected':'' }}>Instalaciones</option>
                        </select>
                        <button type="submit" style="font-size:11px;padding:5px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;background:white;cursor:pointer">Guardar</button>
                    </form>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;padding:3px 10px;border-radius:8px;background:{{ $dc[0] }};color:{{ $dc[1] }};font-weight:500">{{ ucfirst($b->departamento) }}</span>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <span style="font-size:11px;color:{{ $b->activo ? '#15803D' : '#9CA3AF' }}">{{ $b->activo ? 'Activa' : 'Inactiva' }}</span>
                </td>
                <td style="padding:10px 14px;text-align:center">
                    <form method="POST" action="{{ route('admin.un-bolsas.toggle', $b->id) }}" style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" style="font-size:11px;padding:5px 12px;border:1px solid {{ $b->activo ? '#DC2626' : '#15803D' }};border-radius:6px;color:{{ $b->activo ? '#DC2626' : '#15803D' }};background:white;cursor:pointer">
                            {{ $b->activo ? 'Desactivar' : 'Activar' }}
                        </button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection