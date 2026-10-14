@extends('layouts.app')

@section('title', 'Distribución de costos')

@section('content')
<style>
    /* Tarjeta-resumen del proyecto flotante: se queda visible mientras el usuario hace scroll por
       las cuentas/terceros de ese proyecto (la cabecera ya no es fija, así que se pega cerca del
       borde superior del viewport). */
    .obra-sticky {
        position: sticky;
        top: 0;
        z-index: 30;
        background: #fff;
        transition: box-shadow .15s;
        /* Si el resumen (cabecera + mes + acumulado + proyección) supera el alto de pantalla,
           tiene su propio scroll interno en vez de empujar la zona de distribución fuera de vista. */
        max-height: 100vh;
        overflow-y: auto;
    }
    /* Sombra sutil solo cuando el resumen queda pegado al tope (se nota que flota). */
    .obra-sticky.stuck { box-shadow: 0 10px 18px -12px rgba(16,32,54,.35); }
    /* En móvil no debe tapar el contenido: se comporta como una tarjeta normal (no flotante). */
    @media (max-width: 768px) {
        .obra-sticky { position: static; box-shadow: none !important; }
    }

    /* Ícono de ayuda ⓘ con tooltip al pasar el cursor (reemplaza textos fijos de jerga contable). */
    .info-i { display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;
        background:#AEBDCE;color:#fff;font-size:10px;font-weight:800;cursor:help;position:relative;margin-left:4px;font-style:italic;vertical-align:middle }
    .info-i:hover::after { content:attr(data-tip);position:absolute;bottom:150%;left:50%;transform:translateX(-50%);
        background:#1E2A38;color:#fff;font-size:11px;font-weight:500;padding:8px 11px;border-radius:8px;width:230px;z-index:60;
        box-shadow:0 6px 16px rgba(0,0,0,.25);line-height:1.4;text-transform:none;letter-spacing:0;white-space:normal;text-align:left }
    .info-i:hover::before { content:"";position:absolute;bottom:150%;left:50%;transform:translate(-50%,100%);
        border:5px solid transparent;border-top-color:#1E2A38;z-index:60 }

    /* Estado del cierre = control segmentado (Abierta / Parcial / Cerrada), evidente dónde se cambia. */
    .estado-wrap { display:flex;flex-direction:column;align-items:flex-end;gap:3px }
    .estado-wrap .lab { font-size:10px;color:#6B7280;font-weight:700;text-transform:uppercase;letter-spacing:.03em }
    .estado-seg { display:inline-flex;border:1px solid #D7DEE8;border-radius:9px;overflow:hidden;background:#F3F5F9 }
    .estado-seg button { border:none;background:transparent;padding:6px 13px;font-size:12px;font-weight:600;color:#6B7280;cursor:pointer;line-height:1 }
    .estado-seg button + button { border-left:1px solid #D7DEE8 }
    .estado-seg button.on { color:#fff }
    .estado-seg button.on[data-estado="abierta"] { background:#15803D }
    .estado-seg button.on[data-estado="parcial"] { background:#B45309 }
    .estado-seg button.on[data-estado="cerrada"] { background:#1B3F6E }
    .estado-seg button:disabled { opacity:.45;cursor:not-allowed }
    .estado-tip { font-size:10.5px;color:#95A0AF }

    /* "En esta distribución": bloque destacado (verde, borde, número grande) junto a "por llevar". */
    .endist-box { background:#EAF7EE;border:1px solid #BEE3C9;border-radius:10px;padding:7px 14px;text-align:right }
    .endist-box .k { font-size:10px;color:#15803D;font-weight:700;text-transform:uppercase;letter-spacing:.02em }
    .endist-box .v { font-size:19px;font-weight:800;color:#15803D;margin-top:1px;line-height:1.1 }
    .porllevar-box { text-align:right;padding:7px 4px }
    .porllevar-box .k { font-size:10px;color:#6B7280;font-weight:600 }
    .porllevar-box .v { font-size:16px;font-weight:700;color:#1B3F6E;margin-top:1px }

    /* Barra global fija inferior: guardar TODA la distribución del mes (= plano a contabilidad). */
    .dist-actionbar { position:fixed;left:0;right:0;bottom:0;z-index:150;background:#fff;border-top:1px solid #E5E7EB;
        box-shadow:0 -4px 16px rgba(16,32,54,.10);padding:10px 16px }
    .dist-actionbar .inner { max-width:1100px;margin:0 auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap }
    .dist-actionbar .sum { display:flex;align-items:center;gap:16px;flex:1;min-width:200px;font-size:12.5px;color:#6B7280 }
    .dist-actionbar .sum b { color:#1B3F6E }
    .dist-actionbar .sp { flex:1 }

    /* Modal "Mapa de calor de márgenes". */
    .heat-ov { position:fixed;inset:0;background:rgba(20,30,45,.45);display:none;align-items:flex-start;justify-content:center;padding:40px 16px;z-index:210;overflow:auto }
    .heat-ov.open { display:flex }
    .heat-modal { background:#fff;border-radius:14px;max-width:780px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden }
    .heat-modal .mh2 { background:#1B3F6E;color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between }
    .heat-modal .mb { padding:16px 18px }
    .heatgrid { display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:7px;margin-top:6px }
    .hc { border-radius:8px;padding:8px 9px;color:#fff;font-size:11px }
    .hc .hcode { font-weight:700;font-size:11.5px;font-family:ui-monospace,Menlo,Consolas,monospace }
    .hc .hm { font-size:13px;font-weight:800;margin-top:1px }
    .hc.verde{background:#1E9E54} .hc.amarillo{background:#E0A32E;color:#4A3610} .hc.rojo{background:#D6453F} .hc.gris{background:#9CA3AF}
    .heatlabel { font-weight:700;color:#1B3F6E;font-size:12.5px;margin:14px 0 2px }

    /* Tarjeta de bolsa 100% clicqueable: toda la tarjeta abre el detalle por cuenta. */
    .bolsa { cursor:pointer; transition:border-color .15s, box-shadow .15s, transform .15s }
    .bolsa:hover { border-color:#B9D2EB; box-shadow:0 4px 14px rgba(27,76,122,.14); transform:translateY(-1px) }
    .bolsa:active { transform:translateY(0) }
    .bolsa:focus-visible { outline:2px solid #1B4C7A; outline-offset:2px }
    /* "Ver detalle por cuenta": indicador visual (no es un botón) que acompaña el hover de la tarjeta. */
    .bolsa .detbtn { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:10px;
        background:#EAF1F8; border:1px solid #D6E3F1; border-radius:8px; padding:6px 10px;
        font-size:12px; font-weight:600; color:#1B4C7A; transition:background .15s, border-color .15s }
    .bolsa .detbtn .chev { transition:transform .15s }
    .bolsa:hover .detbtn { background:#DCE9F6; border-color:#B9D2EB }
    .bolsa:hover .detbtn .chev { transform:translateX(2px) }

    /* Detalle por cuenta: ocultar lo ya cruzado ($0 exacto) salvo que se active el interruptor. */
    .bolsa-det .bz-cuenta, .bolsa-det .bz-terc, .bolsa-det .bz-row { display:none }
    .bolsa-det.show-zeros .bz-cuenta, .bolsa-det.show-zeros .bz-terc, .bolsa-det.show-zeros .bz-row { display:table-row }
    .bolsa-det.show-zeros .bz-cuenta td, .bolsa-det.show-zeros .bz-terc td, .bolsa-det.show-zeros .bz-row td { opacity:.65 }
    .bolsa-det.show-zeros .hidenote { display:none }
    /* Tabla plana del detalle por tercero. */
    .bd-tbl { border-collapse:collapse; width:100%; min-width:1240px }
    .bd-tbl th, .bd-tbl td { padding:8px 10px; font-size:11.5px; text-align:left; border-bottom:1px solid #EEF1F6; white-space:nowrap }
    .bd-tbl th { font-size:10px; color:#6B7686; text-transform:uppercase; letter-spacing:.02em; background:#F3F6FA }
    .bd-tbl td.n, .bd-tbl th.n { text-align:right }
    .bd-tbl th.bd-act { background:#EAF1F8; color:#1B4C7A }
    .bd-tbl td.bd-act { background:#F7FBFF }
    .bd-tbl tfoot td { font-weight:800; background:#F6FCF8; color:#15803D; border-top:2px solid #CDE9D6 }
    /* Encabezado + fila de filtros fijos (sticky) al hacer scroll por el detalle. */
    .bd-tbl thead th { position:sticky; top:0; z-index:3 }
    .bd-tbl thead tr.bd-filtros th { top:28px; z-index:2; background:#FAFBFD; padding:4px 6px; text-transform:none }
    .bd-tbl thead tr.bd-filtros input { width:100%; min-width:58px; border:1px solid #E5E9F0; border-radius:6px;
        padding:3px 6px; font-size:10.5px; font-weight:400; color:#1E2A38; background:#fff }
    .bd-tbl thead tr.bd-filtros .bd-nofilter { background:#F3F6FA }
    /* Filas resumen "Totales" (siempre) y "Total filtrado" (solo con filtro): fijas (sticky) arriba,
       entre los filtros y la primera línea de datos. El 'top' exacto lo ajusta fixBolsaStickyTops() al
       abrir el detalle; estos valores son el respaldo. */
    .bd-tbl thead tr.bd-sumrow td { position:sticky; top:58px; z-index:2; font-weight:800;
        background:#F6FCF8; color:#15803D; border-top:2px solid #CDE9D6; border-bottom:1px solid #CDE9D6 }
    .bd-tbl thead tr.bd-totfilt td { background:#EAF1F8; color:#1B4C7A }
    /* Filas de un periodo ANTERIOR al mes que se está cerrando: resalte ámbar + barra lateral. */
    .bd-tbl tr.bd-anterior td { background:#FFF7E6 }
    .bd-tbl tr.bd-anterior td:first-child { box-shadow: inset 4px 0 0 #D97706 }
    .bd-ant-tag { display:inline-block; background:#FDE9C8; color:#92631A; border-radius:5px; font-size:9px;
        font-weight:800; padding:1px 6px; margin-left:5px; letter-spacing:.03em; vertical-align:middle }
    .bd-cerrando { font-size:11px; font-weight:700; color:#92631A; background:#FFF7E6; border:1px solid #F3D9A6;
        border-radius:7px; padding:3px 10px }
    .bd-leyenda { font-size:10.5px; color:#92631A; display:inline-flex; align-items:center; gap:6px }
    .bd-leyenda .sw { width:11px; height:11px; border-radius:3px; background:#FFF7E6; border:1px solid #D97706; display:inline-block }
    .bd-limpiar { font-size:11px; padding:5px 10px; border:1px solid #D7DEE8; border-radius:8px; background:#fff; color:#6B7686; cursor:pointer }
    .bd-limpiar:hover { background:#F3F5F9 }
    /* Línea de reversado con "Solicitar reverso a contabilidad" marcado: señalada en verde. */
    .bd-tbl tr.bd-rev-on td { background:#EAF7EE !important }
    .bd-tbl tr.bd-rev-on td:first-child { box-shadow: inset 4px 0 0 #15803D }
    .bd-rev { display:inline-flex; align-items:center; gap:6px; font-size:11px; color:#B91C1C; font-weight:600; cursor:pointer }
    .bd-rev input { width:14px; height:14px; cursor:pointer }
    .bd-rev-obs { margin-left:8px; border:1px solid #E5E9F0; border-radius:7px; padding:4px 7px; font-size:11px; width:190px; background:#fff }
    .bd-rev-dest { display:inline-flex; align-items:center; gap:6px; margin-left:8px }
    .bd-rev-sel { border:1px solid #BEE3C9; border-radius:7px; padding:4px 7px; font-size:11px; background:#fff; max-width:260px }
    .bd-rev-un { border:1px solid #BEE3C9; border-radius:7px; padding:4px 7px; font-size:11px; width:120px; background:#fff }
    .bd-rev-tag { display:none; background:#15803D; color:#fff; border-radius:5px; font-size:9px; font-weight:800; padding:1px 6px; margin-left:6px; letter-spacing:.03em }
    .bd-tbl tr.bd-rev-on .bd-rev-tag { display:inline-block }
    .bd-tbl tr.bd-rev-on .bd-rev { color:#15803D }
    .bd-inp { border:1px solid #BBD3EA; border-radius:8px; padding:5px 8px; width:120px; text-align:right; font-weight:700; color:#1B4C7A; background:#fff; font-size:12px }
    .bd-obs { border:1px solid #E5E9F0; border-radius:8px; padding:5px 8px; width:150px; font-size:12px; background:#fff }
    .bd-qv { font-weight:800; color:#15803D }
    .z0 { font-size:10px; color:#95A0AF; font-weight:700 }
    .badge-cruzada { display:inline-flex;align-items:center;gap:4px;background:#E7F6EC;border:1px solid #BEE3C9;
        color:#15803D;border-radius:20px;padding:2px 9px;font-size:10px;font-weight:700;white-space:nowrap }
    /* Interruptor (toggle) del detalle. */
    .sw-zeros .sw-tr { width:34px;height:20px;border-radius:20px;background:#CBD5E1;position:relative;transition:.18s;flex:none;display:inline-block }
    .sw-zeros .sw-tr::after { content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.18s;box-shadow:0 1px 3px rgba(0,0,0,.2) }
    .sw-zeros input:checked + .sw-tr { background:#1B4C7A }
    .sw-zeros input:checked + .sw-tr::after { transform:translateX(14px) }
    /* Mano de obra del personal: una fila por persona, expandible a su desglose por concepto (PILA). */
    .mo-pers-list { display:flex; flex-direction:column; gap:5px }
    .mo-persona { border:1px solid #D8CBF0; border-radius:7px; background:#fff; overflow:hidden }
    .mo-pers-head { display:flex; align-items:center; gap:8px; padding:6px 9px; font-size:11.5px; cursor:pointer }
    .mo-pers-head:hover { background:#F7F4FD }
    .mo-pers-caret { color:#5B3FA8; font-weight:800; width:10px; display:inline-block; flex:none }
    .mo-pers-det { border-top:1px dashed #E3D9F3; padding:4px 9px 7px 27px; background:#FCFBFE }
    .mo-concepto { display:flex; justify-content:space-between; gap:12px; font-size:11px; color:#4B5563; padding:2px 0 }
    .mo-concepto .n { font-variant-numeric:tabular-nums; color:#374151; font-weight:600 }
</style>
@php
    $depLabel = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $depPrefijo = ['mantenimiento' => 'MT', 'instalaciones' => 'IN'];
@endphp
@php
    // La edición requiere permiso de Operación Y que Contabilidad haya abierto el cierre
    // de este mes. Sin cierre abierto, todo es solo lectura.
    $edicionAbierta = $edicionAbierta ?? false;
    $puedeOperacion = auth()->user()->puedeEditarModulo('operacion');
    $puedeEditar = $puedeOperacion && $edicionAbierta;
    // El "monto a distribuir" de las bolsas se edita en la pantalla de Distribución
    // (Operaciones) cuando el cierre del mes está abierto.
    $puedeEditarBolsa = $puedeEditar;
@endphp
<x-page-banner title="Distribución de costos" icon="📦" :badge="$depEfectivo ? ($depLabel[$depEfectivo] ?? $depEfectivo) : null">
    Reparte el <b>inventario en tránsito</b> de cada obra hacia sus costos: elige la obra, aplica el valor y revisa cómo queda el margen.
    @if(is_null($depUsuario) && !$depEfectivo)
        <span style="color:#FDE68A">— elige un departamento en los filtros para empezar.</span>
    @endif
</x-page-banner>

<x-filtros-panel>
    <form method="GET" action="{{ route('operativo.distribucion') }}" id="form-filtros"
          style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <button type="submit" class="btn-filtrar" style="order:-1">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filtrar
        </button>
        @if(is_null($depUsuario))
        {{-- Director/admin: elige el departamento. El supervisor no ve esto (ya está fijo). --}}
        {{-- Al cambiar el departamento el formulario se envía solo: así el servidor recalcula
             las opciones de "Tipo de obra" que corresponden a ese departamento. --}}
        <div class="filtro-field" style="flex:1 1 150px">
            <label class="filtro-label">Departamento</label>
            <select name="departamento" id="sel-departamento" class="filtro-select" onchange="cambiarDepartamento(this)">
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
        {{-- Buscador de proyecto: filtra el listado en vivo (sin recargar). Sin name → no se envía
             con el form; onkeydown evita que Enter dispare "Filtrar". --}}
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
            // Si el tipo guardado ya no existe para este departamento, mostramos "Todos".
            $tipoSel = array_key_exists($tipo, $opcionesTipo) ? $tipo : 'todos';
            // Filtros secundarios: solo se abren por defecto si vienen activos (≠ valor por defecto).
            $masFiltrosActivo = ($tipoSel !== 'todos') || ($estadoFiltro !== 'todos') || (($vista ?? 'todo') !== 'todo');
        @endphp
        {{-- Filtros secundarios (los que casi no usan): Tipo de obra y Estado, dentro de "Más filtros". --}}
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
                            <option value="{{ $k }}" {{ $vista == $k ? 'selected' : '' }}>{{ $v }}</option>
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

    {{-- Ayuda secundaria colapsable: no ocupa espacio fijo hasta que el usuario la abre. --}}
    <details style="margin-top:12px">
        <summary style="cursor:pointer;font-size:11.5px;color:#64748B;list-style:none;display:inline-flex;align-items:center;gap:6px">
            <span>ℹ️</span> <span style="text-decoration:underline;text-underline-offset:2px">¿Qué significa cada opción de «Qué mostrar»?</span>
        </summary>
        <div style="margin-top:8px;padding:10px 12px;background:#F8FAFC;border:1px solid #EEF2F7;border-radius:9px;font-size:11px;color:#64748B;line-height:1.55">
            <b style="color:#475569">Todo el inventario en tránsito pendiente</b>: todas las obras que aún tienen inventario en tránsito sin repartir; el mes/año solo mueve la fecha de corte de los números.
            <b style="color:#475569">Solo el movimiento del mes elegido</b>: únicamente las obras que tuvieron movimiento de inventario en tránsito en ese mes y año.
        </div>
    </details>

    {{-- Referencia: qué significa el resaltado amarillo de las celdas (antes estaba en la barra de acciones). --}}
    <div style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#92400E">
        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#FEF3C7;border:1px solid #F59E0B"></span>
        Celda modificada por Operaciones
    </div>
</x-filtros-panel>

@if(!$edicionAbierta)
<div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:9px 14px;font-size:12.5px;color:#854D0E;margin-bottom:1rem">
    🔒 <b>Mes en curso — solo lectura.</b> La edición se habilita cuando Contabilidad abra el cierre de este mes.
</div>
@elseif($puedeOperacion)
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:9px 14px;font-size:12.5px;color:#15803D;margin-bottom:1rem">
    🔓 <b>Cierre abierto — puedes editar</b> la distribución de este mes.
</div>
@else
<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:9px 14px;font-size:12.5px;color:#6B7280;margin-bottom:1rem">
    👁 Modo solo lectura. Puedes consultar la distribución pero no guardar cambios.
</div>
@endif

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

@if($distId)
<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:8px 14px;font-size:12px;color:#1B3F6E;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>Editando borrador existente ({{ $depPrefijo[$envio->departamento] ?? '' }}-v{{ $envio->version ?? '?' }}). Al guardar se actualiza esta misma versión.</span>
    <a href="{{ route('operativo.distribucion') }}" style="color:#1B3F6E;font-weight:500;text-decoration:none">+ Empezar un borrador nuevo</a>
</div>
@endif

@if($bloqueado)
<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 14px;font-size:13px;color:#1B3F6E;margin-bottom:1rem">
    🔒 Período enviado a contabilidad{{ $envio && $envio->enviado_at ? ' el '.$envio->enviado_at->format('d/m/Y H:i') : '' }}. Solo lectura — contabilidad debe habilitar la edición para modificarlo.
</div>
@endif

@if(!empty($inactivasConSaldo))
<div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:10px 14px;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="font-size:13px;color:#92400E">
        ⚠️ Hay <b>{{ count($inactivasConSaldo) }}</b> obra(s) marcada(s) como <b>inactivas</b> que todavía tienen <b>saldo en cuenta 14</b>: no se cerraron. Revísalas antes de cerrarlas.
    </div>
    <a href="{{ route('operativo.obras-inactivas.index', ['mes' => $mes, 'anio' => $anio, 'departamento' => $depEfectivo]) }}"
        style="font-size:12px;font-weight:600;padding:7px 14px;background:#B45309;color:#fff;border-radius:8px;text-decoration:none;white-space:nowrap">Ver listado</a>
</div>
@endif

@if($kpiObras > 0 || !empty($bolsas))
{{-- CABECERA: contexto (bolsas de área + KPIs) + toolbar + semáforo. Se muestra aunque no haya
     obras, para que el panel de bolsas siempre esté visible. --}}
<div id="barra-superior">
    {{-- CONTEXTO compacto: bolsas de área (origen del costo) + KPIs, en una sola fila. Paleta azul
         SECAR sobre fondo blanco; el color fuerte solo aparece en "Bajo margen" cuando hay alerta. --}}
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;margin-bottom:12px">
        @foreach(($bolsas ?? []) as $b)
        <div id="bolsa-box-{{ $b['codigo'] }}" class="bolsa"
             role="button" tabindex="0"
             aria-label="Ver detalle por cuenta de {{ $b['nombre'] }}"
             onclick="toggleBolsaDetalle('{{ $b['codigo'] }}')"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleBolsaDetalle('{{ $b['codigo'] }}');}"
             style="flex:1 1 300px;min-width:260px;background:#fff;border:1px solid #E5E9F0;border-radius:12px;box-shadow:0 1px 2px rgba(16,32,54,.06);padding:13px 15px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-weight:700;font-size:13.5px;color:#1B4C7A;display:inline-flex;align-items:center;gap:7px"><span style="width:9px;height:9px;border-radius:50%;background:#1B4C7A"></span>{{ $b['nombre'] }}</span>
            </div>

            {{-- Dos valores separados: OTROS COSTOS (neto) y MANO DE OBRA. El reversado de más queda
                 como línea secundaria pequeña dentro de Otros costos. --}}
            <div style="display:flex;gap:10px;align-items:stretch">
                {{-- Otros costos: saldo NETO = disponible − reversado de más (no el disponible bruto). --}}
                <div style="flex:1;min-width:0">
                    <div style="font-size:10px;font-weight:800;color:#1B4C7A;letter-spacing:.03em;text-transform:uppercase">Otros costos</div>
                    <div style="font-size:10px;color:#6B7686;margin-top:1px">Saldo neto por distribuir</div>
                    <b id="bolsa-otros-{{ $b['codigo'] }}" style="font-size:20px;font-weight:800;color:#1B4C7A;display:block;margin-top:1px" title="Otros costos neto = disponible por distribuir − reversado de más (materiales, servicios…).">${{ number_format($b['otros_neto'] ?? (($b['otros_costos'] ?? $b['total']) - ($b['total_reversado'] ?? 0)), 0, ',', '.') }}</b>
                    <div style="font-size:9.5px;color:#95A0AF;margin-top:3px;line-height:1.5">
                        Disponible ${{ number_format($b['otros_costos'] ?? $b['total'], 0, ',', '.') }}
                        @if(($b['total_reversado'] ?? 0) > 0.5)
                        · <span style="color:#A52020">Reversado de más ${{ number_format($b['total_reversado'], 0, ',', '.') }}</span>
                        @endif
                    </div>
                </div>
                {{-- Mano de obra del personal (pool distinto): se reparte por persona a los proyectos.
                     Al pulsarla se abre, dentro de la tarjeta, el personal con su valor "por aplicar". --}}
                <div role="button" tabindex="0"
                     onclick="event.stopPropagation(); toggleMoPanel('{{ $b['codigo'] }}')"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();event.stopPropagation();toggleMoPanel('{{ $b['codigo'] }}');}"
                     style="flex:1;min-width:0;background:#F1EDFB;border:1px solid #D8CBF0;border-radius:9px;padding:7px 9px;cursor:pointer"
                     title="Ver el personal de mano de obra y su valor por aplicar a cada proyecto.">
                    <div style="font-size:10px;font-weight:800;color:#5B3FA8;letter-spacing:.03em;text-transform:uppercase">Mano de obra</div>
                    <div style="font-size:10px;color:#6B7686;margin-top:1px">A distribuir por persona</div>
                    <b style="font-size:20px;font-weight:800;color:#5B3FA8;display:block;margin-top:1px">${{ number_format($b['mano_obra'] ?? 0, 0, ',', '.') }}</b>
                    <div style="font-size:9.5px;color:#5B3FA8;margin-top:3px;font-weight:600"><span class="caret-mo-{{ $b['codigo'] }}">▸</span> Ver personal</div>
                </div>
            </div>

            {{-- Total bolsa = Otros costos (neto) + Mano de obra = saldo neto de la cuenta 14 (cuadra con contabilidad). --}}
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:9px;padding:7px 10px;background:#F3F8F4;border:1px solid #CDE9D6;border-radius:9px">
                <span style="font-size:10.5px;font-weight:800;color:#15803D;text-transform:uppercase;letter-spacing:.03em">Total bolsa</span>
                <b style="font-size:17px;font-weight:800;color:#15803D" title="Otros costos (neto) + Mano de obra = saldo neto de la cuenta 14; cuadra con contabilidad">${{ number_format($b['neto'] ?? $b['total'], 0, ',', '.') }}</b>
            </div>

            {{-- Indicador visual (NO es un botón): acompaña el hover/clic de toda la tarjeta. --}}
            <div class="detbtn" style="margin-top:9px">
                <span><span class="caret-bolsa-{{ $b['codigo'] }}">▸</span> Ver detalle por cuenta</span>
                <span class="chev" aria-hidden="true">›</span>
            </div>
        </div>
        @endforeach
        @if($kpiObras > 0)
        {{-- KPIs: 3 indicadores pequeños, apilados a la derecha. --}}
        <div style="flex:1 1 220px;min-width:200px;display:flex;flex-direction:column;gap:9px">
            <div style="background:#fff;border:1px solid #E5E9F0;border-radius:11px;box-shadow:0 1px 2px rgba(16,32,54,.06);padding:9px 13px;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:11.5px;color:#6B7686;font-weight:600">Pendiente por distribuir</span>
                <b style="font-size:15px;font-weight:800;color:#1B4C7A">${{ number_format($kpiPendiente, 0, ',', '.') }}</b>
            </div>
            <div style="background:#fff;border:1px solid #E5E9F0;border-radius:11px;box-shadow:0 1px 2px rgba(16,32,54,.06);padding:9px 13px;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:11.5px;color:#6B7686;font-weight:600">Obras</span>
                <b style="font-size:15px;font-weight:800;color:#1E2A38">{{ $kpiObras }}</b>
            </div>
            <div style="background:#fff;border:1px solid #E5E9F0;border-radius:11px;box-shadow:0 1px 2px rgba(16,32,54,.06);padding:9px 13px;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:11.5px;color:#6B7686;font-weight:600">Bajo margen</span>
                <b style="font-size:15px;font-weight:800;color:{{ $kpiAlertas > 0 ? '#DC2626' : '#1E2A38' }}">{{ $kpiAlertas }}</b>
            </div>
        </div>
        @endif
    </div>

    {{-- ══════════ MANO DE OBRA DIRECTA — panel colapsable DENTRO de la zona de la bolsa ══════════ --}}
    {{-- Se abre al pulsar "Mano de obra" en la tarjeta. Muestra el personal con su "por aplicar" y las
         acciones del plano por departamento. La asignación se hace dentro de cada obra destino (abajo). --}}
    @php $moFmtG = fn ($n) => '$'.number_format((float) $n, 0, ',', '.'); @endphp
    @foreach(($moPersonasDep ?? []) as $moDep => $moPers)
        @if(!empty($moPers))
        <div class="mo-global card mo-panel" id="mo-panel-{{ $moDep }}" data-dep="{{ $moDep }}" style="display:none;padding:12px 14px;margin:-2px 0 1rem;border:1px solid #D8CBF0;background:#F7F4FD">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
                <div style="font-size:12px;font-weight:700;color:#5B3FA8">🧑‍🔧 Mano de obra — {{ ucfirst($moDep) }} · por aplicar a cada proyecto</div>
                @if($puedeEditar)
                <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    @if($moHayMesAnterior)
                    <form method="POST" action="{{ route('operativo.mano-obra.precargar') }}" style="margin:0"
                          onsubmit="return confirm('¿Precargar la distribución de {{ $mesAnteriorNombre }}? Reparte el costo actual de cada persona en las mismas obras/proporciones del mes anterior. Reemplaza lo cargado este período.');">
                        @csrf<input type="hidden" name="departamento" value="{{ $moDep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
                        <button type="submit" style="padding:5px 10px;background:#EFF6FF;border:1px solid #1B3F6E;border-radius:6px;font-size:11px;color:#1B3F6E;cursor:pointer">⤵ Precargar mes anterior</button>
                    </form>
                    @endif
                    {{-- Vista SOLO INFORMATIVA: el plano, aplicar y el resumen se retiraron. La mano de
                         obra se distribuye desde la tarjeta de cada proyecto, no desde aquí. --}}
                </div>
                @endif
            </div>
            @php $moTotalDep = array_sum(array_map(fn ($x) => (float) ($x['total'] ?? 0), $moPers)); @endphp
            <div style="font-size:11px;color:#5B3FA8;font-weight:700;margin-bottom:7px">
                Total mano de obra del personal: <b style="font-size:13px">{{ $moFmtG($moTotalDep) }}</b>
                <span style="color:#9CA3AF;font-weight:500">· {{ count($moPers) }} persona(s) · clic en cada una para ver el desglose por concepto</span>
            </div>
            <div class="mo-pers-list">
                @foreach($moPers as $ced => $p)
                <div class="mo-persona" data-dep="{{ $moDep }}" data-ced="{{ $ced }}" data-nom="{{ $p['nom'] }}" data-doc="{{ $p['doc'] }}" data-total="{{ $p['total'] }}">
                    <div class="mo-pers-head" role="button" tabindex="0" onclick="toggleMoPersona(this)"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleMoPersona(this);}">
                        <span class="mo-pers-caret">▸</span>
                        <span style="color:#1B3F6E;font-weight:600">{{ $p['nom'] ?: $ced }}</span>
                        <span style="color:#9CA3AF;font-family:monospace;font-size:10.5px">{{ $p['doc'] ?: $ced }}</span>
                        <span style="margin-left:auto;color:#374151">Total MO <b style="color:#5B3FA8">{{ $moFmtG($p['total']) }}</b></span>
                        <span style="color:#6B7686;font-size:10.5px">· queda <b data-mo-rest style="color:#15803D">{{ $moFmtG($p['total']) }}</b></span>
                    </div>
                    <div class="mo-pers-det" style="display:none">
                        @forelse(($p['conceptos'] ?? []) as $c)
                        <div class="mo-concepto"><span>{{ $c['concepto'] }}</span><span class="n">{{ $moFmtG($c['monto']) }}</span></div>
                        @empty
                        <div class="mo-concepto" style="color:#9CA3AF">Sin desglose por concepto disponible.</div>
                        @endforelse
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    @endforeach

    @if(!empty($bolsas))
    <div style="margin-bottom:4px">
        {{-- Detalle por TERCERO de cada bolsa: tabla plana donde operaciones distribuye directo en
             cada línea. El Saldo ya trae el cruce FIFO general atribuido (todos los negativos de la
             cuenta consumen los positivos más antiguos); lo que quedó en $0 se oculta salvo que se
             active el interruptor. Si la cuenta neta negativo, el sobrante se muestra como alerta de
             solo lectura. El plano al ERP sigue agregado por cuenta. --}}
        @foreach($bolsas as $b)
        @php
            $det = $detalleBolsas[$b['codigo']] ?? [];
            $conSaldo = 0; $ocultas = 0; $totSaldo = 0.0; $totDist = 0.0; $alertas = 0; $totAlerta = 0.0;
            foreach ($det as $dl) {
                if (! empty($dl['alerta'])) { $alertas++; $totAlerta += $dl['saldo']; }
                elseif ($dl['cero']) { $ocultas++; }
                else { $conSaldo++; $totSaldo += $dl['saldo']; $totDist += $dl['monto_distribuir']; }
            }
            // Periodo que se está cerrando (AAAAMM) y su nombre, para resaltar las filas de meses anteriores.
            $nombresMesC = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
            $cierreYM = $anio * 100 + $mes;
            $mesCierreNombre = ($nombresMesC[$mes] ?? '').' '.$anio;
            $netoBolsa = round($totSaldo + $totAlerta, 2); // positivos + (negativos) = neto, cuadra con contabilidad
            $revBolsa  = round(-1 * $totAlerta, 2);         // reversado de más (en positivo)
            // Cuentas 1420 CON saldo por U.N. (opciones para reclasificar un reverso dentro de la misma U.N.).
            $cuentasSaldoUn = [];
            foreach ($det as $dl) {
                if (! empty($dl['alerta']) || ! empty($dl['cero']) || (float) $dl['saldo'] <= 0) continue;
                $u = $dl['un_codigo']; $cc = $dl['cuenta_14'];
                if (! isset($cuentasSaldoUn[$u][$cc])) $cuentasSaldoUn[$u][$cc] = ['cuenta' => $cc, 'nombre' => $dl['nombre'], 'saldo' => 0.0];
                $cuentasSaldoUn[$u][$cc]['saldo'] += (float) $dl['saldo'];
            }
        @endphp
        <div id="bolsa-det-{{ $b['codigo'] }}" class="bolsa-det" style="display:none;margin-top:8px;border:1px solid #E5E9F0;border-radius:10px;overflow:hidden;background:#fff">
            {{-- Toolbar: interruptor $0 · líneas con saldo · Exportar Excel --}}
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:9px 12px;border-bottom:1px solid #EEF1F6;background:#FAFBFD">
                <label class="sw-zeros" style="display:inline-flex;align-items:center;gap:8px;font-size:12px;color:#1E2A38;cursor:pointer;user-select:none">
                    <input type="checkbox" onchange="toggleBolsaZeros('{{ $b['codigo'] }}', this.checked)">
                    <span class="sw-tr"></span> Mostrar lo ya cruzado ($0)
                </label>
                <span class="bd-cerrando" title="Las filas de meses anteriores se resaltan: deben cerrarse primero">Cerrando: {{ $mesCierreNombre }}</span>
                <span class="bd-leyenda"><span class="sw"></span> Ámbar = periodo anterior (cerrar primero)</span>
                <span style="flex:1"></span>
                <button type="button" class="bd-limpiar" onclick="limpiarFiltrosBolsa('{{ $b['codigo'] }}')">Limpiar filtros</button>
                <span style="font-size:11.5px;color:#6B7686">Líneas con saldo: <b style="color:#1B4C7A">{{ $conSaldo }}</b></span>
                <a href="{{ route('operativo.distribucion.detalle-bolsa.excel', ['mes'=>$mes,'anio'=>$anio,'vista'=>$vista,'departamento'=>$b['codigo']]) }}"
                   style="font-size:12px;padding:6px 11px;border:1px solid #CDE9D6;border-radius:8px;background:#F0FDF4;color:#15803D;text-decoration:none;font-weight:700">↓ Exportar Excel</a>
            </div>
            @if($ocultas > 0)
            <div class="hidenote" style="background:#EAF1F8;border-bottom:1px solid #D4E3F2;color:#1B4C7A;padding:8px 12px;font-size:12px">
                ✓ Se ocultaron <b>{{ $ocultas }}</b> línea(s) que ya quedaron en $0 (cruzadas por antigüedad). Actívalas con el interruptor si las necesitas.
            </div>
            @endif
            @if($alertas > 0)
            <div style="background:#FEF2F2;border-bottom:1px solid #FECACA;color:#B91C1C;padding:8px 12px;font-size:12px">
                ⚠ <b>{{ $alertas }}</b> cuenta(s) con <b>reversado de más</b> (neto negativo): revisar con contabilidad. Se muestran como alerta y no se distribuyen.
            </div>
            @endif
            <form method="POST" action="{{ route('operativo.distribucion.bolsa-montos') }}">
                @csrf
                <input type="hidden" name="mes" value="{{ $mes }}">
                <input type="hidden" name="anio" value="{{ $anio }}">
                <input type="hidden" name="departamento" value="{{ $b['codigo'] }}">
                <div style="overflow-x:auto">
                    <table class="bd-tbl">
                        <thead>
                        <tr>
                            <th>U.N.</th><th>Fecha</th><th>Desc. U.N.</th><th>Nombre del proyecto</th>
                            <th>Auxiliar</th><th>Desc. auxiliar</th><th>Nit movto.</th><th>Razón social tercero</th><th>Docto.</th>
                            <th class="n">Saldo</th><th class="n bd-act">A distribuir</th><th class="n bd-act">Queda mes sig.</th><th class="bd-act">Observaciones</th>
                        </tr>
                        {{-- Fila de filtros por columna: filtra las filas en vivo (contiene, sin acentos/mayúsc.). --}}
                        <tr class="bd-filtros" data-bolsa="{{ $b['codigo'] }}">
                            @foreach(['U.N.','Fecha','Desc. U.N.','Proyecto','Auxiliar','Desc. auxiliar','Nit','Razón social','Docto.','Saldo'] as $ci => $ph)
                                <th><input type="text" data-col="{{ $ci }}" placeholder="{{ $ph }}" oninput="filtrarBolsaDet('{{ $b['codigo'] }}')" aria-label="Filtrar {{ $ph }}"></th>
                            @endforeach
                            <th class="bd-nofilter"></th><th class="bd-nofilter"></th><th class="bd-nofilter"></th>
                        </tr>
                        {{-- Resumen FIJO (sticky) arriba, entre los filtros y la primera línea de datos.
                             "Totales" siempre; "Total filtrado" solo cuando hay un filtro activo. --}}
                        <tr class="bd-sumrow bd-totales">
                            <td colspan="9" style="text-align:right">Totales</td>
                            <td class="n" id="ftSaldo-{{ $b['codigo'] }}">${{ number_format($totSaldo, 0, ',', '.') }}</td>
                            <td class="n" id="ftDist-{{ $b['codigo'] }}">${{ number_format($totDist, 0, ',', '.') }}</td>
                            <td class="n" id="ftQueda-{{ $b['codigo'] }}">${{ number_format(max(0, $totSaldo - $totDist), 0, ',', '.') }}</td>
                            <td></td>
                        </tr>
                        <tr class="bd-sumrow bd-totfilt" id="ftFiltRow-{{ $b['codigo'] }}" style="display:none">
                            <td colspan="9" id="ftFiltLbl-{{ $b['codigo'] }}" style="text-align:right">Total filtrado</td>
                            <td class="n" id="ftFilt-{{ $b['codigo'] }}">$0</td>
                            <td colspan="3"></td>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($det as $l)
                        @php
                            $k = $l['un_codigo'].'|'.$l['cuenta_14'].'|'.$l['tercero_dcto'].'|'.$l['documento'];
                            $queda = max(0, $l['saldo'] - $l['monto_distribuir']);
                            // Periodo de la fila (AAAAMM): de fecha_docto si existe, si no del 'ord' (periodo*100).
                            $rowYM = intdiv((int) ($l['ord'] ?? 0), 100);
                            // Fila de un mes anterior al que se está cerrando (debe cerrarse primero).
                            $anterior = empty($l['alerta']) && $rowYM > 0 && $rowYM !== $cierreYM;
                            $clases = !empty($l['alerta']) ? 'bz-alerta' : ($l['cero'] ? 'bz-row' : '');
                            if ($anterior) $clases = trim($clases.' bd-anterior');
                        @endphp
                        @php if (!empty($l['alerta']) && !empty($l['reverso_solicitado'])) $clases = trim($clases.' bd-rev-on'); @endphp
                        <tr class="{{ $clases }}" data-saldo="{{ round($l['saldo']) }}" @if(!empty($l['alerta'])) style="background:#FEF2F2" @endif>
                            <td style="font-family:monospace">{{ $l['un_codigo'] }}</td>
                            <td>{{ $l['fecha'] ?? '—' }}@if($anterior) <span class="bd-ant-tag">ANTERIOR</span>@endif</td>
                            <td style="color:#6B7686">{{ Str::limit($l['un_nombre'], 22) }}</td>
                            <td style="color:#6B7686">{{ Str::limit($l['nombre_proyecto'], 26) }}</td>
                            <td style="font-family:monospace">{{ $l['cuenta_14'] }}</td>
                            <td style="color:#6B7686">{{ Str::limit($l['nombre'], 24) }}</td>
                            <td style="font-family:monospace;color:#6B7686">{{ $l['tercero_dcto'] }}</td>
                            <td>{{ Str::limit($l['razon_social'], 28) }}@if(!empty($l['alerta'])) <span class="z0" style="background:#FEE2E2;color:#B91C1C">reversado de más</span><span class="bd-rev-tag">REVERSO SOLICITADO</span>@elseif($l['cero']) <span class="z0">cruzado</span>@endif</td>
                            <td style="font-family:monospace;color:#6B7686">{{ $l['documento'] }}</td>
                            <td class="n" style="font-weight:700;color:{{ !empty($l['alerta']) ? '#B91C1C' : '#854D0E' }}">${{ number_format($l['saldo'], 0, ',', '.') }}</td>
                            @if(!empty($l['alerta']))
                            {{-- Reversado de más: se puede solicitar a contabilidad que lo reverse ESTE mes (no va a
                                 "queda mes sig."). La U.N. destino de la contrapartida (14→14) se escribe en el campo. --}}
                            <td class="bd-act" colspan="3" style="font-size:11px">
                                @php
                                    $opcionesRev = array_values($cuentasSaldoUn[$l['un_codigo']] ?? []);
                                    $rd = (string) ($l['reverso_destino'] ?? '');
                                    $rdEsUn = str_starts_with($rd, 'un:');
                                    $rdUnVal = $rdEsUn ? substr($rd, 3) : '';
                                @endphp
                                @if($puedeEditarBolsa)
                                <label class="bd-rev"><input type="checkbox" name="reverso[{{ $k }}]" value="1" {{ !empty($l['reverso_solicitado']) ? 'checked' : '' }} onchange="toggleReverso(this)"> Solicitar reverso a contabilidad</label>
                                <span class="bd-rev-dest" style="{{ !empty($l['reverso_solicitado']) ? '' : 'display:none' }}">
                                    <select class="bd-rev-sel" name="reverso_destino[{{ $k }}]" onchange="onReversoDestino(this)" title="A qué cuenta 1420 con saldo (misma U.N.) reclasificar el reverso">
                                        <option value="">— Reclasificar a… —</option>
                                        @foreach($opcionesRev as $op)
                                            <option value="cuenta:{{ $op['cuenta'] }}" {{ $rd === 'cuenta:'.$op['cuenta'] ? 'selected' : '' }}>{{ $op['cuenta'] }} · {{ Str::limit($op['nombre'], 22) }} · ${{ number_format($op['saldo'], 0, ',', '.') }}</option>
                                        @endforeach
                                        <option value="otra" {{ $rdEsUn ? 'selected' : '' }}>Otra U.N.…</option>
                                    </select>
                                    <input type="text" class="bd-rev-un" name="reverso_un[{{ $k }}]" value="{{ $rdUnVal }}" placeholder="U.N. destino" title="Código de la otra U.N. destino (14→14)" style="{{ $rdEsUn ? '' : 'display:none' }}">
                                </span>
                                @else
                                    @if(!empty($l['reverso_solicitado']))
                                        <span style="color:#15803D;font-weight:700">↩ Reverso solicitado</span>
                                        <span style="color:#6B7686">→ {{ $rdEsUn ? 'U.N. '.$rdUnVal : (str_starts_with($rd, 'cuenta:') ? 'cuenta '.substr($rd, 7).' (misma U.N.)' : 'destino sin indicar') }}</span>
                                    @else
                                        <span style="color:#B91C1C">⚠ Reversado de más — revisar con contabilidad (no se distribuye).</span>
                                    @endif
                                @endif
                            </td>
                            @elseif($l['cero'])
                            <td class="n bd-act">—</td><td class="n bd-act">—</td><td class="bd-act">—</td>
                            @elseif($puedeEditarBolsa)
                            <td class="n bd-act"><input class="bd-inp" type="text" inputmode="numeric" name="monto[{{ $k }}]" value="{{ number_format($l['monto_distribuir'], 0, ',', '.') }}" data-tipo="bolsamonto" data-bolsa="{{ $b['codigo'] }}" oninput="onBolsaDetInput(this)"></td>
                            <td class="n bd-act"><span class="bd-qv">${{ number_format($queda, 0, ',', '.') }}</span></td>
                            <td class="bd-act"><input class="bd-obs" type="text" name="obs[{{ $k }}]" value="{{ $l['observaciones'] }}" placeholder="Observación…"></td>
                            @else
                            <td class="n bd-act"><b style="color:#1B4C7A">${{ number_format($l['monto_distribuir'], 0, ',', '.') }}</b></td>
                            <td class="n bd-act"><span class="bd-qv">${{ number_format($queda, 0, ',', '.') }}</span></td>
                            <td class="bd-act" style="color:#6B7686">{{ $l['observaciones'] }}</td>
                            @endif
                        </tr>
                        @empty
                        <tr><td colspan="13" style="padding:14px;text-align:center;color:#95A0AF">Esta bolsa no tiene saldo por distribuir.</td></tr>
                        @endforelse
                        </tbody>
                        <tfoot>
                        {{-- Conciliación con contabilidad (siempre visible): Disponible − Reversado de más =
                             Saldo de la bolsa (neto). Los "Totales" y el "Total filtrado" ahora van arriba. --}}
                        <tr id="ftRowDisp-{{ $b['codigo'] }}">
                            <td colspan="9" style="text-align:right;font-weight:600;color:#6B7686">Disponible por distribuir (suma de líneas con saldo)</td>
                            <td class="n" style="color:#1B4C7A">${{ number_format($totSaldo, 0, ',', '.') }}</td>
                            <td colspan="3"></td>
                        </tr>
                        <tr id="ftRowRev-{{ $b['codigo'] }}">
                            <td colspan="9" style="text-align:right;font-weight:600;color:#B91C1C">(−) Reversado de más / por revisar</td>
                            <td class="n" style="color:#B91C1C">${{ number_format($revBolsa, 0, ',', '.') }}</td>
                            <td colspan="3"></td>
                        </tr>
                        <tr>
                            <td colspan="9" id="ftNetoLbl-{{ $b['codigo'] }}" style="text-align:right;font-weight:800;color:#15803D;background:#EAF7EE">= Saldo de la bolsa (neto) · cuadra con contabilidad</td>
                            <td class="n" id="ftNeto-{{ $b['codigo'] }}" data-neto="{{ round($netoBolsa) }}" style="color:#15803D;background:#EAF7EE">${{ number_format($netoBolsa, 0, ',', '.') }}</td>
                            <td colspan="3" style="background:#EAF7EE"></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                @unless($puedeEditarBolsa)
                <div style="padding:7px 12px;font-size:11px;color:#92400E;background:#FEF9C3;border-top:1px solid #FDE68A">
                    🔒 Para editar el "a distribuir" y las observaciones, Contabilidad debe <b>abrir el cierre</b> de este mes.
                </div>
                @else
                <div style="display:flex;justify-content:flex-end;padding:9px 12px;border-top:1px solid #EEF1F6">
                    <button type="submit" style="padding:7px 16px;background:#1B4C7A;color:white;border:none;border-radius:8px;font-size:12px;cursor:pointer">Guardar montos</button>
                </div>
                @endunless
            </form>
        </div>
        @endforeach
    </div>
    @endif
    @if($kpiObras > 0)
    {{-- Toolbar discreto: Expandir/Colapsar + Mapa de calor + descarga del inventario (saldos 1420). --}}
    @php
        $invLabel = match ($depEfectivo) {
            'instalaciones' => 'Inventario Instalaciones',
            'mantenimiento' => 'Inventario Mantenimiento',
            default         => 'Inventario de proyectos en transito',
        };
    @endphp
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button type="button" id="btn-expandir" style="font-size:12px;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#374151;cursor:pointer">Expandir todo</button>
        <button type="button" id="btn-colapsar" style="font-size:12px;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#374151;cursor:pointer">Colapsar todo</button>
        <span style="flex:1"></span>
        <button type="button" onclick="document.getElementById('heat-modal').classList.add('open')" style="font-size:12px;padding:6px 12px;border:1px solid #1B3F6E;border-radius:8px;background:#1B3F6E;color:white;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
            <span style="display:inline-flex;gap:2px"><span style="width:10px;height:10px;border-radius:2px;background:#1E9E54;display:inline-block"></span><span style="width:10px;height:10px;border-radius:2px;background:#E0A32E;display:inline-block"></span><span style="width:10px;height:10px;border-radius:2px;background:#D6453F;display:inline-block"></span></span>
            Mapa de calor
        </button>
        <a href="{{ route('operativo.distribucion.reporte-saldos', ['mes' => $mes, 'anio' => $anio, 'departamento' => $depEfectivo]) }}"
           style="font-size:12px;padding:6px 12px;border:1px solid #15803D;border-radius:8px;background:#F0FDF4;color:#15803D;cursor:pointer;text-decoration:none;font-weight:500;white-space:nowrap">⬇ {{ $invLabel }}</a>
    </div>

    {{-- Semáforo de margen: referencia, colapsable tras un ícono de ayuda (no ocupa espacio fijo) --}}
    @php
        $depsLeyenda = $depEfectivo ? [$depEfectivo] : array_keys(\App\Services\DistribucionService::UMBRALES_MARGEN);
        $CSEM = \App\Services\DistribucionService::COLORES_SEMAFORO;
    @endphp
    <details style="margin-top:10px;border-top:1px solid #F3F4F6;padding-top:8px">
        <summary style="cursor:pointer;font-size:11px;color:#6B7280;list-style:none;display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;border:1px solid #9CA3AF;font-size:9px;font-weight:700">ⓘ</span>
            <span style="text-decoration:underline;text-underline-offset:2px">Semáforo de margen</span>
        </summary>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-top:8px">
            @foreach($depsLeyenda as $dl)
                @php $u = \App\Services\DistribucionService::UMBRALES_MARGEN[$dl]; @endphp
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#374151">
                    <b style="color:#4B5563">{{ \App\Services\DistribucionService::DEPARTAMENTOS[$dl] ?? $dl }}</b>
                    <span style="padding:1px 7px;border-radius:8px;font-weight:700;background:{{ $CSEM['verde'][0] }};color:{{ $CSEM['verde'][1] }}">≥ {{ $u['verde'] }}%</span>
                    <span style="padding:1px 7px;border-radius:8px;font-weight:700;background:{{ $CSEM['amarillo'][0] }};color:{{ $CSEM['amarillo'][1] }}">≥ {{ $u['amarillo'] }}%</span>
                    <span style="padding:1px 7px;border-radius:8px;font-weight:700;background:{{ $CSEM['rojo'][0] }};color:{{ $CSEM['rojo'][1] }}">≥ {{ $u['rojo'] }}%</span>
                    <span style="padding:1px 7px;border-radius:8px;font-weight:700;background:{{ $CSEM['gris'][0] }};color:{{ $CSEM['gris'][1] }}">&lt; {{ $u['rojo'] }}%</span>
                </span>
            @endforeach
        </div>
    </details>
    @if(!$bloqueado && $puedeEditar)
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;border-top:1px solid #F3F4F6;padding-top:10px">
        <span style="font-size:12px;color:#6B7280">Acciones rápidas <span style="color:#9CA3AF">(solo obras con ingreso)</span>:</span>
        <button type="button" onclick="aplicarTodo()" style="font-size:12px;padding:6px 14px;border:1px solid #16A34A;border-radius:8px;background:white;color:#15803D;cursor:pointer">Aplicar todo el pendiente</button>
        <button type="button" onclick="ponerEnCero()" style="font-size:12px;padding:6px 14px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;cursor:pointer">Poner todo en 0</button>
        <button type="button" onclick="abrirCalculo()" style="font-size:12px;padding:6px 14px;border:1px solid #4338CA;border-radius:8px;background:#EEF2FF;color:#4338CA;font-weight:500;cursor:pointer">✨ Calcular costo sugerido</button>
    </div>
    @endif
    @endif
</div>
@endif

@php
    $colCat = ['EQU-MAT-SUM'=>'#378ADD','MOI'=>'#1D9E75','MOE'=>'#7F77DD','OTROS COSTO'=>'#EF9F27','MOFIJAOPER'=>'#D85A30'];
    $colEstado = ['abierta'=>['#F0FDF4','#15803D'],'parcial'=>['#FEF9C3','#854D0E'],'cerrada'=>['#EFF6FF','#1B3F6E']];
    $colSem = ['verde'=>'#16A34A','ambar'=>'#D97706','rojo'=>'#DC2626','gris'=>'#9CA3AF'];
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $mesNombre = ($nombresMes[$mes] ?? '').' '.$anio;
    $mesAnt = $mes - 1; $anioAnt = $anio;
    if ($mesAnt < 1) { $mesAnt = 12; $anioAnt = $anio - 1; }
    $mesAnteriorNombre = ($nombresMes[$mesAnt] ?? '').' '.$anioAnt;
@endphp

@if($kpiObras == 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay obras con inventario en tránsito pendiente para este período / filtro.</div>
@endif

<form method="POST" action="{{ route('operativo.distribucion.guardar') }}" id="form-dist">
@csrf
<input type="hidden" name="mano_obra_activa" value="1">
<script>window.MO_OBRAS = @json($moObrasInfo ?? new stdClass); window.MO_PERSONAS_DEP = @json($moPersonasDep ?? new stdClass); window.MO_EDIT = @json($puedeEditar ?? false);</script>
<input type="hidden" name="mes" value="{{ $mes }}">
<input type="hidden" name="anio" value="{{ $anio }}">
<input type="hidden" name="dist" value="{{ $distId }}">
<input type="hidden" name="departamento" value="{{ $depEfectivo }}">

@php
    $obrasConIngreso = array_filter($obras, fn($o) => empty($o['sin_ingreso']));
    $obrasSinIngreso = array_filter($obras, fn($o) => !empty($o['sin_ingreso']));
@endphp

{{-- ══════════ GRUPO: CON INGRESO (expandido) ══════════ --}}
<div class="grupo-obras" data-grupo="con">
    <div id="grupo-head-con" style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;padding:6px 2px;margin-bottom:4px">
        <svg id="chev-grupo-con" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#1B3F6E" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transform:rotate(90deg);transition:transform .15s"><polyline points="9 6 15 12 9 18"/></svg>
        <span style="font-weight:700;color:#1B3F6E;font-size:14px">Con ingreso</span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#EFF6FF;color:#1B3F6E">{{ count($obrasConIngreso) }}</span>
    </div>
    <div id="grupo-con">
        @foreach($obrasConIngreso as $cod => $o)
            @include('operativo.partials.obra-card')
        @endforeach
        @if(count($obrasConIngreso) === 0)
            <div style="color:#9CA3AF;font-size:12px;padding:10px 4px">No hay proyectos con ingreso en este filtro.</div>
        @endif
    </div>
</div>

{{-- ══════════ GRUPO: SIN INGRESO (colapsado) ══════════ --}}
<div class="grupo-obras" data-grupo="sin" style="margin-top:16px">
    <div id="grupo-head-sin" style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;padding:6px 2px;margin-bottom:4px">
        <svg id="chev-grupo-sin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#854D0E" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform .15s"><polyline points="9 6 15 12 9 18"/></svg>
        <span style="font-weight:700;color:#854D0E;font-size:14px">Sin ingreso · órdenes abiertas</span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#FEF3C7;color:#854D0E">{{ count($obrasSinIngreso) }}</span>
    </div>
    <div id="grupo-sin" style="display:none">
        @foreach($obrasSinIngreso as $cod => $o)
            @include('operativo.partials.obra-card')
        @endforeach
        @if(count($obrasSinIngreso) === 0)
            <div style="color:#9CA3AF;font-size:12px;padding:10px 4px">No hay proyectos sin ingreso en este filtro.</div>
        @endif
    </div>
</div>

@if($kpiObras > 0 && !$bloqueado)
{{-- BARRA GLOBAL FIJA: guarda TODA la distribución del mes (no por proyecto) y arma el plano a
     contabilidad. Vive dentro del form para que los submit funcionen. --}}
<div class="dist-actionbar">
    <div class="inner">
        <div class="sum">
            @if($puedeEditar)<span id="autosave-status" style="font-size:12px;color:#9CA3AF"></span>@endif
            <span>Total a distribuir del mes: <b id="barra-total-mes">$0</b></span>
        </div>
        <span class="sp"></span>
        <button type="submit" formaction="{{ route('operativo.distribucion.resumen') }}" formtarget="_blank" style="padding:9px 18px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:9px;font-size:13px;cursor:pointer">📄 Ver resumen</button>
        @if($distId)
        <a href="{{ route('operativo.distribucion.reporte-obras', $distId) }}" target="_blank" style="padding:9px 18px;background:white;border:1px solid #15803D;color:#15803D;border-radius:9px;font-size:13px;text-decoration:none">⬇ Excel por obra</a>
        @endif
        @if($puedeEditar)
        <button type="button" onclick="descartarCambios()" style="padding:9px 16px;background:white;border:1px solid #E5E7EB;color:#374151;border-radius:9px;font-size:13px;cursor:pointer">Descartar cambios</button>
        <button type="submit" name="accion" value="guardar" style="padding:9px 18px;background:#6B7280;color:white;border:none;border-radius:9px;font-size:13px;cursor:pointer">{{ $distId ? 'Guardar cambios' : 'Guardar borrador' }}</button>
        <button type="submit" name="accion" value="enviar" onclick="return confirm('¿Enviar toda la distribución del mes a contabilidad? La hoja quedará en solo lectura.')" style="padding:9px 18px;background:#15803D;color:white;border:none;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer">💾 Guardar · generar plano</button>
        @endif
    </div>
</div>
{{-- Espaciador para que la barra fija no tape el final del contenido. --}}
<div style="height:70px"></div>
@endif
</form>

{{-- MODAL: Mapa de calor de márgenes — una celda por proyecto, coloreada por su margen acumulado. --}}
@php
    $heatGrupos = [];
    foreach ($obras as $codH => $oH) {
        $depH = $oH['depto_margen'] ?? \App\Models\User::departamentoDeCodigo($codH);
        $pctH = $oH['mc_acum_cierre_pct'] ?? null;
        $nivelH = \App\Services\DistribucionService::nivelMargenCalor($pctH, $depH);
        $labelH = \App\Services\DistribucionService::DEPARTAMENTOS[$depH] ?? ($depH ? ucfirst($depH) : 'Otros');
        $heatGrupos[$labelH][] = ['cod' => $codH, 'nombre' => $oH['nombre'] ?? $codH, 'pct' => $pctH, 'nivel' => $nivelH];
    }
    // Dentro de cada grupo, de mayor a menor margen (los nulos al final).
    foreach ($heatGrupos as &$itsH) {
        usort($itsH, fn ($a, $b) => ($b['pct'] ?? -999) <=> ($a['pct'] ?? -999));
    }
    unset($itsH);
    ksort($heatGrupos);
@endphp
<div class="heat-ov" id="heat-modal">
    <div class="heat-modal">
        <div class="mh2"><b>🔥 Mapa de calor de márgenes</b>
            <button type="button" onclick="document.getElementById('heat-modal').classList.remove('open')" style="background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:7px;width:28px;height:28px;cursor:pointer;font-size:15px">✕</button>
        </div>
        <div class="mb">
            <div style="font-size:12.5px;color:#6B7280">Cada celda es un proyecto, coloreada por su <b>margen acumulado</b> (incluye esta distribución). Pasa el cursor para ver el nombre.</div>
            @forelse($heatGrupos as $labelH => $itemsH)
            <div class="heatlabel">{{ $labelH }} <span style="font-weight:500;color:#9CA3AF">· {{ count($itemsH) }}</span></div>
            <div class="heatgrid">
                @foreach($itemsH as $it)
                <div class="hc {{ $it['nivel'] }}" title="{{ $it['cod'] }} · {{ $it['nombre'] }}">
                    <div class="hcode">{{ $it['cod'] }}</div>
                    <div class="hm">{{ $it['pct'] === null ? '—' : $it['pct'].'%' }}</div>
                </div>
                @endforeach
            </div>
            @empty
            <div style="padding:16px 0;color:#9CA3AF;font-size:13px">No hay proyectos para mostrar en este filtro.</div>
            @endforelse
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:16px;font-size:12px;color:#6B7280">
                <span style="display:inline-flex;align-items:center;gap:6px"><i style="width:12px;height:12px;border-radius:3px;background:#1E9E54;display:inline-block"></i> Sano</span>
                <span style="display:inline-flex;align-items:center;gap:6px"><i style="width:12px;height:12px;border-radius:3px;background:#E0A32E;display:inline-block"></i> En el límite</span>
                <span style="display:inline-flex;align-items:center;gap:6px"><i style="width:12px;height:12px;border-radius:3px;background:#D6453F;display:inline-block"></i> Bajo margen — revisar</span>
                <span style="display:inline-flex;align-items:center;gap:6px"><i style="width:12px;height:12px;border-radius:3px;background:#9CA3AF;display:inline-block"></i> Sin dato</span>
            </div>
        </div>
    </div>
</div>

{{-- MODAL: ajustar tolerancia --}}
<div id="modal-calc" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:20px 22px;max-width:420px;width:90%">
        <h3 style="font-size:15px;font-weight:600;color:#1B3F6E;margin-bottom:8px">✨ Calcular costo sugerido</h3>
        <p style="font-size:12.5px;color:#6B7280;line-height:1.5;margin-bottom:14px">
            El sistema aplicará costo en cada obra sin que su <b>margen acumulado</b> baje más que la tolerancia por debajo del <b>margen ofertado</b> (piso = ofertado − tolerancia), validando también la proyección. Las obras que ya vienen por debajo de ese piso o en pérdida <b>no recibirán costos</b> y te las mostraré en un aviso.
        </p>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Tolerancia (puntos por debajo del margen ofertado)</label>
        <input type="number" id="tol-input" value="3" min="0" max="100" step="1"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:14px;margin-bottom:16px">
        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button type="button" onclick="cerrarCalculo()" style="padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer">Cancelar</button>
            <button type="button" onclick="ejecutarCalculo()" style="padding:8px 18px;background:#4338CA;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Calcular</button>
        </div>
    </div>
</div>

{{-- MODAL: resultado / alertas --}}
<div id="modal-alerta" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:20px 22px;max-width:560px;width:90%;max-height:82vh;overflow:auto">
        <h3 style="font-size:15px;font-weight:600;color:#1B3F6E;margin-bottom:12px">Resultado del cálculo</h3>
        <div id="alerta-contenido"></div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:8px">
            <button type="button" id="btn-export-alerta" onclick="exportarAlerta()" style="padding:8px 16px;background:white;border:1px solid #15803D;border-radius:8px;font-size:13px;color:#15803D;cursor:pointer">⬇ Exportar a Excel</button>
            <button type="button" onclick="cerrarAlerta()" style="padding:8px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Entendido</button>
        </div>
    </div>
</div>

{{-- MODAL: reasignar ítem a otra obra (Fase D) --}}
<div id="modal-reasignar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
    <div style="background:white;border-radius:12px;padding:20px 22px;max-width:540px;width:92%;max-height:85vh;overflow:auto">
        <h3 style="font-size:15px;font-weight:600;color:#1B3F6E;margin-bottom:6px">Reasignar ítem a otra obra</h3>
        <p style="font-size:12px;color:#6B7280;margin-bottom:12px">Origen: <b id="reasig-origen"></b>. Elige la obra destino; el costo baja en el origen y sube en el destino, y se refleja en el plano.</p>
        <input type="hidden" id="reasig-item">
        <input type="hidden" id="reasig-destino">
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Obra destino (código, nombre o cliente)</label>
        <input type="text" id="reasig-buscar" oninput="filtrarDestinos(this.value)" autocomplete="off" placeholder="🔎 Buscar obra…"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;margin-bottom:6px">
        <div id="reasig-lista" style="max-height:200px;overflow:auto;border:1px solid #E5E7EB;border-radius:8px;margin-bottom:8px"></div>
        <div id="reasig-sel" style="font-size:12px;color:#374151;margin-bottom:10px">Destino: <b>—</b></div>
        <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Justificación (opcional)</label>
        <textarea id="reasig-motivo" rows="2" placeholder="Motivo de la reasignación…"
            style="width:100%;padding:8px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;margin-bottom:14px"></textarea>
        <div style="display:flex;justify-content:flex-end;gap:8px">
            <button type="button" onclick="cerrarReasignar()" style="padding:8px 16px;background:white;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;color:#6B7280;cursor:pointer">Cancelar</button>
            <button type="button" id="reasig-confirm" onclick="confirmarReasignar()" style="padding:8px 18px;background:#4338CA;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Reasignar</button>
        </div>
    </div>
</div>

@php
    $ctaJs = $catalogo->keyBy('cuenta_14');
    $datosJs = [];
    foreach ($obras as $cod => $o) {
        $datosJs[$cod] = [
            'ingMes'   => $o['ingreso_mes'],
            'costoMes' => $o['costo_apl_mes'],
            'ingAcum'  => $o['ingreso_acum'],
            'costoAcum'=> $o['costo_apl_acum'],
            'of'       => $o['ofertado'],
            'mcProy'   => $o['pr_mc_proy'],
            'valorOferta' => (float) ($o['pr_valor_oferta'] ?? 0), // ingreso proyectado (denominador MC% proy)
            'nombre'   => $o['nombre'],
            'pend'     => $o['total_pendiente'],
            'rev'      => $o['total_reversado'],
            'sinIngreso' => (bool) ($o['sin_ingreso'] ?? false),
            'cap'      => max(0, (float) $o['ingreso_mes'] - abs((float) $o['costo_apl_mes'])),
            'prov'     => (float) ($o['sum_prov'] ?? 0),   // provisiones (costo sin aplicar, 14→26)
            'dep'      => $o['depto_margen'] ?? null,       // depto para el semáforo de márgenes
        ];
    }
@endphp
<script>
const CTA = @json($ctaJs);
const DATOS = @json($datosJs);
const BOLSAS = @json(collect($bolsas)->keyBy('codigo'));
const PERIODO = @json($mesNombre);

// Semáforo de márgenes (mismo criterio que el servidor): umbrales por departamento.
const UMBRALES_MARGEN = @json(\App\Services\DistribucionService::UMBRALES_MARGEN);
const COLORES_SEMAFORO = @json(\App\Services\DistribucionService::COLORES_SEMAFORO);
function colorSemaforo(pct, dep){
    const u = UMBRALES_MARGEN[dep];
    let nivel = 'gris';
    if (pct !== null && pct !== undefined && u) {
        if (pct >= u.verde) nivel = 'verde';
        else if (pct >= u.amarillo) nivel = 'amarillo';
        else if (pct >= u.rojo) nivel = 'rojo';
    }
    return COLORES_SEMAFORO[nivel]; // [fondo, texto]
}
function pintarSemaforo(el, pct, dep){
    if (!el) return;
    const c = colorSemaforo(pct, dep);
    el.style.background = c[0];
    el.style.color = c[1];
}
// Disponible en vivo por bolsa (arranca en lo que dejó el servidor, ya descontadas
// las asignaciones guardadas). Se decrementa al asignar y se repone al quitar.
const dispBolsa = {};
// Pool de saldo RESTANTE por cuenta 14 de cada bolsa (para el consumo FIFO en vivo).
const poolBolsa = {};
Object.keys(BOLSAS).forEach(c => {
    dispBolsa[c] = Number(BOLSAS[c].disponible || 0);
    poolBolsa[c] = (BOLSAS[c].pool || []).map(l => ({
        un_codigo: l.un_codigo, cuenta_14: l.cuenta_14, cuenta_61: l.cuenta_61,
        periodo: Number(l.periodo || 0), pendiente: Number(l.pendiente || 0),
    }));
});

// Expandir/colapsar el detalle por cuenta de una bolsa grande.
function toggleBolsaDetalle(c){
    const e = document.getElementById('bolsa-det-'+c); if(!e) return;
    const mostrar = (e.style.display === 'none' || !e.style.display);
    e.style.display = mostrar ? 'block' : 'none';
    document.querySelectorAll('.caret-bolsa-'+c).forEach(x => x.textContent = mostrar ? '▾' : '▸');
    if (mostrar) fixBolsaStickyTops(c);   // ya visible: ahora sí se pueden medir las alturas reales
}

/* Ajusta el 'top' fijo (sticky) de las filas del encabezado para que queden contiguas sin huecos:
   encabezado → filtros → Totales → Total filtrado. Se mide con el detalle ya visible. */
function fixBolsaStickyTops(code){
    const cont = document.getElementById('bolsa-det-'+code); if(!cont) return;
    const tbl  = cont.querySelector('table.bd-tbl'); if(!tbl) return;
    const head = tbl.querySelector('thead tr:first-child');
    const filt = tbl.querySelector('thead tr.bd-filtros');
    const tot  = tbl.querySelector('thead tr.bd-totales');
    const flt  = tbl.querySelector('thead tr.bd-totfilt');
    const hH = head ? head.offsetHeight : 28;
    if (filt) { filt.querySelectorAll('th').forEach(th => th.style.top = hH + 'px'); }
    const fH = filt ? filt.offsetHeight : 28;
    const totTop = hH + fH;
    if (tot) { tot.querySelectorAll('td').forEach(td => td.style.top = totTop + 'px'); }
    if (flt) { const tH = tot ? tot.offsetHeight : 0; flt.querySelectorAll('td').forEach(td => td.style.top = (totTop + tH) + 'px'); }
}

/* Expande/colapsa el desglose por concepto (Sueldo, Pensión, ARL…) de una persona de mano de obra. */
function toggleMoPersona(head){
    const row = head.closest('.mo-persona'); if(!row) return;
    const det = row.querySelector('.mo-pers-det'); if(!det) return;
    const show = (det.style.display === 'none' || !det.style.display);
    det.style.display = show ? 'block' : 'none';
    const car = head.querySelector('.mo-pers-caret'); if(car) car.textContent = show ? '▾' : '▸';
}

/* Abre/cierra el panel de "Mano de obra" (personal por aplicar) dentro de la zona de la bolsa. */
function toggleMoPanel(dep){
    const e = document.getElementById('mo-panel-'+dep); if(!e) return;
    const mostrar = (e.style.display === 'none' || !e.style.display);
    e.style.display = mostrar ? '' : 'none';
    document.querySelectorAll('.caret-mo-'+dep).forEach(x => x.textContent = mostrar ? '▾' : '▸');
    if (mostrar) e.scrollIntoView({behavior:'smooth', block:'nearest'});
}

/* Normaliza texto para filtrar: minúsculas y sin acentos. */
function _normTxt(s){ return (s || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

/* Filtro por columna del detalle de una bolsa (en vivo, "contiene", sin acentos/mayúsculas).
   Oculta las filas que no coinciden; las que pasan vuelven a su visibilidad por CSS (los $0 siguen
   ocultos salvo "mostrar lo ya cruzado"). */
function filtrarBolsaDet(code){
    const cont = document.getElementById('bolsa-det-'+code); if(!cont) return;
    const filtros = [];
    cont.querySelectorAll('tr.bd-filtros input[data-col]').forEach(inp => {
        const v = _normTxt(inp.value.trim());
        if (v !== '') filtros.push({ col: Number(inp.dataset.col), v });
    });
    cont.querySelectorAll('table.bd-tbl tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        let ok = true;
        for (const f of filtros) {
            const cell = tds[f.col];
            if (!_normTxt(cell ? cell.textContent : '').includes(f.v)) { ok = false; break; }
        }
        tr.style.display = ok ? '' : 'none';   // '' = respeta la visibilidad por CSS (ceros, etc.)
    });
    footerBolsa(code, filtros.length > 0);
}
/* Fila "Total filtrado" (fija, arriba): con filtros activos muestra la suma de las filas visibles
   (positivos + negativos) y el conteo; sin filtros se oculta. La conciliación del pie (Disponible −
   Reversado = Saldo de la bolsa neto) queda siempre visible y no cambia con los filtros. */
function footerBolsa(code, hayFiltro){
    const cont = document.getElementById('bolsa-det-'+code); if(!cont) return;
    const row = document.getElementById('ftFiltRow-'+code);
    const lbl = document.getElementById('ftFiltLbl-'+code);
    const val = document.getElementById('ftFilt-'+code);
    if (hayFiltro) {
        let vis = 0, n = 0;
        cont.querySelectorAll('table.bd-tbl tbody tr').forEach(tr => {
            if (tr.style.display === 'none' || !tr.hasAttribute('data-saldo')) return;
            vis += Number(tr.dataset.saldo || 0); n++;       // positivos + negativos de lo visible
        });
        if (lbl) lbl.textContent = 'Total filtrado (' + n + (n === 1 ? ' línea' : ' líneas') + ' visibles)';
        if (val) val.textContent = fmt(vis);
        if (row) row.style.display = '';
    } else {
        if (row) row.style.display = 'none';
    }
}
function limpiarFiltrosBolsa(code){
    const cont = document.getElementById('bolsa-det-'+code); if(!cont) return;
    cont.querySelectorAll('tr.bd-filtros input[data-col]').forEach(inp => inp.value = '');
    cont.querySelectorAll('table.bd-tbl tbody tr').forEach(tr => tr.style.display = '');
    footerBolsa(code, false);
}

/* "Solicitar reverso a contabilidad" en una línea de reversado de más: señala la fila (verde)
   y muestra el selector de destino (a qué cuenta 1420 con saldo / otra U.N. reclasificar). */
function toggleReverso(cb){
    const tr = cb.closest('tr'); if (!tr) return;
    tr.classList.toggle('bd-rev-on', cb.checked);
    const dest = tr.querySelector('.bd-rev-dest');
    if (dest) dest.style.display = cb.checked ? '' : 'none';
}
/* Al elegir "Otra U.N.…" se pide el código de la U.N. destino; con una cuenta de la misma U.N. se oculta. */
function onReversoDestino(sel){
    const un = sel.closest('.bd-rev-dest')?.querySelector('.bd-rev-un');
    if (un) un.style.display = (sel.value === 'otra') ? '' : 'none';
}

/* Interruptor "Mostrar lo ya cruzado ($0)" del detalle de una bolsa (presentación). */
function toggleBolsaZeros(c, on){
    const e = document.getElementById('bolsa-det-'+c); if(!e) return;
    e.classList.toggle('show-zeros', on);
}

/* Distribución en línea del detalle por tercero: al escribir "A distribuir" recalcula "Queda mes
   sig." de la fila y la fila de Totales de esa bolsa (en vivo, con separadores de miles). */
function onBolsaDetInput(inp){
    const v = soloDigitos(inp.value);
    inp.value = v ? v.toLocaleString('es-CO') : '';
    const tr = inp.closest('tr');
    const saldo = Number(tr?.dataset.saldo || 0);
    const qv = tr?.querySelector('.bd-qv'); if(qv) qv.textContent = fmt(Math.max(saldo - v, 0));
    bolsaDetTotales(inp.dataset.bolsa);
}
function bolsaDetTotales(code){
    let s = 0, d = 0;
    document.querySelectorAll('#bolsa-det-'+code+' tbody tr').forEach(tr => {
        if (tr.classList.contains('bz-row')) return;      // las $0 (ya cruzadas) no suman
        if (tr.classList.contains('bz-alerta')) return;   // reversado de más (negativo): no es saldo a distribuir
        s += Number(tr.dataset.saldo || 0);
        const i = tr.querySelector('input[data-tipo="bolsamonto"]');
        d += i ? soloDigitos(i.value) : 0;
    });
    const set = (id, val) => { const e = document.getElementById(id); if(e) e.textContent = fmt(val); };
    set('ftSaldo-'+code, s); set('ftDist-'+code, d); set('ftQueda-'+code, Math.max(s - d, 0));
    // Refresca la barra "Disponible por distribuir" de la tarjeta (aprox: suma de A distribuir).
    set('bolsa-disp-'+code, d);
    const bar = document.getElementById('bolsa-bar-'+code);
    if (bar) bar.style.width = Math.min(100, s > 0 ? Math.round(d / s * 100) : 0) + '%';
}

// Solo dígitos de un valor con formato (ej. "1.500.000" → 1500000).
function soloDigitos(v){ const n = parseInt(String(v).replace(/\D/g, ''), 10); return isNaN(n) ? 0 : n; }

/* Marca en amarillo la celda "a distribuir" de una bolsa cuando queda distinta al saldo. */
function marcarTocadaBolsa(inp){
    if(!inp) return;
    const saldo = Math.round(Number(inp.dataset.saldo || 0));
    const val   = soloDigitos(inp.value);
    inp.classList.toggle('celda-tocada', val !== saldo);
}

// Al editar "A distribuir": formatea con puntos de miles (es-CO), capea al saldo y
// recalcula "Queda mes siguiente" por cuenta + los totales de la bolsa en vivo.
function onMontoInput(inp){
    const saldo = Number(inp.dataset.saldo || 0);
    let monto = soloDigitos(inp.value);
    if (monto > saldo) monto = saldo;                 // no se puede distribuir más que el saldo
    inp.value = monto ? monto.toLocaleString('es-CO') : '';   // formato de dinero con puntos
    marcarTocadaBolsa(inp);                                     // resalta si quedó distinta al saldo

    const cell = document.getElementById('queda-' + inp.dataset.kid);
    if (cell) cell.textContent = fmt(Math.max(0, saldo - monto));

    // Totales de la bolsa: "a distribuir" (suma de inputs) y "queda" (saldo − a distribuir).
    const tabla = inp.closest('table');
    let totAdist = 0, totQueda = 0;
    tabla.querySelectorAll('input[data-tipo="bolsamonto"]').forEach(i => {
        const s = Number(i.dataset.saldo || 0);
        let m = soloDigitos(i.value);
        if (m > s) m = s;
        totAdist += m;
        totQueda += Math.max(0, s - m);
    });
    const adistEl = document.getElementById('adist-tot-' + inp.dataset.bolsa);
    if (adistEl) adistEl.textContent = fmt(totAdist);
    const totEl = document.getElementById('queda-tot-' + inp.dataset.bolsa);
    if (totEl) totEl.textContent = fmt(totQueda);
}
let asignIdx = {};

function fmtPeriodo(p){ p = Number(p||0); if(!p) return '—'; const y = Math.floor(p/100), m = p%100; return String(m).padStart(2,'0')+'/'+y; }

// Consume 'monto' del pool de una bolsa en FIFO (período más antiguo primero, la última
// porción parcial). Muta el pool y devuelve el detalle [{cuenta_14,cuenta_61,periodo,monto}].
function drenarFifoJS(pool, monto){
    pool.sort((a,b) => a.periodo - b.periodo);
    let rem = Math.max(0, monto); const out = [];
    for(const l of pool){
        if(rem <= 0.005) break;
        if(l.pendiente <= 0.005) continue;
        const usar = Math.min(l.pendiente, rem);
        out.push({un_codigo:l.un_codigo, cuenta_14:l.cuenta_14, cuenta_61:l.cuenta_61, periodo:l.periodo, monto:Math.round(usar)});
        l.pendiente -= usar; rem -= usar;
    }
    return out;
}
// Repone al pool lo que devolvía un chip quitado (según su detalle).
function restaurarPool(bolsa, detalle){
    const pool = poolBolsa[bolsa]; if(!pool || !detalle) return;
    detalle.forEach(d => {
        const ln = pool.find(x => x.un_codigo === d.un_codigo && x.cuenta_14 === d.cuenta_14);
        if(ln) ln.pendiente += Number(d.monto||0);
        else pool.push({un_codigo:d.un_codigo, cuenta_14:d.cuenta_14, cuenta_61:d.cuenta_61, periodo:Number(d.periodo||0), pendiente:Number(d.monto||0)});
    });
}
function detalleHtml(detalle){
    return (detalle||[]).map(d =>
        '<div style="font-size:10px;color:#92400E">· <span style="font-family:monospace">'+d.cuenta_14+'</span> '
        + fmtPeriodo(d.periodo) + ' → <span style="font-family:monospace">'+d.cuenta_61+'</span>: '+fmt(d.monto)+'</div>'
    ).join('');
}
const ESTCOL = {abierta:['#F0FDF4','#15803D'], parcial:['#FEF9C3','#854D0E'], cerrada:['#EFF6FF','#1B3F6E']};
let provIdx = {};
let ultimaAlerta = { perdida: [], bajo: [], periodo: '' };

const fmt = n => '$' + Math.round(n).toLocaleString('es-CO');

/* ===== Filtro: al cambiar el departamento se recarga solo =====
   El servidor es el que sabe qué tipos de obra corresponden a cada departamento,
   así que reiniciamos el tipo a "todos" y enviamos el formulario de inmediato. */
function cambiarDepartamento(sel){
    const form = document.getElementById('form-filtros');
    if(!form) return;
    const tipo = form.querySelector('select[name="tipo"]');
    if(tipo){ tipo.value = 'todos'; tipo.disabled = false; }
    sel.style.opacity = '.6';
    form.submit();
}

/* Abre/cierra la zona de distribución de una obra y sincroniza el botón "⊕ Distribuir costos". */
function setObraAbierta(cod, abrir){
    const e=document.getElementById('obra-'+cod); if(!e) return;
    e.style.display = abrir ? 'block' : 'none';
    const txt=document.getElementById('distbtn-txt-'+cod);
    const ico=document.getElementById('distbtn-ico-'+cod);
    const btn=document.getElementById('distbtn-'+cod);
    if(txt) txt.textContent = abrir ? 'Ocultar distribución' : 'Distribuir costos';
    if(ico) ico.textContent = abrir ? '▾' : '⊕';
    if(btn) btn.style.background = abrir ? '#5A6B82' : '#1B3F6E';
}
function toggleObra(cod){ const e=document.getElementById('obra-'+cod); if(!e) return; setObraAbierta(cod, e.style.display==='none' || !e.style.display); }

/* ===== Grupos colapsables (Con ingreso / Sin ingreso) ===== */
function toggleGrupo(g){
    const cont = document.getElementById('grupo-'+g);
    const chev = document.getElementById('chev-grupo-'+g);
    if(!cont) return;
    const abierto = cont.style.display !== 'none';
    cont.style.display = abierto ? 'none' : 'block';
    if(chev) chev.style.transform = abierto ? '' : 'rotate(90deg)';
}
function abrirGrupo(g){
    const cont = document.getElementById('grupo-'+g);
    const chev = document.getElementById('chev-grupo-'+g);
    if(cont) cont.style.display = 'block';
    if(chev) chev.style.transform = 'rotate(90deg)';
}
/* Expandir / colapsar el detalle de todas las obras. */
function expandirTodo(abrir){
    if(abrir){ abrirGrupo('con'); abrirGrupo('sin'); }
    document.querySelectorAll('[id^="obra-"]').forEach(e => setObraAbierta(e.id.slice(5), abrir));
}
/* Normaliza para buscar: minúsculas y SIN acentos (á→a), para que "valle"
   encuentre "FUNDACIÓN VALLE DEL LILI". */
function normalizaBuscar(s){
    return (s == null ? '' : String(s)).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}
/* Buscador por código, nombre del proyecto o cliente (coincidencia parcial,
   insensible a mayúsculas y acentos). Encuentra también dentro de grupos colapsados. */
function filtrarObras(q){
    q = normalizaBuscar(q).trim();
    document.querySelectorAll('.obra-card').forEach(card => {
        const hay = !q || normalizaBuscar(card.dataset.buscar).includes(q);
        card.style.display = hay ? '' : 'none';
    });
    if(q){ abrirGrupo('con'); abrirGrupo('sin'); } // al buscar, abre ambos grupos
}
function toggleProvForm(cod){ const e=document.getElementById('provform-'+cod); e.style.display = e.style.display==='none'?'block':'none'; }
/* Ítems inline (Fase C): expandir/colapsar el detalle bajo la fila de la cuenta 14. */
function toggleItemsCuenta(id, row){
    const e = document.getElementById(id);
    if(!e) return;
    const mostrar = (e.style.display === 'none' || !e.style.display);
    e.style.display = mostrar ? '' : 'none';   // '' = fila de tabla por defecto
    if(row){ const c = row.querySelector('.caret-itc'); if(c) c.textContent = mostrar ? '▾' : '▸'; }
}

/* ===== Fase D: reasignar ítem a otra obra ===== */
const REASIG_DESTINOS = @json($destinos ?? []);
let reasigOrigen = '';
function abrirReasignar(itemId, origen){
    reasigOrigen = origen;
    document.getElementById('reasig-item').value = itemId;
    document.getElementById('reasig-origen').textContent = origen;
    document.getElementById('reasig-destino').value = '';
    document.getElementById('reasig-buscar').value = '';
    document.getElementById('reasig-motivo').value = '';
    document.getElementById('reasig-sel').innerHTML = 'Destino: <b>—</b>';
    document.getElementById('reasig-confirm').disabled = true;
    filtrarDestinos('');
    document.getElementById('modal-reasignar').style.display = 'flex';
}
function cerrarReasignar(){ document.getElementById('modal-reasignar').style.display = 'none'; }
function filtrarDestinos(q){
    q = normalizaBuscar(q).trim();
    const cont = document.getElementById('reasig-lista');
    const matches = REASIG_DESTINOS
        .filter(d => d.codigo !== reasigOrigen && (!q || normalizaBuscar(d.codigo+' '+d.nombre+' '+d.cliente).includes(q)))
        .slice(0, 40);
    cont.innerHTML = matches.map(d =>
        '<div onclick="seleccionarDestino(\''+d.codigo+'\')" style="padding:6px 8px;border-bottom:1px solid #F3F4F6;cursor:pointer;font-size:12px">'
        + '<b style="color:#1B3F6E">'+d.codigo+'</b>'+(d.nombre?' · '+d.nombre:'')+(d.cliente?' · 🏢 '+d.cliente:'')+'</div>'
    ).join('') || '<div style="padding:8px;color:#9CA3AF;font-size:12px">Sin coincidencias</div>';
}
function seleccionarDestino(cod){
    document.getElementById('reasig-destino').value = cod;
    document.getElementById('reasig-sel').innerHTML = 'Destino: <b>'+cod+'</b>';
    document.getElementById('reasig-confirm').disabled = false;
}
function confirmarReasignar(){
    const item = document.getElementById('reasig-item').value;
    const destino = document.getElementById('reasig-destino').value;
    if(!destino){ alert('Elige una obra destino.'); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = @json(url('operativo/items')) + '/' + item + '/reasignar';
    f.style.display = 'none';
    const add = (name, val) => { const i = document.createElement('input'); i.type='hidden'; i.name=name; i.value=val; f.appendChild(i); };
    add('_token', @json(csrf_token()));
    add('destino', destino);
    add('dist', @json($distId));
    add('motivo', document.getElementById('reasig-motivo').value || '');
    document.body.appendChild(f);
    f.submit();
}
function capear(inp){ const max=parseFloat(inp.max||0); let v=soloDigitos(inp.value); if(v>max){v=Math.round(max);} inp.value = v ? v.toLocaleString('es-CO') : ''; }

/* Formatea el campo "a aplicar" con separador de miles (es-CO): 2779139 → 2.779.139. */
function formatAplicar(inp){
    const d = String(inp.value).replace(/\D/g, '');
    inp.value = d === '' ? '' : (parseInt(d, 10) || 0).toLocaleString('es-CO');
}
/* Al escribir en "a aplicar": capea al pendiente (max), formatea con miles y recalcula. */
function aplicarInput(inp, cod){
    const max = Math.round(parseFloat(inp.max || 0));
    let v = soloDigitos(inp.value);
    if (v > max) v = max;
    inp.value = v ? v.toLocaleString('es-CO') : '';
    marcarTocada(inp);
    recalc(cod);
}
/* Deja solo dígitos en los campos "a aplicar" (para el POST/autoguardado) y restaura el formato. */
function normalizarAplicar(){ document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(i => { i.value = String(soloDigitos(i.value)); }); }
function reformatearAplicar(){ document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(formatAplicar); }

/* Marca en amarillo la celda "a aplicar" cuando Operaciones la deja distinta al
   pendiente completo de la cuenta (data-tope = saldo pendiente). Así se ve cuáles tocaron. */
function marcarTocada(inp){
    if(!inp || inp.dataset.tipo !== 'aplicar') return;
    formatAplicar(inp);
    const base = Math.round(parseFloat(inp.dataset.tope || 0));
    const val  = soloDigitos(inp.value);
    const tocada = val !== base;
    inp.classList.toggle('celda-tocada', tocada);
    inp.title = tocada ? '✏ Modificado por Operaciones' : '';
}
function remarcarAplicar(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(marcarTocada);
}

function sumAplicar(cod){
    const card=document.getElementById('card-'+cod); let s=0;
    card.querySelectorAll('input[data-tipo="aplicar"]').forEach(i=>s+=soloDigitos(i.value));
    return s;
}
function sumProv(cod){
    const card=document.getElementById('card-'+cod); let s=0;
    card.querySelectorAll('input[data-tipo="prov"]').forEach(i=>s+=parseFloat(i.value||0));
    return s;
}
function esCerrable(cod){
    const d=DATOS[cod]; if(!d) return true;
    return (d.rev||0)<=0.5 && sumAplicar(cod) >= (d.pend||0)-0.5;
}
function evaluarCerrable(cod){
    const seg=document.getElementById('estado-seg-'+cod); if(!seg) return;
    const btnCerr=seg.querySelector('button[data-estado="cerrada"]');
    const inp=document.getElementById('estado-inp-'+cod);
    const ok=esCerrable(cod);
    if(btnCerr){ btnCerr.disabled=!ok; btnCerr.title = ok ? '' : 'Disponible cuando quede en $0 por distribuir'; }
    if(!ok && inp && inp.value==='cerrada'){ cambiarEstado(cod,'parcial'); }
}

function recalc(cod){
    const card=document.getElementById('card-'+cod);
    let sumA=0, sumB=0;
    card.querySelectorAll('input[data-tipo="aplicar"]').forEach(i=>sumA+=soloDigitos(i.value));
    card.querySelectorAll('input[data-tipo="bolsa"]').forEach(i=>sumB+=parseFloat(i.value||0));
    const d=DATOS[cod]; if(!d) return;
    const prov = Number(d.prov||0);          // provisiones activas (costo sin aplicar, 14→26)
    const moC  = sumMoObra(cod);              // mano de obra directa aplicada a esta obra
    const aplicado14a6 = sumA + sumB;         // lo que se aplica 14→6

    const tot=document.getElementById('aplicar-tot-'+cod); if(tot) tot.textContent=fmt(aplicado14a6 + prov);
    // "Costos por llevar a este proyecto": el inventario propio que aún no se ha aplicado (baja al
    // escribir montos). sumA = lo aplicado 14→6 del propio proyecto (la bolsa viene de otra UN).
    const porll=document.getElementById('porllevar-'+cod); if(porll) porll.textContent=fmt(Math.max(0, (d.pend||0) - sumA));
    const a6=document.getElementById('aplic6-'+cod); if(a6) a6.textContent=fmt(aplicado14a6);
    const psa=document.getElementById('provsa-'+cod); if(psa) psa.textContent=fmt(prov);
    // "Costo aplicado del mes" = costo ya aplicado + lo que se lleva al costo ahora (inventario + bolsa +
    // mano de obra). Sube en vivo con cada asignación (p. ej. $4M de MO lo suben $4M).
    const cmEl=document.getElementById('costomes-'+cod); if(cmEl) cmEl.textContent=fmt((d.costoMes||0) + aplicado14a6 + moC);

    evaluarCerrable(cod);

    // La provisión (14→26) es "costo sin aplicar" pero igual cuenta como costo del mes.
    // La mano de obra directa aplicada (moC) también suma al costo real de la obra.
    const costoMesTotal = (d.costoMes||0) + aplicado14a6 + prov + moC;
    const mcMesPesos = (d.ingMes||0) - costoMesTotal;
    const mcEl=document.getElementById('mcmes-'+cod);
    if(mcEl){ mcEl.textContent=fmt(mcMesPesos); mcEl.style.color = mcMesPesos>=0 ? '#15803D' : '#DC2626'; }
    const pctMes = d.ingMes ? (mcMesPesos/d.ingMes*100) : null;
    const pctEl=document.getElementById('mcpct-'+cod);
    if(pctEl){ pctEl.textContent = pctMes===null ? '—' : (pctMes.toFixed(1)+'%'); pintarSemaforo(pctEl, pctMes===null?null:Number(pctMes.toFixed(1)), d.dep); }

    // Acumulado de cierre: hasta este mes INCLUYENDO la distribución (14→6), la provisión y la MO.
    const costoAcumCierre = (d.costoAcum||0) + aplicado14a6 + prov + moC;
    const mcAcumPesos = (d.ingAcum||0) - costoAcumCierre;
    const caEl=document.getElementById('costoacum-'+cod); if(caEl) caEl.textContent=fmt(costoAcumCierre);
    const maEl=document.getElementById('mcacum-'+cod);
    if(maEl){ maEl.textContent=fmt(mcAcumPesos); maEl.style.color = mcAcumPesos>=0 ? '#15803D' : '#DC2626'; }
    const pctAcum = d.ingAcum ? (mcAcumPesos/d.ingAcum*100) : null;
    const mapEl=document.getElementById('mcacumpct-'+cod);
    if(mapEl){ mapEl.textContent = pctAcum===null ? '—' : (pctAcum.toFixed(1)+'%'); pintarSemaforo(mapEl, pctAcum===null?null:Number(pctAcum.toFixed(1)), d.dep); }

    // PROYECCIÓN en vivo (ahora dentro del bloque fijo): costo real = costo acumulado + inventario
    // pendiente + provisiones + otros costos (bolsa + mano de obra). Distribuir inventario mueve
    // pendiente→aplicado (no cambia el costo real); provisiones/bolsa/MO sí lo cambian.
    const vo = Number(d.valorOferta || 0);
    const costoRealProy = (d.costoAcum || 0) + (d.pend || 0) + prov + sumB + moC;
    const prcostoEl = document.getElementById('prcosto-'+cod);
    if(prcostoEl) prcostoEl.textContent = fmt(costoRealProy);
    const mcProy = vo > 0 ? ((vo - costoRealProy) / vo * 100) : null;
    const prmcpEl = document.getElementById('prmcp-'+cod);
    if(prmcpEl){
        prmcpEl.textContent = mcProy===null ? '—' : (mcProy.toFixed(1)+'%');
        // Semáforo: si el ofertado es menor al proyectado, va verde (bajo margen que se está mejorando).
        const forzarVerde = (d.of !== null && mcProy !== null && Number(d.of) < mcProy);
        pintarSemaforo(prmcpEl, mcProy===null ? null : (forzarVerde ? 999 : Number(mcProy.toFixed(1))), d.dep);
    }
    const prdeltaEl = document.getElementById('prdelta-'+cod);
    if(prdeltaEl && d.of !== null && mcProy !== null){
        const delta = Math.round((mcProy - Number(d.of)) * 10) / 10;
        prdeltaEl.textContent = (delta >= 0 ? ('▲ +'+delta) : ('▼ '+delta)) + ' pts vs oferta';
        prdeltaEl.style.color = delta >= 0 ? '#15803D' : '#DC2626';
    }

    actualizarBarraGlobal();
}

/* Total "a distribuir del mes" de la barra global fija: suma de lo que se lleva al costo en TODOS
   los proyectos (aplicado 14→6 + bolsa + mano de obra) más las provisiones activas. En vivo. */
function actualizarBarraGlobal(){
    const el = document.getElementById('barra-total-mes'); if(!el) return;
    let t = 0;
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(i => t += soloDigitos(i.value));
    document.querySelectorAll('#form-dist input[data-tipo="bolsa"]').forEach(i => t += parseFloat(i.value||0));
    document.querySelectorAll('#form-dist .mo-obra-sec [data-mo-monto]').forEach(i => t += soloDigitos(i.value));
    for (const cod in DATOS) { t += Number((DATOS[cod]||{}).prov || 0); }
    el.textContent = fmt(t);
}

/* "Descartar cambios": recarga la pantalla con lo último guardado (descarta lo que se esté
   editando sin guardar). El guard de "abandonar sitio" se omite porque es una recarga intencional. */
function descartarCambios(){
    if (confirm('¿Descartar los cambios sin guardar y recargar con lo último guardado?')) {
        window.onbeforeunload = null;
        location.reload();
    }
}

/* Clic del usuario en el control segmentado de estado: cambia y marca la distribución como
   "sin guardar" (dispara el autoguardado). Las llamadas programáticas usan cambiarEstado directo. */
function estadoClick(cod, val){
    cambiarEstado(cod, val);
    const inp=document.getElementById('estado-inp-'+cod);
    if(inp) inp.dispatchEvent(new Event('change', {bubbles:true}));
}
function cambiarEstado(cod, val){
    if(val==='cerrada' && !esCerrable(cod)){
        alert('No puedes cerrar esta obra: aún tiene saldo por distribuir. Déjala en $0 por distribuir (o resuelve el reversado) para poder cerrarla.');
        val='parcial';
    }
    const inp=document.getElementById('estado-inp-'+cod); if(inp) inp.value=val;
    const seg=document.getElementById('estado-seg-'+cod);
    if(seg) seg.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.estado===val));
}

/* ===== Provisiones persistentes (se conservan cada mes hasta reversarlas) =====
   Se crean/reversan por AJAX (sin recargar): así NO se pierde lo que estén editando
   en la distribución ni sale el aviso de "abandonar sitio". Actualizan lista y margen. */
const PROV_HEADERS = { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) };

function crearProvision(cod){
    const c14  = document.getElementById('prov-cta-'+cod).value;
    const monto = Number(String(document.getElementById('prov-monto-'+cod).value||'').replace(/\D/g,''));
    const desc = document.getElementById('prov-desc-'+cod).value || '';
    if(!c14 || !(monto>0)){ alert('Elige la cuenta 14 y un monto mayor a 0.'); return; }
    const fd = new FormData();
    fd.append('codigo_proyecto', cod); fd.append('cuenta_14', c14); fd.append('monto', monto); fd.append('descripcion', desc);
    fd.append('mes', @json($mes)); fd.append('anio', @json($anio)); fd.append('departamento', @json($depEfectivo));
    fetch(@json(route('operativo.provisiones.crear')), { method:'POST', headers: PROV_HEADERS, body: fd, credentials:'same-origin' })
        .then(r => r.ok ? r.json() : r.json().then(j=>Promise.reject(j)))
        .then(j => {
            if(!j.ok) throw j;
            const p = j.provision;
            const cont = document.getElementById('provs-'+cod);
            const vac = cont.querySelector('.prov-vacio-'+cod); if(vac) vac.style.display='none';
            const div = document.createElement('div');
            div.setAttribute('data-prov-id', p.id); div.dataset.cod = cod; div.dataset.monto = Math.round(p.monto);
            div.style.cssText = 'display:flex;align-items:center;justify-content:space-between;font-size:11px;padding:5px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px';
            // Recién creada: "del mes · no enviada" → se puede ELIMINAR (borrado directo, sin reverso).
            div.innerHTML = '<span>🧾 <span style="font-family:monospace">'+p.cuenta_14+'</span> → <span style="font-family:monospace">'+p.cuenta_26+'</span>'
                + (p.descripcion?(' · '+p.descripcion):'') + ' <span style="color:#9CA3AF">· activa desde '+p.desde+'</span>'
                + ' <span title="Registrada este mes y aún no enviada a contabilidad: se puede eliminar sin generar reverso." style="font-size:9px;padding:1px 6px;border-radius:6px;background:#DCFCE7;color:#15803D;margin-left:4px">del mes · no enviada</span></span>'
                + '<span style="display:flex;align-items:center;gap:10px"><b>'+fmt(p.monto)+'</b>'
                + '<a href="#" onclick="eliminarProvision('+p.id+');return false" title="Borrado directo: no se ha enviado a contabilidad, no genera reverso" style="color:#DC2626;text-decoration:none;font-weight:600">Eliminar</a></span>';
            cont.insertBefore(div, cont.querySelector('.prov-vacio-'+cod));
            if(DATOS[cod]) DATOS[cod].prov = Number(DATOS[cod].prov||0) + Number(p.monto||0);
            recalc(cod);
            document.getElementById('prov-monto-'+cod).value=''; document.getElementById('prov-desc-'+cod).value='';
        })
        .catch(e => alert(e && e.error ? e.error : 'No se pudo crear la provisión.'));
}

function reversarProvision(id){
    if(!confirm('¿Reversar esta provisión? Se generará el asiento inverso (26 → 61) en el mes en curso y dejará de arrastrarse.')) return;
    const row = document.querySelector('[data-prov-id="'+id+'"]');
    const cod = row ? row.dataset.cod : null;
    const monto = row ? Number(row.dataset.monto||0) : 0;
    const fd = new FormData(); fd.append('mes', @json($mes)); fd.append('anio', @json($anio));
    fetch(@json(url('operativo/provisiones'))+'/'+id+'/reversar', { method:'POST', headers: PROV_HEADERS, body: fd, credentials:'same-origin' })
        .then(r => r.ok ? r.json() : r.json().then(j=>Promise.reject(j)))
        .then(j => {
            if(!j.ok) throw j;
            quitarFilaProvision(row, cod, monto);
        })
        .catch(e => alert(e && e.error ? e.error : 'No se pudo reversar la provisión.'));
}

/* Eliminar una provisión del mes aún NO enviada a contabilidad: borrado directo, sin reverso. */
function eliminarProvision(id){
    if(!confirm('¿Eliminar esta provisión? Aún no se ha enviado a contabilidad, así que se borra directamente (no genera reverso).')) return;
    const row = document.querySelector('[data-prov-id="'+id+'"]');
    const cod = row ? row.dataset.cod : null;
    const monto = row ? Number(row.dataset.monto||0) : 0;
    fetch(@json(url('operativo/provisiones'))+'/'+id, { method:'DELETE', headers: PROV_HEADERS, credentials:'same-origin' })
        .then(r => r.ok ? r.json() : r.json().then(j=>Promise.reject(j)))
        .then(j => {
            if(!j.ok) throw j;
            quitarFilaProvision(row, cod, monto);
        })
        .catch(e => alert(e && e.error ? e.error : 'No se pudo eliminar la provisión.'));
}

/* Quita la fila de una provisión del DOM, baja el acumulado de provisión y recalcula los KPIs. */
function quitarFilaProvision(row, cod, monto){
    if(row) row.remove();
    if(cod && DATOS[cod]){ DATOS[cod].prov = Math.max(0, Number(DATOS[cod].prov||0) - monto); recalc(cod); }
    if(cod){ const c=document.getElementById('provs-'+cod); const vac=c && c.querySelector('.prov-vacio-'+cod);
             if(vac && !c.querySelector('[data-prov-id]')) vac.style.display=''; }
}

/* ===== Asignación desde bolsas de área ===== */
function fmtBolsaDisp(c){
    const disp = Math.max(0, dispBolsa[c] || 0);
    const total = Number(BOLSAS[c] ? BOLSAS[c].total : 0) || 0;
    const pct = total > 0 ? Math.round(disp / total * 100) : 0;
    const dispEl = document.getElementById('bolsa-disp-'+c); if(dispEl) dispEl.textContent = fmt(disp);
    const barEl  = document.getElementById('bolsa-bar-'+c);  if(barEl)  barEl.style.width = pct + '%';
    const doneEl = document.getElementById('bolsa-done-'+c); if(doneEl) doneEl.style.display = disp <= 0.5 ? 'inline-block' : 'none';
    // El disponible cambió: refrescar el indicador dentro de cada obra que muestre esta bolsa.
    refrescarDispObras();
}

/* Disponible de la bolsa elegida, mostrado DENTRO de cada obra (junto a "Asignar
   desde bolsa"), para no tener que subir al panel. Refleja el disponible acumulado
   al mes y se actualiza en vivo con cada asignación. */
function actualizarDispObra(cod){
    const sel  = document.getElementById('asignbolsa-cta-'+cod);
    const wrap = document.getElementById('disp-obra-wrap-'+cod);
    if(!sel || !wrap) return;
    const bolsa = sel.value;
    if(!bolsa){ wrap.style.display = 'none'; return; }
    const disp = Math.max(0, dispBolsa[bolsa] || 0);
    const bEl = document.getElementById('disp-obra-bolsa-'+cod); if(bEl) bEl.textContent = bolsa;
    const dEl = document.getElementById('disp-obra-'+cod);
    if(dEl){ dEl.textContent = fmt(disp); dEl.style.color = disp <= 0.5 ? '#DC2626' : '#92400E'; }
    wrap.style.display = 'block';
}
function refrescarDispObras(){
    document.querySelectorAll('[id^="asignbolsa-cta-"]').forEach(sel => {
        actualizarDispObra(sel.id.slice('asignbolsa-cta-'.length));
    });
}

function asignarBolsa(cod){
    const sel = document.getElementById('asignbolsa-cta-'+cod);
    const inp = document.getElementById('asignbolsa-monto-'+cod);
    if(!sel || !inp) return;
    const bolsa = sel.value;
    let monto = Math.round(parseFloat(inp.value||0));
    if(!bolsa){ alert('Elige una bolsa.'); return; }
    if(!(monto>0)){ alert('Escribe un monto mayor a 0.'); return; }
    const disp = Math.max(0, dispBolsa[bolsa] || 0);
    if(disp <= 0.5){ alert('Esa bolsa ya no tiene disponible por distribuir.'); return; }
    if(monto > disp){
        alert('Solo puedes asignar hasta '+fmt(disp)+' de la bolsa '+bolsa+'. Se ajustó a ese máximo.');
        monto = Math.round(disp);
    }
    const info = BOLSAS[bolsa] || {nombre:''};
    // Consumo FIFO en vivo: de qué cuentas 14 (y períodos) sale este monto.
    const detalle = drenarFifoJS(poolBolsa[bolsa], monto);
    asignIdx[cod] = (asignIdx[cod]||0) + 1; const i = 'n'+asignIdx[cod];
    const div = document.createElement('div');
    div.style.cssText='font-size:11px;padding:5px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px';
    div.dataset.bolsa = bolsa; div.dataset.monto = monto; div.dataset.detalle = JSON.stringify(detalle);
    div.innerHTML = '<div style="display:flex;align-items:center;justify-content:space-between">'
        + '<span>🡒 Desde <b>'+bolsa+'</b> · '+(info.nombre||'')+'</span>'
        + '<span style="display:flex;align-items:center;gap:8px"><b>'+fmt(monto)+'</b>'
        + '<a href="#" onclick="quitarBolsa(this,\''+cod+'\');return false" style="color:#DC2626;text-decoration:none">✕</a></span></div>'
        + '<div style="margin-top:2px">'+detalleHtml(detalle)+'</div>'
        + '<input type="hidden" name="asignacion_bolsa['+cod+']['+i+'][bolsa]" value="'+bolsa+'">'
        + '<input type="hidden" name="asignacion_bolsa['+cod+']['+i+'][monto]" value="'+monto+'" data-cod="'+cod+'" data-tipo="bolsa" data-bolsa="'+bolsa+'">';
    document.getElementById('asigns-'+cod).appendChild(div);
    dispBolsa[bolsa] = disp - monto;
    fmtBolsaDisp(bolsa);
    inp.value='';
    recalc(cod);
}

function quitarBolsa(el, cod){
    const chip = el.closest('div[data-bolsa]');
    if(!chip) return;
    const bolsa = chip.dataset.bolsa;
    const monto = parseFloat(chip.dataset.monto||0);
    let detalle = []; try { detalle = JSON.parse(chip.dataset.detalle || '[]'); } catch(e){}
    restaurarPool(bolsa, detalle);          // devuelve el saldo a las cuentas de la bolsa
    dispBolsa[bolsa] = (dispBolsa[bolsa]||0) + monto;
    chip.remove();
    fmtBolsaDisp(bolsa);
    recalc(cod);
}

function aplicarTodo(){
    // "Aplicar todo el pendiente" = llenar cada cuenta con su saldo pendiente completo
    // (el max del input). Operaciones ajusta desde ahí.
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.dataset.bloqueado === '1') return; // sin ingreso: no se toca
        inp.value = Math.round(parseFloat(inp.max || 0));
    });
    remarcarAplicar();
    for (const cod in DATOS) { recalc(cod); }
}

function ponerEnCero(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.dataset.bloqueado === '1') return; // solo obras con ingreso
        inp.value = 0;
    });
    remarcarAplicar();
    for (const cod in DATOS) { recalc(cod); }
}

/* Solicita autorización de gerencia para un proyecto sin ingreso.
   Se hace con un form dinámico para no anidar formularios dentro de #form-dist. */
/* ===== Cálculo automático de costo sugerido ===== */
function abrirCalculo(){ document.getElementById('modal-calc').style.display='flex'; }
function cerrarCalculo(){ document.getElementById('modal-calc').style.display='none'; }
function cerrarAlerta(){ document.getElementById('modal-alerta').style.display='none'; }

/* Llena los inputs de una obra hasta 'objetivo', consumiendo de MÁS ANTIGUO a
   MÁS NUEVO (data-periodo asc). Cada input se llena hasta su pendiente (max). */
function llenarFifoObra(cod, objetivo){
    const inputs = [...document.querySelectorAll('#card-'+cod+' input[data-tipo="aplicar"]')]
        .filter(i => i.dataset.bloqueado !== '1')
        .sort((a,b) => (parseInt(a.dataset.periodo||0,10) - parseInt(b.dataset.periodo||0,10)));
    let rem = Math.max(0, objetivo);
    inputs.forEach(inp => {
        const max = parseFloat(inp.max || 0);
        const v = Math.min(max, rem);
        inp.value = Math.round(v);
        rem -= v;
    });
}

function distribuirEnObra(cod, objetivo){
    // Tope de facturación del mes: el objetivo nunca supera lo facturable.
    const cap = (DATOS[cod] && DATOS[cod].cap != null) ? DATOS[cod].cap : Infinity;
    llenarFifoObra(cod, Math.min(objetivo, cap));
}

function ejecutarCalculo(){
    let tol = parseFloat(document.getElementById('tol-input').value);
    if(isNaN(tol) || tol < 0) tol = 3;

    const enPerdida = [];
    const bajoOfertado = [];

    for(const cod in DATOS){
        const d = DATOS[cod];
        if (d.sinIngreso) continue; // el cálculo sugerido solo aplica a obras con ingreso
        const inputs = document.querySelectorAll('#card-'+cod+' input[data-tipo="aplicar"]');

        // Margen acumulado actual (control): ingreso vs costo cuenta 6 acumulado
        const mcAcum = d.ingAcum>0 ? (d.ingAcum - d.costoAcum)/d.ingAcum*100 : null;
        // Margen proyectado (oferta vs realidad)
        const mcProy = (d.mcProy !== null && d.mcProy !== undefined) ? d.mcProy : null;
        const of  = (d.of !== null && d.of !== undefined) ? d.of : null;
        // Piso: ofertado − tolerancia. Sin oferta cargada, protegemos contra pérdida (piso 0).
        const piso = of !== null ? (of - tol) : 0;

        // 1) En pérdida (algún margen negativo): no aplicar
        const enPerd = (mcProy !== null && mcProy < 0) || (mcAcum !== null && mcAcum < 0);
        if(enPerd){
            inputs.forEach(i => i.value = 0);
            enPerdida.push({cod, nombre:d.nombre, margen:(mcProy !== null ? mcProy : mcAcum)});
            recalc(cod);
            continue;
        }

        // 2) Ya viene por debajo del piso (acumulado o proyección): no aplicar, mostrar
        const yaBajo = (mcAcum !== null && mcAcum < piso) || (mcProy !== null && mcProy < piso);
        if(of !== null && yaBajo){
            inputs.forEach(i => i.value = 0);
            bajoOfertado.push({cod, nombre:d.nombre, margen:(mcProy !== null ? mcProy : mcAcum), ofertado:of});
            recalc(cod);
            continue;
        }

        // 3) Aplicar hasta que el margen acumulado llegue al piso:
        //    costo tal que (ingAcum − (costoAcum + X)) / ingAcum = piso/100
        //    X = ingAcum*(1 − piso/100) − costoAcum   (menos las provisiones ya puestas)
        let objetivo = d.ingAcum>0 ? (d.ingAcum*(1 - piso/100) - d.costoAcum) : 0;
        objetivo -= sumProv(cod);
        if(objetivo < 0) objetivo = 0;

        distribuirEnObra(cod, objetivo);
        recalc(cod);
    }

    remarcarAplicar();
    cerrarCalculo();
    mostrarAlerta(enPerdida, bajoOfertado);
}

function mostrarAlerta(perdida, bajo){
    ultimaAlerta = { perdida, bajo, periodo: PERIODO };

    let html = '';

    if(perdida.length === 0 && bajo.length === 0){
        html += '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;font-size:13px;color:#15803D">✓ Se calculó el costo sugerido en todas las obras dentro de la tolerancia. Revisa los valores y guarda cuando estés de acuerdo.</div>';
    } else {
        html += '<div style="background:#EEF2FF;border:1px solid #C7D2FE;border-radius:8px;padding:10px 14px;font-size:13px;color:#4338CA;margin-bottom:12px">Se calculó el costo sugerido. Revisa estos casos antes de guardar:</div>';
    }

    if(perdida.length){
        html += '<div style="font-size:12px;font-weight:700;color:#DC2626;margin-bottom:6px">⚠ En pérdida — no se aplicaron costos ('+perdida.length+')</div>';
        html += '<div style="border:1px solid #FECACA;border-radius:8px;overflow:hidden;margin-bottom:14px">';
        perdida.forEach((o,ix) => {
            html += '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 12px;font-size:12px;'+(ix%2?'background:#FEF2F2':'background:white')+'">'
                 +  '<span><b>'+o.cod+'</b> · '+(o.nombre||'')+'</span>'
                 +  '<span style="color:#DC2626;font-weight:600;white-space:nowrap">'+o.margen.toFixed(1)+'%</span></div>';
        });
        html += '</div>';
    }

    if(bajo.length){
        html += '<div style="font-size:12px;font-weight:700;color:#B45309;margin-bottom:6px">Por debajo del piso ofertado — no se aplicaron costos ('+bajo.length+')</div>';
        html += '<div style="border:1px solid #FDE68A;border-radius:8px;overflow:hidden">';
        bajo.forEach((o,ix) => {
            html += '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 12px;font-size:12px;'+(ix%2?'background:#FFFBEB':'background:white')+'">'
                 +  '<span><b>'+o.cod+'</b> · '+(o.nombre||'')+'</span>'
                 +  '<span style="white-space:nowrap;color:#B45309;font-weight:600">'+o.margen.toFixed(1)+'% <span style="color:#9CA3AF;font-weight:400">vs '+o.ofertado.toFixed(1)+'% ofertado</span></span></div>';
        });
        html += '</div>';
    }

    document.getElementById('alerta-contenido').innerHTML = html;

    const btnExp = document.getElementById('btn-export-alerta');
    if(btnExp) btnExp.style.display = (perdida.length || bajo.length) ? 'inline-block' : 'none';

    document.getElementById('modal-alerta').style.display = 'flex';
}

function exportarAlerta(){
    const { perdida, bajo, periodo } = ultimaAlerta;
    if(perdida.length === 0 && bajo.length === 0){
        alert('No hay obras para revisar en este cálculo.');
        return;
    }

    const filas = [];
    filas.push(['Código', 'Nombre obra', 'Motivo', 'Margen', 'Ofertado', 'Período']);

    perdida.forEach(o => {
        filas.push([
            o.cod,
            o.nombre || '',
            'En pérdida (no se aplicaron costos)',
            o.margen.toFixed(1).replace('.', ',') + '%',
            '',
            periodo
        ]);
    });

    bajo.forEach(o => {
        filas.push([
            o.cod,
            o.nombre || '',
            'Por debajo del piso ofertado (no se aplicaron costos)',
            o.margen.toFixed(1).replace('.', ',') + '%',
            o.ofertado.toFixed(1).replace('.', ',') + '%',
            periodo
        ]);
    });

    const esc = v => {
        v = String(v == null ? '' : v);
        if(/[";\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
        return v;
    };
    const csv = filas.map(f => f.map(esc).join(';')).join('\r\n');

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'obras_por_revisar_' + String(periodo || '').replace(/\s+/g, '_') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function bloquearForm(){
    const f=document.getElementById('form-dist'); if(!f) return;
    f.querySelectorAll('input, select, button, textarea').forEach(e=>{ e.disabled=true; });
}

/* ═══════════ Mano de obra directa: asignación DENTRO de cada obra destino ═══════════ */
let moSeqG = 500000;
const escMo = (s) => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const cssEsc = (s) => (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
window.MO_PERSONAS_DEP = window.MO_PERSONAS_DEP || {};

// Suma de la MO directa aplicada a una obra (líneas ocultas, en dígitos limpios).
function sumMoObra(cod){
    const card = document.getElementById('card-'+cod); if(!card) return 0;
    let s = 0; card.querySelectorAll('.mo-obra-sec [data-mo-monto]').forEach(i => s += soloDigitos(i.value));
    return s;
}
// Aplicado de un tercero en TODAS las obras (para el tope "por aplicar").
function moAplicadoDe(ced){
    let s = 0;
    document.querySelectorAll('#form-dist .mo-obra-sec [data-mo-monto][data-mo-ced="'+cssEsc(ced)+'"]').forEach(i => s += soloDigitos(i.value));
    return s;
}
// Formatea el campo Monto del control con separador de miles (sin recalcular).
function moFmtMonto(el){ const n = soloDigitos(el.value); el.value = n ? n.toLocaleString('es-CO') : ''; }

// Refresca el resumen global "MO por aplicar" y los desplegables (solo terceros con saldo).
function moRecalcGlobal(){
    const aplic = {};
    document.querySelectorAll('#form-dist .mo-obra-sec [data-mo-monto]').forEach(i => {
        const c = i.dataset.moCed; aplic[c] = (aplic[c]||0) + soloDigitos(i.value);
    });
    document.querySelectorAll('.mo-persona').forEach(p => {
        const rest = Math.round((parseFloat(p.dataset.total)||0) - (aplic[p.dataset.ced]||0));
        const el = p.querySelector('[data-mo-rest]');
        if (el) { el.textContent = fmt(rest); el.style.color = rest < -0.5 ? '#DC2626' : '#15803D'; }
    });
    document.querySelectorAll('.mo-obra-sec').forEach(sec => {
        const sel = sec.querySelector('[data-mo-sel]'); if(!sel) return;
        const pers = (window.MO_PERSONAS_DEP[sec.dataset.dep]) || {};
        let opts = '<option value="">— Elegir tercero —</option>';
        Object.keys(pers).forEach(ced => {
            const rest = Math.round((pers[ced].total||0) - (aplic[ced]||0));
            if (rest <= 0) return;
            opts += '<option value="'+escMo(ced)+'">'+escMo(pers[ced].nom||ced)+' (queda '+fmt(rest)+')</option>';
        });
        sel.innerHTML = opts;
    });
}

function crearMoLine(cod, ced, nom, monto){
    const j = moSeqG++;
    const d = document.createElement('div');
    d.className = 'mo-line'; d.dataset.moCed = ced;
    d.style.cssText = 'font-size:11px;padding:5px 8px;background:#ECFEFF;border:1px solid #A5F3FC;border-radius:6px;margin-top:4px;display:flex;align-items:center;gap:8px';
    d.innerHTML =
        '<span style="flex:1;color:#155E75">🡒 <b>'+escMo(nom)+'</b></span>'+
        '<b data-mo-val style="color:#155E75">'+fmt(monto)+'</b>'+
        '<a href="#" onclick="quitarMo(this,\''+escMo(cod)+'\');return false" style="color:#DC2626;text-decoration:none">✕</a>'+
        '<input type="hidden" name="mano_obra['+escMo(cod)+'][j'+j+'][cedula]" value="'+escMo(ced)+'">'+
        '<input type="hidden" name="mano_obra['+escMo(cod)+'][j'+j+'][nombre]" value="'+escMo(nom)+'">'+
        '<input type="hidden" name="mano_obra['+escMo(cod)+'][j'+j+'][monto]" value="'+Math.round(monto)+'" data-mo-monto data-mo-ced="'+escMo(ced)+'">';
    return d;
}

function moDirty(){ const f = document.getElementById('form-dist'); if(f) f.dispatchEvent(new Event('input', {bubbles:true})); }

window.asignarMo = function(cod){
    const sec = document.querySelector('.mo-obra-sec[data-cod="'+cssEsc(cod)+'"]'); if(!sec) return;
    const dep = sec.dataset.dep;
    const sel = sec.querySelector('[data-mo-sel]'); const ced = sel.value;
    if(!ced){ alert('Elige un tercero con saldo por aplicar.'); return; }
    const montoEl = sec.querySelector('[data-mo-monto-in]');
    let v = soloDigitos(montoEl.value);
    if(v <= 0){ alert('Escribe el monto a aplicar.'); return; }
    const pers = ((window.MO_PERSONAS_DEP[dep])||{})[ced] || {total:0, nom:ced};
    const rest = Math.round((pers.total||0) - moAplicadoDe(ced));
    if(rest <= 0){ alert('Ese tercero ya no tiene saldo por aplicar.'); return; }
    if(v > rest) v = rest;   // tope = su saldo por aplicar
    const cont = sec.querySelector('[data-mo-lineas]');
    const linea = [...cont.querySelectorAll('.mo-line')].find(l => l.dataset.moCed === ced);
    if(linea){
        const mi = linea.querySelector('[data-mo-monto]'); const nv = soloDigitos(mi.value) + v;
        mi.value = String(nv); linea.querySelector('[data-mo-val]').textContent = fmt(nv);
    } else {
        cont.appendChild(crearMoLine(cod, ced, pers.nom||ced, v));
    }
    sel.value = ''; montoEl.value = '';
    recalc(cod); moRecalcGlobal(); moDirty();
};

window.quitarMo = function(a, cod){
    a.closest('.mo-line').remove();
    recalc(cod); moRecalcGlobal(); moDirty();
};

document.addEventListener('DOMContentLoaded', function(){
    for (const cod in DATOS) { evaluarCerrable(cod); }
    document.querySelectorAll('.mo-obra-sec').forEach(sec => { if (DATOS[sec.dataset.cod]) recalc(sec.dataset.cod); });
    moRecalcGlobal();
    refrescarDispObras();   // disponible por bolsa dentro de cada obra
    @if($bloqueado || !$puedeEditar) bloquearForm(); @endif

    // Enlaces por addEventListener (además de los inline): garantizan que el
    // buscador, expandir/colapsar y los grupos respondan aunque una CSP bloquee
    // los handlers inline o el navegador no dispare 'keyup'.
    function on(id, ev, fn){ const el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }
    on('buscador', 'input', function(){ filtrarObras(this.value); });
    on('btn-expandir', 'click', function(){ expandirTodo(true); });
    on('btn-colapsar', 'click', function(){ expandirTodo(false); });
    on('grupo-head-con', 'click', function(){ toggleGrupo('con'); });
    on('grupo-head-sin', 'click', function(){ toggleGrupo('sin'); });
    document.querySelectorAll('[data-toggle-obra]').forEach(function(row){
        row.addEventListener('click', function(){ toggleObra(row.getAttribute('data-toggle-obra')); });
    });

    // Marcar en amarillo las celdas ya modificadas al cargar (borradores guardados).
    remarcarAplicar();
    document.querySelectorAll('input[data-tipo="bolsamonto"]').forEach(marcarTocadaBolsa);

    // Si llegan desde "Otros costos → Editar montos" con ?bolsa=XXX, abrir esa bolsa.
    const bolsaAbrir = new URLSearchParams(location.search).get('bolsa');
    if (bolsaAbrir) {
        const det = document.getElementById('bolsa-det-' + bolsaAbrir);
        if (det && (det.style.display === 'none' || !det.style.display)) toggleBolsaDetalle(bolsaAbrir);
        const box = document.getElementById('bolsa-box-' + bolsaAbrir);
        if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

{{-- ══════════ Autoguardado (red de seguridad) ══════════
     Mientras la persona edita, cada pocos segundos se guarda un BORRADOR REAL en el
     servidor (base de datos), de modo que si se daña el equipo o cierra sin guardar,
     el trabajo queda y se puede retomar desde "Mis distribuciones" en cualquier equipo.
     Además se deja una copia local (localStorage) como respaldo offline por si el
     servidor no responde, y se avisa al salir con cambios sin guardar. --}}
@if($puedeEditar)
<script>
(function () {
    const form = document.getElementById('form-dist');
    if (!form) return;

    const URL_GUARDAR = @json(route('operativo.distribucion.guardar'));
    // Clave del respaldo local por período + departamento + borrador.
    const KEY = 'secar_dist_draft_v1|{{ $mes }}|{{ $anio }}|{{ $depEfectivo ?? '' }}|{{ $distId ?? 'new' }}';

    let dirty = false, saving = false, submitting = false, tmr = null;

    const statusEl = document.getElementById('autosave-status');
    function setStatus(txt, color) { if (statusEl) { statusEl.textContent = txt; statusEl.style.color = color || '#9CA3AF'; } }

    // Respaldo local (ligero): montos "a aplicar" y estado por obra.
    function serializar() {
        const data = {};
        // Los montos "a aplicar" se guardan como número limpio (sin separador de miles).
        form.querySelectorAll('input[data-tipo="aplicar"], input[name^="estado_obra"]').forEach(el => {
            if (!el.name) return;
            data[el.name] = el.dataset.tipo === 'aplicar' ? String(soloDigitos(el.value)) : el.value;
        });
        return data;
    }
    function guardarLocal() { try { localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), data: serializar() })); } catch (e) {} }
    function tieneTrabajo(data) { return Object.keys(data || {}).some(k => k.indexOf('aplicar') === 0 && Number(data[k]) > 0); }

    // ── Autoguardado en el SERVIDOR (borrador real en la BD) ──
    function autoguardarServidor() {
        if (saving || submitting || !dirty) return;
        saving = true;
        setStatus('Autoguardando…', '#9CA3AF');
        const fd = new FormData(form);
        // El backend espera números limpios: quita el separador de miles de los montos "a aplicar".
        form.querySelectorAll('input[data-tipo="aplicar"]').forEach(i => { if (i.name) fd.set(i.name, String(soloDigitos(i.value))); });
        fd.set('auto', '1');
        fd.set('accion', 'guardar');
        fetch(URL_GUARDAR, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd,
            credentials: 'same-origin',
        }).then(r => r.ok ? r.json() : Promise.reject(r))
          .then(j => {
              if (!j || !j.ok) throw new Error('resp');
              // Reutilizar el mismo borrador en los próximos autoguardados y al guardar a mano.
              if (j.dist) {
                  const di = form.querySelector('input[name="dist"]');
                  if (di && !di.value) di.value = j.dist;
                  try {
                      const u = new URL(window.location.href);
                      if (!u.searchParams.get('dist')) { u.searchParams.set('dist', j.dist); history.replaceState(null, '', u.toString()); }
                  } catch (e) {}
              }
              dirty = false;
              try { localStorage.removeItem(KEY); } catch (e) {}   // el servidor ya lo tiene
              setStatus('✔ Autoguardado ' + (j.hora || ''), '#15803D');
          })
          .catch(() => { setStatus('⚠ Sin conexión: guardado solo en este equipo', '#B45309'); })
          .finally(() => { saving = false; });
    }

    function alCambiar() {
        dirty = true;
        guardarLocal();                                   // respaldo local inmediato
        setStatus('Cambios sin guardar…', '#B45309');
        clearTimeout(tmr);
        tmr = setTimeout(autoguardarServidor, 3000);      // autoguardado en servidor (debounce)
    }
    form.addEventListener('input', alCambiar);
    form.addEventListener('change', alCambiar);
    setInterval(autoguardarServidor, 25000);              // respaldo periódico

    // Guardar/Enviar a mano ya persiste en BD: limpiamos estado y respaldo local.
    // (El botón "Ver resumen" usa formaction/nuevo tab: no debe limpiar nada.)
    form.addEventListener('submit', function (e) {
        // El backend espera números limpios: quita el separador de miles antes de enviar y lo
        // restaura en pantalla (por si el envío abre en otra pestaña, como "Ver resumen").
        normalizarAplicar();
        setTimeout(reformatearAplicar, 0);
        const btn = e.submitter;
        if (btn && btn.name === 'accion') {
            submitting = true; dirty = false; clearTimeout(tmr);
            try { localStorage.removeItem(KEY); } catch (e) {}
        }
    });

    // Aviso al salir si quedan cambios sin persistir.
    window.addEventListener('beforeunload', function (e) {
        if (dirty && !submitting) { e.preventDefault(); e.returnValue = ''; return ''; }
    });

    // ── Respaldo local: si un autoguardado no alcanzó a subir, ofrecer recuperarlo ──
    let prev = null;
    try { prev = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
    if (prev && tieneTrabajo(prev.data)) mostrarBanner(prev);

    function fechaCorta(ts) {
        try { return new Date(ts).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
        catch (e) { return ''; }
    }
    function mostrarBanner(snap) {
        const bar = document.createElement('div');
        bar.style.cssText = 'position:sticky;top:0;z-index:120;background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:1rem;display:flex;align-items:center;gap:12px;flex-wrap:wrap';
        bar.innerHTML = '<span>💾 <b>Tienes cambios sin guardar</b> de ' + fechaCorta(snap.t) + ' en este equipo. ¿Recuperarlos?</span>'
            + '<span style="margin-left:auto;display:flex;gap:8px">'
            + '<button type="button" id="dr-rec" style="padding:6px 14px;border:none;border-radius:6px;background:#D97706;color:#fff;font-size:12px;font-weight:600;cursor:pointer">Recuperar</button>'
            + '<button type="button" id="dr-des" style="padding:6px 14px;border:1px solid #D97706;border-radius:6px;background:#fff;color:#B45309;font-size:12px;cursor:pointer">Descartar</button>'
            + '</span>';
        const cont = document.querySelector('.content');
        cont.insertBefore(bar, cont.firstChild);
        document.getElementById('dr-rec').addEventListener('click', function () { aplicarLocal(snap.data); bar.remove(); });
        document.getElementById('dr-des').addEventListener('click', function () {
            try { localStorage.removeItem(KEY); } catch (e) {} bar.remove();
        });
    }
    function aplicarLocal(data) {
        Object.keys(data).forEach(name => {
            const el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            el.value = data[name];
            // Si es el estado (input oculto), refleja el valor en el control segmentado.
            const mEstado = name.match(/^estado_obra\[(.+)\]$/);
            if (mEstado && typeof cambiarEstado === 'function') cambiarEstado(mEstado[1], data[name]);
        });
        if (typeof DATOS === 'object') { for (const cod in DATOS) { try { recalc(cod); } catch (e) {} } }
        if (typeof remarcarAplicar === 'function') remarcarAplicar();
        alCambiar();   // marca sucio y dispara autoguardado al servidor
    }
})();
</script>
@endif

<script>
// Sombra sutil del resumen flotante de cada tarjeta: se activa solo cuando el bloque queda pegado
// al tope del viewport (top <= 0), para que se note que flota. Throttle con requestAnimationFrame.
(function () {
    let pend = false;
    function marcarStuck() {
        pend = false;
        document.querySelectorAll('.obra-sticky').forEach(function (r) {
            r.classList.toggle('stuck', r.getBoundingClientRect().top <= 0);
        });
    }
    function onScroll() { if (!pend) { pend = true; requestAnimationFrame(marcarStuck); } }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    marcarStuck();
})();
</script>
@endsection