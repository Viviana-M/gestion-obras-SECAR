@extends('layouts.app')

@section('title', 'Distribución de costos')

@section('content')
<h1 class="page-title">Distribución de costos</h1>

<div class="card" style="margin-bottom:1rem">
    <form method="GET" action="{{ route('operativo.distribucion') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
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
            <select name="tipo" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                @foreach(['todos'=>'Todos','mantenimiento'=>'Mantenimiento','contrato'=>'Contratos','reparacion'=>'Reparaciones','garantia'=>'Garantías','otro'=>'Otros'] as $k => $v)
                    <option value="{{ $k }}" {{ $tipo == $k ? 'selected' : '' }}>{{ $v }}</option>
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
</div>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#15803D;margin-bottom:1rem">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#DC2626;margin-bottom:1rem">{{ session('error') }}</div>
@endif

@if($distId)
<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:8px 14px;font-size:12px;color:#1B3F6E;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>Editando borrador existente (v{{ $envio->version ?? '?' }}). Al guardar se actualiza esta misma versión.</span>
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

@if($kpiObras > 0 && !$bloqueado)
<div style="display:flex;gap:10px;align-items:center;margin-bottom:1rem;flex-wrap:wrap">
    <span style="font-size:12px;color:#6B7280">Acciones rápidas:</span>
    <button type="button" onclick="aplicarTodo()" style="font-size:12px;padding:6px 14px;border:1px solid #16A34A;border-radius:8px;background:white;color:#15803D;cursor:pointer">Aplicar todo el pendiente</button>
    <button type="button" onclick="ponerEnCero()" style="font-size:12px;padding:6px 14px;border:1px solid #DC2626;border-radius:8px;background:white;color:#DC2626;cursor:pointer">Poner todo en 0</button>
</div>
@endif

@php
    $colCat = ['EQU-MAT-SUM'=>'#378ADD','MOI'=>'#1D9E75','MOE'=>'#7F77DD','OTROS COSTO'=>'#EF9F27','MOFIJAOPER'=>'#D85A30'];
    $colEstado = ['abierta'=>['#F0FDF4','#15803D'],'parcial'=>['#FEF9C3','#854D0E'],'cerrada'=>['#EFF6FF','#1B3F6E']];
    $colSem = ['verde'=>'#16A34A','ambar'=>'#D97706','rojo'=>'#DC2626','gris'=>'#9CA3AF'];
    $fmt = fn($n) => '$'.number_format($n, 0, ',', '.');
    $pp  = fn($v) => $v === null ? '—' : (($v >= 0 ? '+' : '').number_format($v, 1, ',', '.').' pp');
@endphp

@if($kpiObras == 0)
<div class="card" style="text-align:center;color:#9CA3AF;padding:2rem">No hay obras con saldo en cuenta 14 para este período / filtro.</div>
@endif

<form method="POST" action="{{ route('operativo.distribucion.guardar') }}" id="form-dist">
@csrf
<input type="hidden" name="mes" value="{{ $mes }}">
<input type="hidden" name="anio" value="{{ $anio }}">
<input type="hidden" name="dist" value="{{ $distId }}">

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
            </div>
            <div style="font-size:11px;color:#6B7280;margin-top:3px" id="metodo-{{ $cod }}">Método: {{ $o['metodo'] }}</div>
        </div>
        <div style="text-align:right;flex:none">
            <div style="font-size:11px;color:#854D0E">A aplicar</div>
            <div style="font-weight:600;color:#854D0E" id="aplicar-tot-{{ $cod }}">{{ $fmt($o['sum_aplicar'] + $o['sum_prov']) }}</div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#E5E7EB">
        <div style="background:white;padding:10px 14px">
            <div style="font-size:10px;color:#9CA3AF">Margen del mes <i>(ref.)</i></div>
            <div style="font-size:15px;font-weight:600;color:#6B7280">{{ $o['margen_mes'] === null ? '—' : $o['margen_mes'].'%' }}</div>
        </div>
        <div style="background:white;padding:10px 14px">
            <div style="font-size:10px;color:#9CA3AF">Margen acumulado <i>(control)</i></div>
            <div style="font-size:15px;font-weight:600;color:{{ $colSem[$o['semaforo']] }}">{{ $o['margen_acum'] === null ? '—' : $o['margen_acum'].'%' }}</div>
            <div style="font-size:10px;color:#9CA3AF">vs ofertado: {{ $pp($o['delta_acum']) }}</div>
        </div>
        <div style="background:white;padding:10px 14px">
            <div style="font-size:10px;color:#9CA3AF">Margen proyección <i>(dinámico)</i></div>
            <div style="font-size:15px;font-weight:600;color:#374151" id="proy-{{ $cod }}">{{ $o['margen_proy'] === null ? '—' : $o['margen_proy'].'%' }}</div>
            <div style="font-size:10px;color:#9CA3AF" id="proyd-{{ $cod }}">vs ofertado: {{ $pp($o['delta_proy']) }}</div>
        </div>
        <div style="background:white;padding:10px 14px">
            <div style="font-size:10px;color:#9CA3AF">Ofertado (comercial)</div>
            <div style="font-size:15px;font-weight:600;color:#1B3F6E">{{ $o['ofertado'] === null ? '—' : $o['ofertado'].'%' }}</div>
        </div>
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
                                value="{{ round($sub['aplicar']) }}"
                                name="aplicar[{{ $cod }}][{{ $sub['cuenta_14'] }}]"
                                data-cod="{{ $cod }}" data-tipo="aplicar"
                                oninput="capear(this);recalc('{{ $cod }}')"
                                style="width:100px;padding:3px 6px;border:1px solid #E5E7EB;border-radius:4px;font-size:11px;text-align:right">
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
                <button type="button" onclick="toggleProvForm('{{ $cod }}')" style="font-size:11px;padding:4px 10px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;cursor:pointer">+ Provisión</button>
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
    <button type="submit" name="accion" value="guardar" style="padding:9px 22px;background:#6B7280;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">{{ $distId ? 'Guardar cambios' : 'Guardar borrador' }}</button>
    <button type="submit" name="accion" value="enviar" onclick="return confirm('¿Enviar toda la distribución del mes a contabilidad? La hoja quedará en solo lectura.')" style="padding:9px 22px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Enviar a contabilidad</button>
</div>
@endif
</form>

@php
    $ctaJs = $catalogo->keyBy('cuenta_14');
    $datosJs = [];
    foreach ($obras as $cod => $o) {
        $datosJs[$cod] = [
            'ing'   => $o['ingreso_acum'],
            'costo' => $o['costo_apl_acum'],
            'of'    => $o['ofertado'],
            'acum'  => $o['margen_acum'],
            'pend'  => $o['total_pendiente'],
            'rev'   => $o['total_reversado'],
        ];
    }
@endphp
<script>
const CTA = @json($ctaJs);
const DATOS = @json($datosJs);
const COLSEM = {verde:'#16A34A',ambar:'#D97706',rojo:'#DC2626',gris:'#9CA3AF'};
const ESTCOL = {abierta:['#F0FDF4','#15803D'], parcial:['#FEF9C3','#854D0E'], cerrada:['#EFF6FF','#1B3F6E']};
let provIdx = {};

const fmt = n => '$' + Math.round(n).toLocaleString('es-CO');

function toggleObra(cod){ const e=document.getElementById('obra-'+cod); if(e) e.style.display = e.style.display==='none'?'block':'none'; }
function toggleProvForm(cod){ const e=document.getElementById('provform-'+cod); e.style.display = e.style.display==='none'?'block':'none'; }
function capear(inp){ const max=parseFloat(inp.max||0); let v=parseFloat(inp.value||0); if(v>max){inp.value=Math.round(max);} if(v<0){inp.value=0;} }

function sumAplicar(cod){
    const card=document.getElementById('card-'+cod); let s=0;
    card.querySelectorAll('input[data-tipo="aplicar"]').forEach(i=>s+=parseFloat(i.value||0));
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
    const tot=document.getElementById('aplicar-tot-'+cod); if(tot) tot.textContent=fmt(sumA+sumP);
    evaluarCerrable(cod);
    if(!d.ing){ return; }
    const proy=(d.ing-(d.costo+sumA+sumP))/d.ing*100;
    document.getElementById('proy-'+cod).textContent=proy.toFixed(1)+'%';
    const dp = d.of!=null ? (proy-d.of) : null;
    document.getElementById('proyd-'+cod).textContent = d.of!=null ? ('vs ofertado: '+(dp>=0?'+':'')+dp.toFixed(1)+' pp') : '';
    let sem='gris';
    if(d.of!=null && d.acum!=null){
        if(d.acum<d.of) sem='rojo'; else if(proy<d.of) sem='ambar'; else sem='verde';
    }
    document.getElementById('dot-'+cod).style.background=COLSEM[sem];
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
        inp.value = Math.round(parseFloat(inp.max || 0));
    });
    for (const cod in DATOS) { recalc(cod); }
}

function ponerEnCero(){
    document.querySelectorAll('#form-dist input[data-tipo="aplicar"]').forEach(inp => {
        inp.value = 0;
    });
    for (const cod in DATOS) { recalc(cod); }
}

function bloquearForm(){
    const f=document.getElementById('form-dist'); if(!f) return;
    f.querySelectorAll('input, select, button').forEach(e=>{ e.disabled=true; });
}

document.addEventListener('DOMContentLoaded', function(){
    for (const cod in DATOS) { evaluarCerrable(cod); }
    @if($bloqueado) bloquearForm(); @endif
});
</script>
@endsection