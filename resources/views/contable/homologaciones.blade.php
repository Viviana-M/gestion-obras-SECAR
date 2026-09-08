@extends('layouts.app')

@section('title', 'Homologaciones de cuentas')

@section('content')
@php
    $nombresMes = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                   7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $anioIni = (int) env('ANIO_INICIO_SISTEMA', 2022);
    $anioFin = max((int) date('Y') + 1, $periodoMinAnio);
@endphp

<x-page-banner title="Homologaciones — Plan de cuentas (14 ↔ 61)" icon="🔗">
    Vincula cada cuenta de <b>inventario de obra (14)</b> con su cuenta de <b>costo (61)</b>. Los cambios tienen vigencia y no reescriben el pasado.
</x-page-banner>

@if(session('success'))
<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#15803D">{{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ session('error') }}</div>
@endif
@if($errors->any())
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:13px;color:#DC2626">{{ $errors->first() }}</div>
@endif

{{-- AVISO DE VIGENCIA --}}
<div style="background:#EEF2FF;border:1px solid #C7D2FE;border-radius:8px;padding:10px 14px;margin-bottom:1rem;font-size:12.5px;color:#4338CA;line-height:1.5">
    <b>Las homologaciones tienen vigencia.</b> Cambiar una cuenta <b>no reescribe el pasado</b>: se cierra la versión anterior y se crea una nueva a partir del período que indiques.
    @if($ultimoEnvTexto)
        El último período enviado a contabilidad es <b>{{ $ultimoEnvTexto }}</b>, así que los cambios pueden aplicar desde <b>{{ $periodoMinTexto }}</b> en adelante.
    @else
        Todavía no hay períodos enviados a contabilidad.
    @endif
</div>

{{-- SUBIR EXCEL --}}
<div class="card" style="margin-bottom:1rem">
    <div style="font-size:14px;font-weight:600;color:#1B3F6E;margin-bottom:4px">Cargar plan de cuentas</div>
    <div style="font-size:12px;color:#9CA3AF;margin-bottom:12px">
        Lee la hoja "Plan de Cuentas" (Nombre / Cuenta Inv Obra / Costo / Estructura). Las cuentas nuevas se crean; las que cambien generan una versión nueva a partir del período que elijas.
    </div>
    <form method="POST" action="{{ route('contable.homologaciones.importar') }}" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        @csrf
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Archivo</label>
            <input type="file" name="archivo" accept=".xlsx,.xls" required style="font-size:13px;padding:6px;border:1px solid #E5E7EB;border-radius:8px">
        </div>
        <div>
            <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">Los cambios aplican desde</label>
            <div style="display:flex;gap:6px">
                <select name="vigente_mes" required style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                    @foreach($nombresMes as $k => $m)
                        <option value="{{ $k }}" {{ $k == $periodoMinMes ? 'selected' : '' }}>{{ $m }}</option>
                    @endforeach
                </select>
                <select name="vigente_anio" required style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                    @for($y = $anioIni; $y <= $anioFin; $y++)
                        <option value="{{ $y }}" {{ $y == $periodoMinAnio ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
        </div>
        <button type="submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer;height:36px">Subir y procesar</button>
    </form>
</div>

{{-- ACCIONES --}}
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:10px;flex-wrap:wrap">
    <div style="display:flex;gap:8px;align-items:center">
        <input id="buscador-h" oninput="filtrarH()" placeholder="Buscar cuenta o nombre…"
            style="padding:7px 12px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;min-width:220px">
        <span id="contador-h" style="font-size:12px;color:#9CA3AF">{{ $homologaciones->count() }} cuentas</span>
    </div>
    <button onclick="abrirNueva()" style="padding:7px 16px;background:white;border:1px solid #1B3F6E;color:#1B3F6E;border-radius:8px;font-size:13px;cursor:pointer">+ Agregar homologación</button>
</div>

{{-- TABLA --}}
<div class="card" style="overflow-x:auto">
    <table id="tabla-h" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#F3F4F6">
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 14 (inv. obra)</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Cuenta 61 (costo)</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Nombre</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Estructura</th>
                <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Vigencia</th>
                <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #E5E7EB;color:#6B7280;font-size:11px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($homologaciones as $h)
            @php
                $estOk = array_key_exists((string) $h->estructura, $estructuras);
                $nVers = isset($historial[(string) $h->cuenta_14]) ? count($historial[(string) $h->cuenta_14]) : 1;
            @endphp
            <tr class="fila-h" data-buscar="{{ strtolower($h->cuenta_14.' '.$h->cuenta_61.' '.$h->nombre.' '.$h->estructura) }}">
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;font-weight:600;color:#1B3F6E;white-space:nowrap">
                    {{ $h->cuenta_14 }}
                    @if($nVers > 1)
                        <span onclick="verHistorial('{{ $h->cuenta_14 }}')" title="Ver historial de cambios"
                              style="margin-left:5px;font-family:'Segoe UI',sans-serif;font-size:10px;font-weight:600;padding:1px 6px;border-radius:8px;background:#EEF2FF;color:#4338CA;cursor:pointer">v{{ $h->version }}</span>
                    @endif
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;font-family:monospace;color:#374151">{{ $h->cuenta_61 }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#374151">{{ $h->nombre }}</td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6">
                    @if($estOk)
                        <span style="color:#6B7280">{{ $h->estructura }}</span>
                    @else
                        <span title="Estructura no reconocida: la distribución la trata como Otros costos"
                              style="color:#DC2626;font-weight:600">⚠ {{ $h->estructura ?: '(vacía)' }}</span>
                    @endif
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;color:#9CA3AF;font-size:11px;white-space:nowrap">
                    {{ $h->rangoVigencia() }}
                    @if($h->requiere_reclasificacion && !$h->reclasificado_at)
                        <div style="margin-top:2px"><span style="font-size:10px;font-weight:600;padding:1px 6px;border-radius:8px;background:#FEF9C3;color:#854D0E">Reclasificación pendiente</span></div>
                    @endif
                </td>
                <td style="padding:7px 10px;border-bottom:1px solid #F3F4F6;text-align:center;white-space:nowrap">
                    <button onclick='abrirEditar(@json($h))' style="font-size:11px;padding:3px 10px;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;color:#1B3F6E">Editar</button>
                    <button onclick="verHistorial('{{ $h->cuenta_14 }}')" style="font-size:11px;padding:3px 10px;border:1px solid #E5E7EB;border-radius:6px;background:white;cursor:pointer;color:#6B7280">Historial</button>
                    <form method="POST" action="{{ route('contable.homologaciones.eliminar', $h->id) }}" style="display:inline"
                          onsubmit="return confirm('¿Eliminar TODAS las versiones de la cuenta {{ $h->cuenta_14 }}?\n\nSe pierde el historial de clasificación. Si la cuenta ya se usó en meses pasados, esos costos quedarán sin homologar.\n\nSolo hazlo si la cuenta se creó por error.')">
                        @csrf @method('DELETE')
                        <button type="submit" style="font-size:11px;padding:3px 10px;border:1px solid #FECACA;border-radius:6px;background:white;cursor:pointer;color:#DC2626">Eliminar</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" style="text-align:center;padding:2rem;color:#9CA3AF">Aún no hay homologaciones. Sube el plan de cuentas o agrega una a mano.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- SUGERENCIAS (no son listas cerradas) --}}
<datalist id="lista-c14">
    @foreach($cuentas14 as $cta => $desc)
        <option value="{{ $cta }}">{{ Str::limit($desc, 45) }}</option>
    @endforeach
</datalist>
<datalist id="lista-c61">
    @foreach($cuentas61 as $cta => $desc)
        <option value="{{ $cta }}">{{ Str::limit($desc, 45) }}</option>
    @endforeach
</datalist>

{{-- MODAL CREAR / EDITAR --}}
<div id="modal-h" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)cerrarH()">
    <div style="background:white;border-radius:12px;padding:20px;width:min(560px,95vw);max-height:92vh;overflow:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 id="modal-h-titulo" style="font-size:15px;font-weight:600;color:#1B3F6E">Nueva homologación</h3>
            <button onclick="cerrarH()" style="border:none;background:#F3F4F6;width:30px;height:30px;border-radius:8px;cursor:pointer;color:#6B7280">×</button>
        </div>

        <form id="modal-h-form" method="POST" onsubmit="return validarH()">
            @csrf
            <input type="hidden" name="_method" id="h-method" value="POST">

            <div style="display:grid;gap:12px">

                <div>
                    <label style="font-size:11px;color:#6B7280">Cuenta 14 (inv. obra) *</label>
                    <input name="cuenta_14" id="h-c14" list="lista-c14" autocomplete="off" required
                        placeholder="Escribe o elige de la lista…" oninput="revisarCuenta('c14')"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:monospace">
                    <div id="aviso-c14" style="display:none;font-size:11px;margin-top:4px;padding:6px 8px;border-radius:6px"></div>
                </div>

                <div>
                    <label style="font-size:11px;color:#6B7280">Cuenta 61 (costo) *</label>
                    <input name="cuenta_61" id="h-c61" list="lista-c61" autocomplete="off" required
                        placeholder="Escribe o elige de la lista…" oninput="revisarCuenta('c61')"
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:monospace">
                    <div id="aviso-c61" style="display:none;font-size:11px;margin-top:4px;padding:6px 8px;border-radius:6px"></div>
                </div>

                <div>
                    <label style="font-size:11px;color:#6B7280">Nombre</label>
                    <input name="nombre" id="h-nombre" style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                </div>

                <div>
                    <label style="font-size:11px;color:#6B7280">Estructura *</label>
                    <select name="estructura" id="h-estructura" required
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                        <option value="">— Elegir —</option>
                        @foreach($estructuras as $k => $label)
                            <option value="{{ $k }}">{{ $k }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- ══ Bloque que solo aparece al EDITAR (crear versión nueva) ══ --}}
                <div id="bloque-version" style="display:none;border-top:1px dashed #E5E7EB;padding-top:12px">

                    <div style="background:#EEF2FF;border:1px solid #C7D2FE;border-radius:8px;padding:8px 10px;font-size:11.5px;color:#4338CA;line-height:1.45;margin-bottom:12px">
                        Esto <b>no sobrescribe</b> la homologación actual: crea una <b>versión nueva</b>.
                        Lo ya contabilizado en los meses anteriores queda como está.
                    </div>

                    <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">El cambio aplica desde *</label>
                    <div style="display:flex;gap:6px;margin-bottom:4px">
                        <select name="vigente_mes" id="h-vig-mes" style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                            @foreach($nombresMes as $k => $m)
                                <option value="{{ $k }}" {{ $k == $periodoMinMes ? 'selected' : '' }}>{{ $m }}</option>
                            @endforeach
                        </select>
                        <select name="vigente_anio" id="h-vig-anio" style="flex:1;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px">
                            @for($y = $anioIni; $y <= $anioFin; $y++)
                                <option value="{{ $y }}" {{ $y == $periodoMinAnio ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div style="font-size:10px;color:#9CA3AF;margin-bottom:12px">
                        Mínimo permitido: <b>{{ $periodoMinTexto }}</b>@if($ultimoEnvTexto) — {{ $ultimoEnvTexto }} ya se envió a contabilidad @endif
                    </div>

                    <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:4px">¿Por qué se cambia? *</label>
                    <textarea name="motivo" id="h-motivo" rows="2" placeholder="Ej: la cuenta 61350501 se dividió; los materiales importados van ahora a la 61350505."
                        style="width:100%;padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:12.5px;font-family:inherit;resize:vertical"></textarea>
                    <div style="font-size:10px;color:#9CA3AF;margin-top:3px;margin-bottom:12px">Queda en la trazabilidad. Escríbelo para que se entienda dentro de seis meses.</div>

                    {{-- Reclasificación --}}
                    <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:10px 12px">
                        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
                            <input type="checkbox" name="requiere_reclasificacion" id="h-reclas" value="1"
                                   onchange="toggleReclas()" style="margin-top:2px;cursor:pointer">
                            <span style="font-size:12.5px;color:#854D0E;line-height:1.45">
                                <b>Requiere plano de reclasificación</b><br>
                                <span style="font-size:11px;color:#B45309">
                                    Genera un asiento correctivo que mueve los costos <b>ya contabilizados</b> de la cuenta 61 anterior a la nueva.
                                    No reescribe el histórico: lo corrige con un movimiento nuevo y trazable.
                                </span>
                            </span>
                        </label>

                        <div id="bloque-reclas" style="display:none;margin-top:10px;padding-top:10px;border-top:1px dashed #FDE68A">
                            <label style="font-size:11px;color:#854D0E;display:block;margin-bottom:4px">Reclasificar los costos desde</label>
                            <div style="display:flex;gap:6px">
                                <select name="reclas_mes" id="h-rec-mes" style="flex:1;padding:7px 10px;border:1px solid #FDE68A;border-radius:8px;font-size:13px;background:white">
                                    @foreach($nombresMes as $k => $m)
                                        <option value="{{ $k }}">{{ $m }}</option>
                                    @endforeach
                                </select>
                                <select name="reclas_anio" id="h-rec-anio" style="flex:1;padding:7px 10px;border:1px solid #FDE68A;border-radius:8px;font-size:13px;background:white">
                                    @for($y = $anioIni; $y <= $anioFin; $y++)
                                        <option value="{{ $y }}" {{ $y == date('Y') ? 'selected' : '' }}>{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                            <div style="font-size:10px;color:#B45309;margin-top:4px">
                                Debe ser <b>anterior</b> al período en que empieza a regir la cuenta nueva.
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div style="margin-top:18px;text-align:right">
                <button type="button" onclick="cerrarH()" style="padding:7px 16px;border:1px solid #E5E7EB;border-radius:8px;background:white;color:#6B7280;font-size:13px;cursor:pointer">Cancelar</button>
                <button type="submit" id="h-submit" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Guardar</button>
            </div>
        </form>
    </div>
</div>

{{-- MODAL HISTORIAL --}}
<div id="modal-hist" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)cerrarHist()">
    <div style="background:white;border-radius:12px;padding:20px;width:min(640px,95vw);max-height:88vh;overflow:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 id="hist-titulo" style="font-size:15px;font-weight:600;color:#1B3F6E">Historial</h3>
            <button onclick="cerrarHist()" style="border:none;background:#F3F4F6;width:30px;height:30px;border-radius:8px;cursor:pointer;color:#6B7280">×</button>
        </div>
        <div id="hist-contenido"></div>
        <div style="margin-top:16px;text-align:right">
            <button type="button" onclick="cerrarHist()" style="padding:7px 18px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;cursor:pointer">Cerrar</button>
        </div>
    </div>
</div>

<script>
const H_NUEVO = "{{ route('contable.homologaciones.guardar') }}";
const H_EDIT  = "{{ url('/contable/homologaciones') }}";

const CONOCIDAS = {
    c14: @json($cuentas14),
    c61: @json($cuentas61),
};
const HISTORIAL       = @json($historial);
const YA_HOMOLOGADAS  = @json($homologaciones->pluck('cuenta_14')->map(fn($c) => (string) $c)->values());

let editandoId = null;   // null = estoy creando

function avisar(campo, tipo, texto) {
    const el  = document.getElementById('aviso-' + campo);
    const inp = document.getElementById('h-' + campo);
    if (!texto) { el.style.display = 'none'; inp.style.borderColor = '#E5E7EB'; return; }
    const estilos = {
        ok:    ['#F0FDF4', '#BBF7D0', '#15803D'],
        nueva: ['#FFFBEB', '#FDE68A', '#B45309'],
        error: ['#FEF2F2', '#FECACA', '#DC2626'],
    };
    const [bg, bd, fg] = estilos[tipo] || estilos.nueva;
    el.style.display = 'block';
    el.style.background = bg;
    el.style.border = '1px solid ' + bd;
    el.style.color = fg;
    el.textContent = texto;
    inp.style.borderColor = bd;
}

function revisarCuenta(campo) {
    const inp = document.getElementById('h-' + campo);
    const v = inp.value.trim();
    if (v === '') { avisar(campo, null, null); return; }

    const prefijo = campo === 'c14' ? '14' : '6';
    if (!/^\d+$/.test(v))        { avisar(campo, 'error', 'La cuenta debe tener solo números.'); return; }
    if (!v.startsWith(prefijo))  { avisar(campo, 'error', 'Esta cuenta debería empezar por ' + prefijo + '.'); return; }

    if (campo === 'c14' && editandoId === null && YA_HOMOLOGADAS.includes(v)) {
        avisar(campo, 'error', 'Esta cuenta ya está homologada. Usa Editar en su fila para crear una versión nueva.');
        return;
    }

    const desc = CONOCIDAS[campo][v];
    if (desc !== undefined) {
        avisar(campo, 'ok', '✓ ' + (desc || 'Cuenta conocida'));
        const nom = document.getElementById('h-nombre');
        if (desc && nom.value.trim() === '') nom.value = desc;
    } else {
        avisar(campo, 'nueva', '⚠ Esta cuenta no aparece en los movimientos cargados. Si es nueva, continúa; si no, revísala.');
    }
}

function toggleReclas() {
    const on = document.getElementById('h-reclas').checked;
    document.getElementById('bloque-reclas').style.display = on ? 'block' : 'none';
}

function validarH() {
    const c14 = document.getElementById('h-c14').value.trim();
    const c61 = document.getElementById('h-c61').value.trim();
    const est = document.getElementById('h-estructura').value;

    if (!/^14\d+$/.test(c14)) { alert('La cuenta 14 debe empezar por 14 y tener solo números.'); return false; }
    if (!/^6\d+$/.test(c61))  { alert('La cuenta 61 debe empezar por 6 y tener solo números.');  return false; }
    if (!est)                 { alert('Elige la estructura.'); return false; }

    if (editandoId === null && YA_HOMOLOGADAS.includes(c14)) {
        alert('La cuenta ' + c14 + ' ya está homologada. Usa el botón Editar en su fila.');
        return false;
    }

    // Al editar: motivo obligatorio y coherencia de la reclasificación
    if (editandoId !== null) {
        const motivo = document.getElementById('h-motivo').value.trim();
        if (motivo.length < 5) { alert('Escribe por qué se cambia la homologación. Queda en la trazabilidad.'); return false; }

        const vp = parseInt(document.getElementById('h-vig-anio').value) * 100 + parseInt(document.getElementById('h-vig-mes').value);

        if (document.getElementById('h-reclas').checked) {
            const rp = parseInt(document.getElementById('h-rec-anio').value) * 100 + parseInt(document.getElementById('h-rec-mes').value);
            if (rp >= vp) {
                alert('La reclasificación corrige el pasado: debe empezar ANTES del período en que entra a regir la cuenta nueva.');
                return false;
            }
        }
    }

    const desconocidas = [];
    if (CONOCIDAS.c14[c14] === undefined) desconocidas.push('14 → ' + c14);
    if (CONOCIDAS.c61[c61] === undefined) desconocidas.push('61 → ' + c61);

    if (desconocidas.length) {
        return confirm(
            'Estas cuentas no aparecen en los movimientos cargados:\n\n  ' + desconocidas.join('\n  ') +
            '\n\nSi son cuentas nuevas del plan, está bien. Si fue un error de digitación, cancela y corrígelo.\n\n¿Guardar de todas formas?'
        );
    }
    return true;
}

function abrirNueva() {
    editandoId = null;
    document.getElementById('modal-h-titulo').textContent = 'Nueva homologación';
    document.getElementById('modal-h-form').action = H_NUEVO;
    document.getElementById('h-method').value = 'POST';
    ['h-c14','h-c61','h-nombre'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('h-estructura').value = '';
    document.getElementById('h-c14').readOnly = false;
    document.getElementById('bloque-version').style.display = 'none';
    document.getElementById('h-reclas').checked = false;
    document.getElementById('bloque-reclas').style.display = 'none';
    document.getElementById('h-submit').textContent = 'Guardar';
    avisar('c14', null, null);
    avisar('c61', null, null);
    document.getElementById('modal-h').style.display = 'flex';
}

function abrirEditar(h) {
    editandoId = h.id;
    document.getElementById('modal-h-titulo').textContent = 'Nueva versión de ' + h.cuenta_14 + ' (actual: v' + h.version + ')';
    document.getElementById('modal-h-form').action = H_EDIT + '/' + h.id;
    document.getElementById('h-method').value = 'PUT';
    document.getElementById('h-c14').value = h.cuenta_14 ?? '';
    document.getElementById('h-c14').readOnly = true;
    document.getElementById('h-c61').value = h.cuenta_61 ?? '';
    document.getElementById('h-nombre').value = h.nombre ?? '';

    const sel = document.getElementById('h-estructura');
    const est = (h.estructura ?? '').toString();
    sel.value = [...sel.options].some(o => o.value === est) ? est : '';

    document.getElementById('bloque-version').style.display = 'block';
    document.getElementById('h-motivo').value = '';
    document.getElementById('h-reclas').checked = false;
    document.getElementById('bloque-reclas').style.display = 'none';
    document.getElementById('h-submit').textContent = 'Crear versión nueva';

    avisar('c14', null, null);
    revisarCuenta('c61');
    document.getElementById('modal-h').style.display = 'flex';
}

function cerrarH() { document.getElementById('modal-h').style.display = 'none'; }

function verHistorial(cuenta) {
    const versiones = HISTORIAL[cuenta] || [];
    document.getElementById('hist-titulo').textContent = 'Historial de ' + cuenta;

    let html = '';
    if (!versiones.length) {
        html = '<div style="color:#9CA3AF;font-size:13px">Sin historial.</div>';
    } else {
        versiones.forEach(v => {
            const borde = v.vigente ? '#BBF7D0' : '#E5E7EB';
            const fondo = v.vigente ? '#F0FDF4' : '#FFFFFF';
            const badge = v.vigente
                ? '<span style="font-size:10px;font-weight:600;padding:1px 7px;border-radius:8px;background:#DCFCE7;color:#15803D">VIGENTE</span>'
                : '<span style="font-size:10px;font-weight:600;padding:1px 7px;border-radius:8px;background:#F3F4F6;color:#9CA3AF">HISTÓRICA</span>';

            html += '<div style="border:1px solid ' + borde + ';background:' + fondo + ';border-radius:8px;padding:10px 12px;margin-bottom:8px">'
                 +   '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">'
                 +     '<span style="font-size:13px;font-weight:600;color:#1B3F6E">v' + v.version + ' · <span style="font-family:monospace">' + v.cuenta_61 + '</span></span>'
                 +     badge
                 +   '</div>'
                 +   '<div style="font-size:11.5px;color:#6B7280;line-height:1.6">'
                 +     '<div><b>Vigencia:</b> ' + v.rango + '</div>'
                 +     '<div><b>Estructura:</b> ' + (v.estructura || '—') + '</div>'
                 +     '<div><b>Nombre:</b> ' + (v.nombre || '—') + '</div>'
                 +     (v.motivo ? '<div><b>Motivo:</b> ' + v.motivo + '</div>' : '')
                 +     (v.reclasif ? '<div style="color:#B45309"><b>Reclasificación</b> solicitada desde ' + (v.reclas_desde || '—') + '</div>' : '')
                 +     (v.creado ? '<div style="color:#9CA3AF;font-size:10.5px;margin-top:3px">Registrada el ' + v.creado + '</div>' : '')
                 +   '</div>'
                 + '</div>';
        });
    }

    document.getElementById('hist-contenido').innerHTML = html;
    document.getElementById('modal-hist').style.display = 'flex';
}

function cerrarHist() { document.getElementById('modal-hist').style.display = 'none'; }

function filtrarH() {
    const q = document.getElementById('buscador-h').value.toLowerCase().trim();
    let n = 0;
    document.querySelectorAll('#tabla-h tbody tr.fila-h').forEach(f => {
        const ok = f.dataset.buscar.includes(q);
        f.style.display = ok ? '' : 'none';
        if (ok) n++;
    });
    document.getElementById('contador-h').textContent = n + ' cuenta' + (n === 1 ? '' : 's');
}
</script>
@endsection