@extends('layouts.app')

@section('title', 'Consulta de obras')

@section('content')
{{-- Réplica EN VIVO de las tarjetas de proyecto de la Distribución, en solo lectura. Reutiliza el
     mismo componente de tarjeta (operativo.partials.obra-card) en modo readonly y la misma fuente de
     datos (DistribucionCostosController::index), para que se vea idéntica y se mantenga sincronizada. --}}
@include('operativo.partials.obra-card-styles')
@php
    $readonly = true;
    $puedeEditar = false;
    $depLabel  = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $colCat    = ['EQU-MAT-SUM'=>'#378ADD','MOI'=>'#1D9E75','MOE'=>'#7F77DD','OTROS COSTO'=>'#EF9F27','MOFIJAOPER'=>'#D85A30'];
    $colEstado = ['abierta'=>['#F0FDF4','#15803D'],'parcial'=>['#FEF9C3','#854D0E'],'cerrada'=>['#EFF6FF','#1B3F6E']];
    $colSem    = ['verde'=>'#16A34A','ambar'=>'#D97706','rojo'=>'#DC2626','gris'=>'#9CA3AF'];
    $fmt       = fn($n) => '$'.number_format((float) $n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $mesNombre  = ($nombresMes[$mes] ?? '').' '.$anio;
@endphp

<x-page-banner title="Consulta de obras" icon="🔎" :badge="$depEfectivo ? ($depLabel[$depEfectivo] ?? $depEfectivo) : null">
    Consulta en vivo de las obras y su rentabilidad (resumen del mes, acumulado y proyección). Es una
    vista <b>solo lectura</b>: refleja lo que se distribuye, sin permitir editar.
    @if(is_null($depUsuario) && !$depEfectivo)
        <span style="color:#FDE68A">— elige un departamento en los filtros para empezar.</span>
    @endif
</x-page-banner>

<x-filtros-panel>
    <form method="GET" action="{{ route('operativo.consulta-obras') }}" id="form-filtros"
          style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <button type="submit" class="btn-filtrar" style="order:-1">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filtrar
        </button>
        @if(is_null($depUsuario))
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" id="sel-departamento" class="filtro-select" onchange="document.getElementById('form-filtros').submit()">
                <option value="">— Elegir —</option>
                <option value="mantenimiento" {{ $depEfectivo == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $depEfectivo == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div class="filtro-field" style="flex:1 1 120px">
            <label class="filtro-label">Mes</label>
            <select name="mes" class="filtro-select">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div class="filtro-field" style="flex:1 1 90px">
            <label class="filtro-label">Año</label>
            <select name="anio" class="filtro-select">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div class="filtro-field" style="flex:2 1 240px">
            <label class="filtro-label">Buscar proyecto</label>
            <input type="text" id="buscador" autocomplete="off" placeholder="🔎 Código, proyecto o cliente…"
                onkeydown="if(event.key==='Enter'){event.preventDefault();}" class="filtro-input">
        </div>
        @php
            if ($depEfectivo === 'instalaciones') {
                $opcionesTipo = ['todos'=>'Todos','obras'=>'Obras','garantia'=>'Garantías'];
            } elseif ($depEfectivo === 'mantenimiento') {
                $opcionesTipo = ['todos'=>'Todos','obras'=>'Obras','contrato'=>'Contratos','reparacion'=>'Reparaciones','garantia'=>'Garantías'];
            } else {
                $opcionesTipo = ['todos'=>'Todos','obras'=>'Obras','contrato'=>'Contratos','reparacion'=>'Reparaciones','garantia'=>'Garantías','otro'=>'Otros'];
            }
            $tipoSel = array_key_exists($tipo, $opcionesTipo) ? $tipo : 'todos';
            $masFiltrosActivo = ($tipoSel !== 'todos') || ($estadoFiltro !== 'todos') || (($vista ?? 'todo') !== 'todo');
        @endphp
        <details class="mas-filtros" style="flex:1 1 100%;margin-top:2px" {{ $masFiltrosActivo ? 'open' : '' }}>
            <summary style="cursor:pointer;font-size:12px;color:#1B3F6E;font-weight:600;list-style:none;display:inline-flex;align-items:center;gap:6px">
                <span>⚙️ Más filtros</span>
                @if($masFiltrosActivo)<span style="font-size:10px;font-weight:600;background:#EFF6FF;color:#1B3F6E;border:1px solid #BFDBFE;border-radius:8px;padding:1px 7px">activos</span>@endif
            </summary>
            <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
                <div class="filtro-field" style="flex:2 1 250px">
                    <label class="filtro-label">Qué mostrar</label>
                    <select name="vista" class="filtro-select">
                        @foreach(['todo'=>'Todo el inventario en tránsito pendiente','mes'=>'Solo el movimiento del mes elegido'] as $k => $v)
                            <option value="{{ $k }}" {{ ($vista ?? 'todo') == $k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filtro-field" style="flex:1 1 150px">
                    <label class="filtro-label">Tipo de obra</label>
                    <select name="tipo" id="sel-tipo" class="filtro-select"
                        {{ is_null($depUsuario) && !$depEfectivo ? 'disabled' : '' }}>
                        @foreach($opcionesTipo as $k => $v)
                            <option value="{{ $k }}" {{ $tipoSel == $k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filtro-field" style="flex:1 1 150px">
                    <label class="filtro-label">Estado</label>
                    <select name="estado" class="filtro-select">
                        @foreach(['todos'=>'Todos','abierta'=>'Abiertas','parcial'=>'Parciales','cerrada'=>'Cerradas'] as $k => $v)
                            <option value="{{ $k }}" {{ $estadoFiltro == $k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </details>
    </form>
</x-filtros-panel>

@php
    $obrasConIngreso = array_filter($obras, fn($o) => empty($o['sin_ingreso']));
    $obrasSinIngreso = array_filter($obras, fn($o) => !empty($o['sin_ingreso']));
@endphp

{{-- ══════════ GRUPO: CON INGRESO ══════════ --}}
<div class="grupo-obras" data-grupo="con">
    <div style="display:flex;align-items:center;gap:8px;padding:6px 2px;margin-bottom:4px">
        <span style="font-weight:700;color:#1B3F6E;font-size:14px">Con ingreso</span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#EFF6FF;color:#1B3F6E">{{ count($obrasConIngreso) }}</span>
    </div>
    <div id="grupo-con">
        @foreach($obrasConIngreso as $cod => $o)
            @include('operativo.partials.obra-card', ['readonly' => true])
        @endforeach
        @if(count($obrasConIngreso) === 0)
            <div style="color:#9CA3AF;font-size:12px;padding:10px 4px">No hay proyectos con ingreso en este filtro.</div>
        @endif
    </div>
</div>

{{-- ══════════ GRUPO: SIN INGRESO ══════════ --}}
<div class="grupo-obras" data-grupo="sin" style="margin-top:16px">
    <div style="display:flex;align-items:center;gap:8px;padding:6px 2px;margin-bottom:4px">
        <span style="font-weight:700;color:#854D0E;font-size:14px">Sin ingreso · órdenes abiertas</span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#FEF3C7;color:#854D0E">{{ count($obrasSinIngreso) }}</span>
    </div>
    <div id="grupo-sin">
        @foreach($obrasSinIngreso as $cod => $o)
            @include('operativo.partials.obra-card', ['readonly' => true])
        @endforeach
        @if(count($obrasSinIngreso) === 0)
            <div style="color:#9CA3AF;font-size:12px;padding:10px 4px">No hay proyectos sin ingreso en este filtro.</div>
        @endif
    </div>
</div>

<script>
// Buscador en vivo (mismo comportamiento que en Distribución): filtra las tarjetas por código,
// nombre o cliente sin recargar. No hay edición ni guardado en esta pantalla.
(function () {
    var inp = document.getElementById('buscador');
    if (!inp) return;
    inp.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll('.obra-card').forEach(function (card) {
            var hay = (card.getAttribute('data-buscar') || '');
            card.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>
@endsection
