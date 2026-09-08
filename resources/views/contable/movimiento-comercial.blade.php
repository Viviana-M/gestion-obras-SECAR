@extends('layouts.app')

@section('title', 'Cargue de movimiento comercial')

@section('content')
@php
    $puedeEditar = auth()->user()->puedeEditarModulo('contabilidad');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
@endphp

<x-page-banner title="Cargue de movimiento comercial (ítems)" icon="🛒">
    Sube el Excel BIABLE (hoja <b>Comercial_Mvto</b>); cada ítem se cruza con la llave de cuentas y alimenta la conciliación de la Distribución.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('warning'))
<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 14px;font-size:13px;color:#92400E;margin-bottom:1rem">⚠ {{ session('warning') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

@if($puedeEditar)
<div class="card" style="margin-bottom:1.25rem;background:#F9FAFB">
    <form method="POST" action="{{ route('contable.movimiento-comercial.store') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div style="flex:1;min-width:260px">
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Excel BIABLE (hoja Comercial_Mvto)</label>
            <input type="file" name="archivo" accept=".xlsx,.xls" required
                style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px;background:white;width:100%">
        </div>
        <button type="submit" style="padding:9px 20px;background:#15803D;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:38px">⬆ Cargar</button>
    </form>
    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">
        El período (mes/año) se toma de la columna <b>Periodo</b> (YYYYMM) de cada fila. Los ítems que no crucen con la llave se reportan.
    </p>
    @php
        $columnasMovimiento = [
            ['nombre' => 'Unidad de Negocio', 'nota' => 'Código de la obra/OT'],
            ['nombre' => 'Periodo', 'nota' => 'YYYYMM; define el mes/año'],
            ['nombre' => 'Tipo de Inventario'],
            ['nombre' => 'Motivo', 'nota' => 'Código de movimiento'],
            ['nombre' => 'Costo_prom_net', 'nota' => 'Costo promedio neto'],
            ['nombre' => 'Motivo (descripción)', 'opcional' => true],
            ['nombre' => 'Nombre Item', 'opcional' => true],
            ['nombre' => 'Nombre Tercero', 'opcional' => true],
            ['nombre' => 'Cantidad_net_1', 'opcional' => true],
            ['nombre' => 'Fecha', 'opcional' => true],
            ['nombre' => 'Numero Documento', 'opcional' => true],
        ];
    @endphp
    <x-columnas-plano :numerar="false"
        titulo="La hoja Comercial_Mvto debe traer estas columnas (se identifican por su nombre):"
        :columnas="$columnasMovimiento">
        Las columnas marcadas se reconocen por su encabezado (no importa el orden). Las <i>(opcional)</i> enriquecen el detalle pero no son obligatorias.
    </x-columnas-plano>
</div>
@else
<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:9px 14px;font-size:12.5px;color:#6B7280;margin-bottom:1rem">
    👁 Modo solo lectura. Puedes consultar lo cargado pero no subir archivos.
</div>
@endif

<div class="card" style="padding:0;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:420px">
        <thead>
            <tr style="background:#1B3F6E;color:white">
                <th style="padding:10px 14px;text-align:left">Período</th>
                <th style="padding:10px 14px;text-align:right">Ítems</th>
                <th style="padding:10px 14px;text-align:right">Obras</th>
            </tr>
        </thead>
        <tbody>
            @forelse($porPeriodo as $p)
            <tr style="border-bottom:1px solid #E5E7EB">
                <td style="padding:9px 14px">{{ $nombresMes[$p->mes] ?? $p->mes }} {{ $p->anio }}</td>
                <td style="padding:9px 14px;text-align:right">{{ number_format($p->filas, 0, ',', '.') }}</td>
                <td style="padding:9px 14px;text-align:right">{{ number_format($p->obras, 0, ',', '.') }}</td>
            </tr>
            @empty
            <tr><td colspan="3" style="padding:1.5rem;text-align:center;color:#9CA3AF">Aún no hay ítems cargados. Sube el Excel BIABLE.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
