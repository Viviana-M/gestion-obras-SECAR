@extends('layouts.app')

@section('title', 'Llave de cuentas por ítem')

@section('content')
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="page-title" style="margin:0">Llave de cuentas por ítem</h1>
    <button type="button" onclick="document.getElementById('form-nueva').style.display='block'"
        style="font-size:13px;padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;cursor:pointer">+ Nueva llave</button>
</div>

<p style="font-size:12px;color:#6B7280;margin:8px 0 1rem">
    Mapea la combinación <b>tipo de inventario + tipo de movimiento (motivo)</b> → <b>cuenta</b> de costo/gasto.
    Cuando coinciden el tipo de inventario y el movimiento, esa es la cuenta. Es la llave que usará el cargue de ítems.
</p>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">
    @foreach($errors->all() as $e) <div>{{ $e }}</div> @endforeach
</div>
@endif

{{-- Cargue por Excel --}}
<div class="card" style="margin-bottom:1rem;background:#F9FAFB">
    <form method="POST" action="{{ route('admin.llave-items.importar') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div style="flex:1;min-width:240px">
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Cargar Excel (tipo de inventario · tipo de movimiento · cuenta)</label>
            <input type="file" name="archivo" accept=".xlsx,.xls" required
                style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white;width:100%">
        </div>
        <button type="submit" style="padding:9px 18px;background:#15803D;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:38px">⬆ Cargar</button>
    </form>
    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">
        El archivo debe tener columnas para <b>tipo de inventario</b>, <b>tipo de movimiento</b> y <b>cuenta</b> (opcionalmente nombre y naturaleza).
        Recargar el mismo par reemplaza su cuenta (no duplica).
    </p>
</div>

{{-- Formulario nueva llave (oculto por defecto) --}}
<div id="form-nueva" style="display:{{ $errors->any() ? 'block' : 'none' }};background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px;margin-bottom:1rem">
    <form method="POST" action="{{ route('admin.llave-items.store') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Tipo de inventario</label>
            <input type="text" name="tipo_inventario" value="{{ old('tipo_inventario') }}" required
                style="width:150px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div style="flex:1;min-width:180px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Nombre tipo inventario</label>
            <input type="text" name="nombre_tipo_inventario" value="{{ old('nombre_tipo_inventario') }}"
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div style="flex:1;min-width:220px">
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Tipo de movimiento (motivo)</label>
            <input type="text" name="tipo_movimiento" value="{{ old('tipo_movimiento') }}" required placeholder="Salida Directa Inventario en Obra"
                style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Cuenta</label>
            <input type="text" name="cuenta" value="{{ old('cuenta') }}" required
                style="width:130px;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Naturaleza</label>
            <select name="naturaleza" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="">—</option>
                <option value="Débito" {{ old('naturaleza')=='Débito'?'selected':'' }}>Débito</option>
                <option value="Crédito" {{ old('naturaleza')=='Crédito'?'selected':'' }}>Crédito</option>
            </select>
        </div>
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Guardar</button>
        <button type="button" onclick="document.getElementById('form-nueva').style.display='none'"
            style="padding:7px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer;height:36px">Cancelar</button>
    </form>
</div>

{{-- Buscador --}}
<form method="GET" action="{{ route('admin.llave-items.index') }}" style="margin-bottom:1rem;display:flex;gap:8px">
    <input type="text" name="q" value="{{ $q }}" placeholder="🔎 Tipo, movimiento o cuenta…" autocomplete="off"
        style="padding:8px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;width:260px">
    <button type="submit" style="padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Buscar</button>
    @if($q !== '')
        <a href="{{ route('admin.llave-items.index') }}" style="padding:8px 14px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;text-decoration:none">Limpiar</a>
    @endif
</form>

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:820px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 12px;text-align:left">Llave: tipo de inventario + movimiento → cuenta</th>
                <th style="padding:10px 12px;text-align:center">Estado</th>
                <th style="padding:10px 12px;text-align:center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $it)
            <tr style="border-bottom:1px solid #E5E7EB;{{ $it->activo ? '' : 'opacity:.5' }}">
                <td style="padding:8px 12px">
                    {{-- El form de edición va COMPLETO en una sola celda (HTML válido dentro de la tabla) --}}
                    <form method="POST" action="{{ route('admin.llave-items.update', $it->id) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        @csrf @method('PUT')
                        <input type="text" name="tipo_inventario" value="{{ $it->tipo_inventario }}" title="Tipo de inventario" style="width:110px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;font-family:monospace">
                        <input type="text" name="nombre_tipo_inventario" value="{{ $it->nombre_tipo_inventario }}" placeholder="nombre (opcional)" style="width:150px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                        <span style="color:#9CA3AF">+</span>
                        <input type="text" name="tipo_movimiento" value="{{ $it->tipo_movimiento }}" title="Tipo de movimiento" style="flex:1;min-width:200px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                        <span style="color:#9CA3AF">→</span>
                        <input type="text" name="cuenta" value="{{ $it->cuenta }}" title="Cuenta" style="width:110px;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;font-family:monospace">
                        <select name="naturaleza" title="Naturaleza" style="padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                            <option value="" {{ !$it->naturaleza ? 'selected' : '' }}>—</option>
                            <option value="Débito" {{ $it->naturaleza=='Débito'?'selected':'' }}>Débito</option>
                            <option value="Crédito" {{ $it->naturaleza=='Crédito'?'selected':'' }}>Crédito</option>
                        </select>
                        <button type="submit" style="font-size:11px;padding:5px 10px;border:1px solid #1B3F6E;border-radius:6px;color:#1B3F6E;background:white;cursor:pointer">Guardar</button>
                    </form>
                </td>
                <td style="padding:8px 12px;text-align:center">
                    <span style="font-size:11px;color:{{ $it->activo ? '#15803D' : '#9CA3AF' }}">{{ $it->activo ? 'Activo' : 'Inactivo' }}</span>
                </td>
                <td style="padding:8px 12px;text-align:center">
                    <form method="POST" action="{{ route('admin.llave-items.toggle', $it->id) }}" style="display:inline">
                        @csrf @method('PUT')
                        <button type="submit" style="font-size:11px;padding:5px 12px;border:1px solid {{ $it->activo ? '#DC2626' : '#15803D' }};border-radius:6px;color:{{ $it->activo ? '#DC2626' : '#15803D' }};background:white;cursor:pointer">{{ $it->activo ? 'Desactivar' : 'Activar' }}</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:#9CA3AF">{{ $q !== '' ? 'Sin resultados para la búsqueda.' : 'Aún no hay llaves. Carga un Excel o agrega una manualmente.' }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
