@extends('layouts.app')

@section('title', 'Distribución de costos')

@section('content')
@php
    $depLabel = ['mantenimiento' => 'Mantenimiento (MT)', 'instalaciones' => 'Instalaciones (IN)'];
    $depPrefijo = ['mantenimiento' => 'MT', 'instalaciones' => 'IN'];
@endphp
@php
    $puedeEditar = auth()->user()->puedeEditarModulo('operacion');
@endphp
<h1 class="page-title" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    Distribución de costos
    @if($depEfectivo)
        <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:10px;background:#EEF2FF;color:#4338CA">
            {{ $depLabel[$depEfectivo] ?? $depEfectivo }}
        </span>
    @elseif(is_null($depUsuario))
        <span style="font-size:12px;color:#9CA3AF;font-weight:400">— elige un departamento arriba para empezar —</span>
    @endif
</h1>

@if(!$puedeEditar)
<div style="background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;padding:9px 14px;font-size:12.5px;color:#6B7280;margin-bottom:1rem">
    👁 Modo solo lectura. Puedes consultar la distribución pero no guardar cambios.
</div>
@endif

<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('operativo.distribucion') }}" id="form-filtros" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        @if(is_null($depUsuario))
        {{-- Director/admin: elige el departamento. El supervisor no ve esto (ya está fijo). --}}
        {{-- Al cambiar el departamento el formulario se envía solo: así el servidor recalcula
             las opciones de "Tipo de obra" que corresponden a ese departamento. --}}
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Departamento</label>
            <select name="departamento" id="sel-departamento"
                onchange="cambiarDepartamento(this)"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                <option value="">— Elegir —</option>
                <option value="mantenimiento" {{ $depEfectivo == 'mantenimiento' ? 'selected' : '' }}>Mantenimiento</option>
                <option value="instalaciones" {{ $depEfectivo == 'instalaciones' ? 'selected' : '' }}>Instalaciones</option>
            </select>
        </div>
        @endif
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Vista</label>
            <select name="vista" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['todo'=>'Todo lo pendiente (cuenta 14)','mes'=>'Solo el mes seleccionado'] as $k => $v)
                    <option value="{{ $k }}" {{ $vista == $k ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Mes</label>
            <select name="mes" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'] as $i => $m)
                    <option value="{{ $i+1 }}" {{ ($i+1) == $mes ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Año</label>
            <select name="anio" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @for($y = env('ANIO_INICIO_SISTEMA', 2022); $y <= date('Y'); $y++)
                    <option value="{{ $y }}" {{ $y == $anio ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Tipo de obra</label>
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
            @endphp
            <select name="tipo" id="sel-tipo"
                {{ is_null($depUsuario) && !$depEfectivo ? 'disabled' : '' }}
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach($opcionesTipo as $k => $v)
                    <option value="{{ $k }}" {{ $tipoSel == $k ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:12px;color:#6B7280;display:block;margin-bottom:4px">Estado</label>
            <select name="estado" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['todos'=>'Todos','abierta'=>'Abiertas','parcial'=>'Parciales','cerrada'=>'Cerradas'] as $k => $v)
                    <option value="{{ $k }}" {{ $estadoFiltro == $k ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" style="padding:7px 20px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Filtrar</button>
    </form>
    <p style="font-size:11px;color:#9CA3AF;margin-top:8px">
        En vista <b>“Todo lo pendiente”</b> se muestran todas las obras con saldo abierto en la cuenta 14 (el mes/año solo afecta los números al corte).
        En vista <b>“Solo el mes seleccionado”</b> se muestran únicamente las obras con movimiento de cuenta 14 en ese mes y año.
    </p>
</div>

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

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:1rem">
    <div style="background:#FEF9C3;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#854D0E;margin-bottom:4px">Pendiente por distribuir</div>
        <div style="font-size:18px;font-weight:600;color:#854D0E">${{ number_format($kpiPendiente, 0, ',', '.') }}</div>
    </div>
    <div style="background:#F3F4F6;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#6B7280;margin-bottom:4px">Obras con pendiente</div>
        <div style="font-size:18px;font-weight:600;color:#1B3F6E">{{ $kpiObras }}</div>
    </div>
    <div style="background:#FEF2F2;border-radius:8px;padding:12px 16px">
        <div style="font-size:11px;color:#DC2626;margin-bottom:4px">Bajo margen ofertado</div>
        <div style="font-size:18px;font-weight:600;color:#DC2626">{{ $kpiAlertas }}</div>
    </div>
</div>

@if($bloqueado)
<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 14px;font-size:13px;color:#1B3F6E;margin-bottom:1rem">
    🔒 Período enviado a contabilidad{{ $envio && $envio->enviado_at ? ' el '.$envio->enviado_at->format('d/m/Y H:i') : '' }}. Solo lectura — contabilidad debe habilitar la edición para modificarlo.
</div>
@endif

@if($kpiObras > 0 && !$bloqueado && $puedeEditar)
<div style="display:flex;gap:10px;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
    <span style="font-size:12px;color:#6B7280">Acciones rápidas:</span>
    <button type="button" onclick="aplicarTodo()" style="font-size:12px;padding:6px 14px;border:1px solid #16A34A;border-radius:8px;background:white;color:#15803D;cursor:pointer">Aplicar todo el pendiente</button>
    <button type="button" onclick="ponerEnCero()" style="font-size:12px;padding:6px 14px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;cursor:pointer">Poner todo en 0</button>
    <button type="button" onclick="abrirCalculo()" style="font-size:12px;padding:6px 14px;border:1px solid #4338CA;border-radius:8px;background:#EEF2FF;color:#4338CA;font-weight:500;cursor:pointer">✨ Calcular costo sugerido</button>
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
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay obras con saldo en cuenta 14 para este período / filtro.</div>
@endif

<form method="POST" action="{{ route('operativo.distribucion.guardar') }}" id="form-dist">
@csrf
<input type="hidden" name="mes" value="{{ $mes }}">
<input type="hidden" name="anio" value="{{ $anio }}">
<input type="hidden" name="dist" value="{{ $distId }}">
<input type="hidden" name="departamento" value="{{ $depEfectivo }}">

@foreach($obras as $cod => $o)
@php $ce = $colEstado[$o['estado']] ?? ['#F3F4F6','#6B7280']; @endphp
<div class="card" id="card-{{ $cod }}" style="margin-bottom:10px;padding:0;overflow:hidden">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;cursor:pointer;flex-wrap:wrap" onclick="toggleObra('{{ $cod }}')">
        <div style="min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span id="dot-{{ $cod }}" style="width:9px;height:9px;border-radius:50%;background:{{ $colSem[$o['semaforo']] }};display:inline-block"></span>
                <span style="font-weight:600;color:#1B3F6E">{{ $cod }}</span>
                <select name="estado_obra[{{ $cod }}]" onclick="event.stopPropagation()" onchange="cambiarEstado('{{ $cod }}', this.value)"
                    style="font-size:11px;padding:3px 8px;border-radius:8px;border:1px solid {{ $ce[1] }};background:{{ $ce[0] }};color:{{ $ce[1] }};font-weight:500;cursor:pointer">
                    <option value="abierta" {{ $o['estado']=='abierta'?'selected':'' }}>Abierta</option>
                    <option value="parcial" {{ $o['estado']=='parcial'?'selected':'' }}>Parcial</option>
                    <option value="cerrada" {{ $o['estado']=='cerrada'?'selected':'' }}>Cerrada</option>
                </select>
                <span style="font-size:12px;color:#9CA3AF">{{ Str::limit($o['nombre'], 40) }}</span>
                @if($o['bloqueado_ingreso'])
                    <span onclick="event.stopPropagation()" style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEE2E2;color:#B91C1C">🔒 Sin ingreso{{ $o['autorizacion_estado'] === 'pendiente' ? ' · pendiente' : ($o['autorizacion_estado'] === 'rechazada' ? ' · rechazada' : '') }}</span>
                @elseif($o['autorizado'])
                    <span onclick="event.stopPropagation()" style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#DCFCE7;color:#15803D">✅ Autorizado</span>
                @endif
            </div>
            <div style="font-size:11px;color:#6B7280;margin-top:3px" id="metodo-{{ $cod }}">Método: {{ $o['metodo'] }}</div>
        </div>
        <div style="text-align:right;flex:none">
            <div style="font-size:11px;color:#854D0E">A aplicar</div>
            <div style="font-weight:600;color:#854D0E" id="aplicar-tot-{{ $cod }}">{{ $fmt($o['sum_aplicar'] + $o['sum_prov']) }}</div>
        </div>
    </div>

    {{-- ESTADO DE AVANCE DE OBRA (acumulado al mes anterior) --}}
    <div style="background:#F9FAFB;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#6B7280;letter-spacing:.4px;margin-bottom:6px">ESTADO DE AVANCE DE OBRA · ACUMULADO A {{ mb_strtoupper($mesAnteriorNombre) }}</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Facturado acum. reconocido</div>
                <div style="font-size:14px;font-weight:600;color:#1B3F6E">{{ $fmt($o['fact_acum_rec']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Costo acum. reconocido</div>
                <div style="font-size:14px;font-weight:600;color:#374151">{{ $fmt($o['costo_acum_rec']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Margen acum. ($)</div>
                <div style="font-size:14px;font-weight:600;color:{{ $o['margen_acum_pesos'] >= 0 ? '#15803D' : '#DC2626' }}">{{ $fmt($o['margen_acum_pesos']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">MC %</div>
                <div style="font-size:14px;font-weight:600;color:#374151">{{ $o['mc_pct_acum'] === null ? '—' : $o['mc_pct_acum'].'%' }}</div>
            </div>
        </div>
    </div>

    {{-- RENTABILIDAD DEL MES (costo del mes + MC dinámico al aplicar 14→6) --}}
    <div style="background:#FFFBEB;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#854D0E;letter-spacing:.4px;margin-bottom:6px">RENTABILIDAD DEL MES · {{ mb_strtoupper($mesNombre) }}</div>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Ingresos del mes</div>
                <div style="font-size:14px;font-weight:600;color:#854D0E">{{ $fmt($o['ingreso_mes']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Costo del mes (cuenta 6)</div>
                <div style="font-size:14px;font-weight:600;color:#374151">{{ $fmt($o['costo_mes_c6']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Aplicado ahora (14→6)</div>
                <div style="font-size:14px;font-weight:600;color:#1B3F6E" id="aplic6-{{ $cod }}">{{ $fmt($o['aplicado_mes']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">MC del mes ($)</div>
                <div style="font-size:14px;font-weight:600;color:{{ $o['mc_mes_pesos'] >= 0 ? '#15803D' : '#DC2626' }}" id="mcmes-{{ $cod }}">{{ $fmt($o['mc_mes_pesos']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Rentabilidad % MC</div>
                <div style="font-size:14px;font-weight:600;color:#854D0E" id="mcpct-{{ $cod }}">{{ $o['mc_mes_pct'] === null ? '—' : $o['mc_mes_pct'].'%' }}</div>
            </div>
        </div>
    </div>

    {{-- PROYECCIÓN DE RENTABILIDAD (oferta comercial vs realidad) --}}
    <div style="background:#EEF2FF;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#4338CA;letter-spacing:.4px;margin-bottom:6px">PROYECCIÓN DE RENTABILIDAD · OFERTA vs REALIDAD</div>
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Valor oferta comercial</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $fmt($o['pr_valor_oferta']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Diferencia por facturar</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $fmt($o['pr_dif_facturar']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Inventario en obra (cta 14)</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $fmt($o['pr_inv_obra']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Inventario almacén <i>(en actualización)</i></div>
                <div style="font-size:14px;font-weight:600;color:#9CA3AF">{{ $fmt($o['pr_inv_almacen']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Costo total</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $fmt($o['pr_costo_total']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">MC % ofertado</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $o['pr_mc_ofertado'] === null ? '—' : $o['pr_mc_ofertado'].'%' }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">MC % proyección <i>(costo acum + inventarios)</i></div>
                @php
                    $mcp = $o['pr_mc_proy']; $ofc = $o['pr_mc_ofertado'];
                    $colProy = '#312E81';
                    if ($mcp !== null && $ofc !== null) $colProy = $mcp >= $ofc ? '#15803D' : '#DC2626';
                @endphp
                <div style="font-size:14px;font-weight:600;color:{{ $colProy }}">{{ $mcp === null ? '—' : $mcp.'%' }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Avance de facturación</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $o['pr_avance_fact'] === null ? '—' : $o['pr_avance_fact'].'%' }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Costo presupuestado</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $fmt($o['pr_costo_presup']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#6366F1;line-height:1.3">Avance ejecución obra</div>
                <div style="font-size:14px;font-weight:600;color:#312E81">{{ $o['pr_avance_ejec'] === null ? '—' : $o['pr_avance_ejec'].'%' }}</div>
            </div>
        </div>
    </div>

    {{-- OBSERVACIONES DEL COORDINADOR --}}
    <div style="padding:10px 16px;border-top:1px solid #E5E7EB">
        <label style="font-size:9px;font-weight:700;color:#6B7280;letter-spacing:.4px;display:block;margin-bottom:5px">OBSERVACIONES DEL COORDINADOR</label>
        <textarea name="observacion[{{ $cod }}]" rows="2" placeholder="Escribe aquí cualquier observación sobre esta obra (opcional)..."
            style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;font-family:inherit;resize:vertical">{{ $o['observacion'] }}</textarea>
    </div>

    <div style="padding:12px 16px">
        <div style="display:flex;height:8px;border-radius:4px;overflow:hidden;background:#F3F4F6">
            @foreach($categorias as $k => $label)
                @php $v = $o['cat'][$k]['pendiente']; @endphp
                @if($v > 0 && $o['total_pendiente'] > 0)
                    <div style="width:{{ round($v / $o['total_pendiente'] * 100, 2) }}%;background:{{ $colCat[$k] }};height:100%"></div>
                @endif
            @endforeach
        </div>
    </div>

    <div id="obra-{{ $cod }}" style="display:none;padding:0 16px 14px;border-top:1px solid #F3F4F6">
        @if($o['bloqueado_ingreso'])
        <div style="margin-top:12px;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:12px 14px">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
                <span style="font-size:12px;font-weight:700;color:#B91C1C">🔒 Sin ingreso en el mes — requiere autorización</span>
                @if($o['autorizacion_estado'] === 'pendiente')
                    <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEF9C3;color:#854D0E">Solicitud pendiente</span>
                @elseif($o['autorizacion_estado'] === 'rechazada')
                    <span style="font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;background:#FEE2E2;color:#B91C1C">Rechazada</span>
                @endif
            </div>
            <p style="font-size:11.5px;color:#7F1D1D;line-height:1.5;margin:0 0 8px">
                Este proyecto no tuvo ingreso en el período, por lo que sus valores a distribuir quedan en 0 y bloqueados.
                Solo se le puede cargar costo con una autorización de gerencia aprobada.
            </p>
            @if($o['autorizacion_estado'] === 'pendiente')
                <div style="font-size:11px;color:#6B7280">Motivo enviado: <i>{{ $o['autorizacion_motivo'] }}</i></div>
            @else
                @if($o['autorizacion_estado'] === 'rechazada' && $o['autorizacion_coment'])
                    <div style="font-size:11px;color:#B91C1C;margin-bottom:8px">Comentario de gerencia: <i>{{ $o['autorizacion_coment'] }}</i></div>
                @endif
                @if($puedeEditar)
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
                    <textarea id="motivo-aut-{{ $cod }}" rows="2" placeholder="Motivo de la solicitud (obligatorio)..."
                        style="flex:1;min-width:220px;padding:7px 10px;border:1px solid #FCA5A5;border-radius:6px;font-size:12px;font-family:inherit;resize:vertical"></textarea>
                    <button type="button" onclick="solicitarAut('{{ $cod }}')"
                        style="padding:8px 14px;background:#B91C1C;color:white;border:none;border-radius:6px;font-size:12px;cursor:pointer;white-space:nowrap">Solicitar autorización</button>
                </div>
                @endif
            @endif
        </div>
        @elseif($o['autorizado'])
        <div style="margin-top:12px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:8px 12px;font-size:11.5px;color:#15803D">
            ✅ Autorizado por gerencia para distribuir costos este mes.
        </div>
        @endif
        @foreach($categorias as $k => $label)
            @php $c = $o['cat'][$k]; @endphp
            @if($c['pendiente'] > 0)
            <div style="margin-top:12px">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                    <span style="width:9px;height:9px;border-radius:2px;background:{{ $colCat[$k] }};display:inline-block"></span>
                    <span style="font-size:12px;font-weight:600">{{ $label }}</span>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:11px">
                    <tr style="color:#9CA3AF">
                        <td style="padding:3px 6px">Cuenta 14</td><td></td>
                        <td style="padding:3px 6px">Cuenta 61</td>
                        <td style="padding:3px 6px">Concepto</td>
                        <td style="padding:3px 6px;text-align:right">Pendiente</td>
                        <td style="padding:3px 6px;text-align:right">A aplicar</td>
                    </tr>
                    @foreach($c['subs'] as $sub)
                    @if($sub['pendiente'] > 0)
                    <tr style="border-top:1px solid #F3F4F6">
                        <td style="padding:4px 6px;font-family:monospace;color:#9CA3AF">{{ $sub['cuenta_14'] }}</td>
                        <td style="padding:4px 6px;text-align:center;color:#D1D5DB">→</td>
                        <td style="padding:4px 6px;font-family:monospace;color:{{ $sub['cuenta_61'] === 'SIN HOMOLOGAR' ? '#DC2626' : '#1B3F6E' }}">{{ $sub['cuenta_61'] }}</td>
                        <td style="padding:4px 6px;color:#6B7280">{{ Str::limit($sub['nombre'], 26) }}</td>
                        <td style="padding:4px 6px;text-align:right;color:#854D0E">{{ $fmt($sub['pendiente']) }}</td>
                        <td style="padding:4px 6px;text-align:right">
                            <input type="number" min="0" max="{{ round($sub['pendiente']) }}" step="1"
                                value="{{ $o['bloqueado_ingreso'] ? 0 : round($sub['aplicar']) }}"
                                name="aplicar[{{ $cod }}][{{ $sub['cuenta_14'] }}]"
                                data-cod="{{ $cod }}" data-tipo="aplicar"
                                oninput="capear(this);recalc('{{ $cod }}')"
                                {{ $o['bloqueado_ingreso'] ? 'disabled title=Requiere-autorizacion-de-gerencia' : '' }}
                                style="width:100px;padding:3px 6px;border:1px solid #E5E7EB;border-radius:4px;font-size:11px;text-align:right{{ $o['bloqueado_ingreso'] ? ';background:#F3F4F6;color:#9CA3AF;cursor:not-allowed' : '' }}">
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </table>
            </div>
            @endif
        @endforeach

        <div style="margin-top:14px;border-top:1px dashed #E5E7EB;padding-top:10px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <span style="font-size:12px;font-weight:600;color:#854D0E">Provisiones (costo en tránsito)</span>
                @if($o['bloqueado_ingreso'])
                <button type="button" disabled title="Requiere autorización de gerencia" style="font-size:11px;padding:4px 10px;border:1px solid #E5E7EB;border-radius:6px;background:#F3F4F6;color:#9CA3AF;cursor:not-allowed">+ Provisión</button>
                @else
                <button type="button" onclick="toggleProvForm('{{ $cod }}')" style="font-size:11px;padding:4px 10px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;cursor:pointer">+ Provisión</button>
                @endif
            </div>

            <div id="provs-{{ $cod }}">
                @foreach($o['provisiones'] as $i => $pr)
                <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;padding:4px 6px;background:#FFFBEB;border-radius:6px;margin-top:4px">
                    <span>➕ <span style="font-family:monospace">{{ $pr['cuenta_14'] }}</span> → <span style="font-family:monospace">{{ $pr['cuenta_61'] }}</span> · {{ Str::limit($pr['nombre'], 24) }}{{ $pr['descripcion'] ? ' · '.$pr['descripcion'] : '' }}</span>
                    <span style="display:flex;align-items:center;gap:8px"><b>{{ $fmt($pr['monto']) }}</b>
                        <a href="#" onclick="this.closest('div').remove();recalc('{{ $cod }}');return false" style="color:#DC2626;text-decoration:none">✕</a></span>
                    <input type="hidden" name="provision[{{ $cod }}][s{{ $i }}][cuenta]" value="{{ $pr['cuenta_14'] }}">
                    <input type="hidden" name="provision[{{ $cod }}][s{{ $i }}][monto]" value="{{ round($pr['monto']) }}" data-cod="{{ $cod }}" data-tipo="prov">
                    <input type="hidden" name="provision[{{ $cod }}][s{{ $i }}][desc]" value="{{ $pr['descripcion'] }}">
                </div>
                @endforeach
            </div>

            <div id="provform-{{ $cod }}" style="display:none;margin-top:8px;background:#F9FAFB;border-radius:8px;padding:10px">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                    <div style="flex:2;min-width:180px">
                        <label style="font-size:10px;color:#6B7280;display:block">Cuenta 14</label>
                        <select id="prov-cta-{{ $cod }}" style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                            @foreach($catalogo as $cat)
                                <option value="{{ $cat->cuenta_14 }}">{{ $cat->cuenta_14 }} · {{ Str::limit($cat->nombre, 28) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:1;min-width:110px">
                        <label style="font-size:10px;color:#6B7280;display:block">Monto</label>
                        <input type="number" id="prov-monto-{{ $cod }}" min="0" step="1" placeholder="0" style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                    </div>
                    <div style="flex:2;min-width:140px">
                        <label style="font-size:10px;color:#6B7280;display:block">Descripción</label>
                        <input type="text" id="prov-desc-{{ $cod }}" placeholder="Factura pendiente..." style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                    </div>
                    <button type="button" onclick="addProv('{{ $cod }}')" style="padding:7px 14px;background:#1B3F6E;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">Agregar</button>
                </div>
            </div>
        </div>

        @if($o['total_reversado'] > 0)
        <div style="margin-top:12px;background:#FEF2F2;border-radius:8px;padding:8px 12px;font-size:11px;color:#DC2626">
            ⚠ Reversado de más (alerta, no editable): {{ $fmt($o['total_reversado']) }}
        </div>
        @endif
    </div>
</div>
@endforeach

@if($kpiObras > 0 && !$bloqueado)
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:1rem">
    <button type="submit" formaction="{{ route('operativo.distribucion.resumen') }}" formtarget="_blank" style="padding:9px 22px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:8px;font-size:13px;cursor:pointer">📄 Ver resumen</button>
    @if($puedeEditar)
    <button type="submit" name="accion" value="guardar" style="padding:9px 22px;background:#6B7280;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">{{ $distId ? 'Guardar cambios' : 'Guardar borrador' }}</button>
    <button type="submit" name="accion" value="enviar" onclick="return confirm('¿Enviar toda la distribución del mes a contabilidad? La hoja quedará en solo lectura.')" style="padding:9px 22px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Enviar a contabilidad</button>
    @endif
</div>
@endif
</form>

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
            'nombre'   => $o['nombre'],
            'pend'     => $o['total_pendiente'],
            'rev'      => $o['total_reversado'],
        ];
    }
@endphp
<script>
const CTA = @json($ctaJs);
const DATOS = @json($datosJs);
const PERIODO = @json($mesNombre);
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

function toggleObra(cod){ const e=document.getElementById('obra-'+cod); if(e) e.style.display = e.style.display==='none'?'block':'none'; }
function toggleProvForm(cod){ const e=document.getElementById('provform-'+cod); e.style.display = e.style.display==='none'?'block':'none'; }
function capear(inp){ const max=parseFloat(inp.max||0); let v=parseFloat(inp.value||0); if(v>max){inp.value=Math.round(max);} if(v<0){inp.value=0;} }

function sumAplicar(cod){
    const card=document.getElementById('card-'+cod); let s=0;
    card.querySelectorAll('input[data-tipo="aplicar"]').forEach(i=>s+=parseFloat(i.value||0));
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
    const sel=document.querySelector('select[name="estado_obra['+cod+']"]'); if(!sel) return;
    const opt=sel.querySelector('option[value="cerrada"]');
    const ok=esCerrable(cod);
    if(opt) opt.disabled=!ok;
    if(!ok && sel.value==='cerrada'){ sel.value='parcial'; cambiarEstado(cod,'parcial'); }
}

function recalc(cod){
    const card=document.getElementById('card-'+cod);
    let sumA=0, sumP=0;
    card.querySelectorAll('input[data-tipo="aplicar"]').forEach(i=>sumA+=parseFloat(i.value||0));
    card.querySelectorAll('input[data-tipo="prov"]').forEach(i=>sumP+=parseFloat(i.value||0));
    const d=DATOS[cod]; if(!d) return;
    const aplicado = sumA + sumP;

    const tot=document.getElementById('aplicar-tot-'+cod); if(tot) tot.textContent=fmt(aplicado);
    const a6=document.getElementById('aplic6-'+cod); if(a6) a6.textContent=fmt(aplicado);

    evaluarCerrable(cod);

    const costoMesTotal = (d.costoMes||0) + aplicado;
    const mcMesPesos = (d.ingMes||0) - costoMesTotal;
    const mcEl=document.getElementById('mcmes-'+cod);
    if(mcEl){ mcEl.textContent=fmt(mcMesPesos); mcEl.style.color = mcMesPesos>=0 ? '#15803D' : '#DC2626'; }
    const pctEl=document.getElementById('mcpct-'+cod);
    if(pctEl){ pctEl.textContent = d.ingMes ? ((mcMesPesos/d.ingMes*100).toFixed(1)+'%') : '—'; }
}

function cambiarEstado(cod, val){
    if(val==='cerrada' && !esCerrable(cod)){
        alert('No puedes cerrar esta obra: aún tiene saldo abierto en la cuenta 14. Aplica todo el pendiente (o resuelve el reversado) para poder cerrarla.');
        const s=document.querySelector('select[name="estado_obra['+cod+']"]'); if(s) s.value='parcial'; val='parcial';
    }
    const m=document.getElementById('metodo-'+cod);
    if(m) m.textContent='Método: '+(val==='abierta'?'Reclasificar OT áreas → OT operación':'Cuenta 14 → 61');
    const sel=document.querySelector('select[name="estado_obra['+cod+']"]');
    if(sel && ESTCOL[val]){ sel.style.background=ESTCOL[val][0]; sel.style.color=ESTCOL[val][1]; sel.style.borderColor=ESTCOL[val][1]; }
}

function addProv(cod){
    const c14=document.getElementById('prov-cta-'+cod).value;
    const monto=parseFloat(document.getElementById('prov-monto-'+cod).value||0);
    const desc=document.getElementById('prov-desc-'+cod).value||'';
    if(!c14||monto<=0){ alert('Elige cuenta y un monto mayor a 0.'); return; }
    const info=CTA[c14]||{cuenta_61:'?',nombre:''};
    provIdx[cod]=(provIdx[cod]||0)+1; const i='n'+provIdx[cod];
    const div=document.createElement('div');
    div.style.cssText='display:flex;align-items:center;justify-content:space-between;font-size:11px;padding:4px 6px;background:#FFFBEB;border-radius:6px;margin-top:4px';
    div.innerHTML=`<span>➕ <span style="font-family:monospace">${c14}</span> → <span style="font-family:monospace">${info.cuenta_61}</span> · ${info.nombre||''} ${desc?('· '+desc):''}</span>
        <span style="display:flex;align-items:center;gap:8px"><b>${fmt(monto)}</b>
        <a href="#" onclick="this.closest('div').remove();recalc('${cod}');return false" style="color:#DC2626;text-decoration:none">✕</a></span>
        <input type="hidden" name="provision[${cod}][${i}][cuenta]" value="${c14}">
        <input type="hidden" name="provision[${cod}][${i}][monto]" value="${Math.round(monto)}" data-cod="${cod}" data-tipo="prov">
        <input type="hidden" name="provision[${cod}][${i}][desc]" value="${desc}">`;
    document.getElementById('provs-'+cod).appendChild(div);
    document.getElementById('prov-monto-'+cod).value='';
    document.getElementById('prov-desc-'+cod).value='';
    recalc(cod);
}

function aplicarTodo(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.disabled) return; // proyecto bloqueado por falta de ingreso
        inp.value = Math.round(parseFloat(inp.max || 0));
    });
    for (const cod in DATOS) { recalc(cod); }
}

function ponerEnCero(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.disabled) return;
        inp.value = 0;
    });
    for (const cod in DATOS) { recalc(cod); }
}

/* Solicita autorización de gerencia para un proyecto sin ingreso.
   Se hace con un form dinámico para no anidar formularios dentro de #form-dist. */
function solicitarAut(cod){
    const ta = document.getElementById('motivo-aut-' + cod);
    const motivo = (ta ? ta.value : '').trim();
    if (!motivo) { alert('Escribe el motivo de la solicitud.'); if (ta) ta.focus(); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = @json(route('operativo.autorizaciones.solicitar'));
    f.style.display = 'none';
    f.innerHTML = '<input type="hidden" name="_token" value="' + @json(csrf_token()) + '">'
        + '<input type="hidden" name="codigo_proyecto">'
        + '<input type="hidden" name="mes" value="{{ $mes }}">'
        + '<input type="hidden" name="anio" value="{{ $anio }}">'
        + '<input type="hidden" name="motivo">';
    f.querySelector('[name="codigo_proyecto"]').value = cod;
    f.querySelector('[name="motivo"]').value = motivo;
    document.body.appendChild(f);
    f.submit();
}

/* ===== Cálculo automático de costo sugerido ===== */
function abrirCalculo(){ document.getElementById('modal-calc').style.display='flex'; }
function cerrarCalculo(){ document.getElementById('modal-calc').style.display='none'; }
function cerrarAlerta(){ document.getElementById('modal-alerta').style.display='none'; }

function distribuirEnObra(cod, objetivo){
    const inputs=[...document.querySelectorAll('#card-'+cod+' input[data-tipo="aplicar"]')].filter(i=>!i.disabled);
    const maxes=inputs.map(i=>parseFloat(i.max||0));
    const totalMax=maxes.reduce((a,b)=>a+b,0);
    if(totalMax<=0){ inputs.forEach(i=>i.value=0); return; }
    const obj=Math.max(0, Math.min(objetivo, totalMax));
    inputs.forEach((inp,idx)=>{
        const share = totalMax>0 ? (maxes[idx]/totalMax)*obj : 0;
        inp.value = Math.min(Math.round(share), Math.round(maxes[idx]));
    });
}

function ejecutarCalculo(){
    let tol = parseFloat(document.getElementById('tol-input').value);
    if(isNaN(tol) || tol < 0) tol = 3;

    const enPerdida = [];
    const bajoOfertado = [];

    for(const cod in DATOS){
        const d = DATOS[cod];
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

document.addEventListener('DOMContentLoaded', function(){
    for (const cod in DATOS) { evaluarCerrable(cod); }
    @if($bloqueado || !$puedeEditar) bloquearForm(); @endif
});
</script>
@endsection