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

@if($bloqueado)
<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 14px;font-size:13px;color:#1B3F6E;margin-bottom:1rem">
    🔒 Período enviado a contabilidad{{ $envio && $envio->enviado_at ? ' el '.$envio->enviado_at->format('d/m/Y H:i') : '' }}. Solo lectura — contabilidad debe habilitar la edición para modificarlo.
</div>
@endif

@if($kpiObras > 0)
{{-- BARRA SUPERIOR FIJA: totales + buscador + acciones --}}
<div style="position:sticky;top:0;z-index:50;background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:10px 14px;margin-bottom:1rem;box-shadow:0 2px 10px rgba(0,0,0,.06)">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;justify-content:space-between">
        <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap">
            <div>
                <div style="font-size:10px;color:#854D0E">Pendiente por distribuir</div>
                <div style="font-size:16px;font-weight:700;color:#854D0E">${{ number_format($kpiPendiente, 0, ',', '.') }}</div>
            </div>
            <div>
                <div style="font-size:10px;color:#6B7280">Obras</div>
                <div style="font-size:16px;font-weight:700;color:#1B3F6E">{{ $kpiObras }}</div>
            </div>
            <div>
                <div style="font-size:10px;color:#DC2626">Bajo margen</div>
                <div style="font-size:16px;font-weight:700;color:#DC2626">{{ $kpiAlertas }}</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input type="text" id="buscador" placeholder="🔎 Código o cliente…" autocomplete="off"
                style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px;width:190px">
            <button type="button" id="btn-expandir" style="font-size:12px;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#374151;cursor:pointer">Expandir todo</button>
            <button type="button" id="btn-colapsar" style="font-size:12px;padding:6px 12px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#374151;cursor:pointer">Colapsar todo</button>
        </div>
    </div>
    @if(!$bloqueado && $puedeEditar)
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;border-top:1px solid #F3F4F6;padding-top:10px">
        <span style="font-size:12px;color:#6B7280">Acciones rápidas <span style="color:#9CA3AF">(solo obras con ingreso)</span>:</span>
        <button type="button" onclick="aplicarTodo()" style="font-size:12px;padding:6px 14px;border:1px solid #16A34A;border-radius:8px;background:white;color:#15803D;cursor:pointer">Aplicar todo el pendiente</button>
        <button type="button" onclick="ponerEnCero()" style="font-size:12px;padding:6px 14px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;cursor:pointer">Poner todo en 0</button>
        <button type="button" onclick="abrirCalculo()" style="font-size:12px;padding:6px 14px;border:1px solid #4338CA;border-radius:8px;background:#EEF2FF;color:#4338CA;font-weight:500;cursor:pointer">✨ Calcular costo sugerido</button>
    </div>
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
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay obras con saldo en cuenta 14 para este período / filtro.</div>
@endif

<form method="POST" action="{{ route('operativo.distribucion.guardar') }}" id="form-dist">
@csrf
<input type="hidden" name="mes" value="{{ $mes }}">
<input type="hidden" name="anio" value="{{ $anio }}">
<input type="hidden" name="dist" value="{{ $distId }}">
<input type="hidden" name="departamento" value="{{ $depEfectivo }}">

@php
    $obrasConIngreso = array_filter($obras, fn($o) => empty($o['requiere_autorizacion']));
    $obrasSinIngreso = array_filter($obras, fn($o) => !empty($o['requiere_autorizacion']));
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
        <svg id="chev-grupo-sin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#B91C1C" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform .15s"><polyline points="9 6 15 12 9 18"/></svg>
        <span style="font-weight:700;color:#B91C1C;font-size:14px">Sin ingreso · requiere autorización</span>
        <span style="font-size:11px;font-weight:600;padding:2px 9px;border-radius:10px;background:#FEE2E2;color:#B91C1C">{{ count($obrasSinIngreso) }}</span>
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
            'sinIngreso' => (bool) $o['requiere_autorizacion'],
            'cap'      => max(0, (float) $o['ingreso_mes'] - abs((float) $o['costo_apl_mes'])),
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
    document.querySelectorAll('[id^="obra-"]').forEach(e => { e.style.display = abrir ? 'block' : 'none'; });
}
/* Buscador por código o cliente. */
function filtrarObras(q){
    q = (q||'').trim().toLowerCase();
    document.querySelectorAll('.obra-card').forEach(card => {
        const hay = !q || (card.dataset.buscar||'').includes(q);
        card.style.display = hay ? '' : 'none';
    });
    if(q){ abrirGrupo('con'); abrirGrupo('sin'); } // al buscar, abre ambos grupos
}
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
    // "Aplicar todo el pendiente" = proponer el monto topado al facturado del mes
    // y repartido FIFO por antigüedad (data-tope viene calculado del servidor).
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.dataset.bloqueado === '1') return; // sin ingreso: no se toca
        inp.value = Math.round(parseFloat(inp.dataset.tope || 0));
    });
    for (const cod in DATOS) { recalc(cod); }
}

function ponerEnCero(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        if (inp.dataset.bloqueado === '1') return; // solo obras con ingreso
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
    // Monto propuesto = lo que el operador escribió en "a aplicar" + provisiones de la obra.
    const monto = sumAplicar(cod) + sumProv(cod);
    if (monto <= 0) { alert('Escribe primero el monto que quieres distribuir en las categorías.'); return; }
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = @json(route('operativo.autorizaciones.solicitar'));
    f.style.display = 'none';
    f.innerHTML = '<input type="hidden" name="_token" value="' + @json(csrf_token()) + '">'
        + '<input type="hidden" name="codigo_proyecto">'
        + '<input type="hidden" name="mes" value="{{ $mes }}">'
        + '<input type="hidden" name="anio" value="{{ $anio }}">'
        + '<input type="hidden" name="monto">'
        + '<input type="hidden" name="motivo">';
    f.querySelector('[name="codigo_proyecto"]').value = cod;
    f.querySelector('[name="monto"]').value = monto;
    f.querySelector('[name="motivo"]').value = motivo;
    document.body.appendChild(f);
    f.submit();
}

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
});
</script>
@endsection