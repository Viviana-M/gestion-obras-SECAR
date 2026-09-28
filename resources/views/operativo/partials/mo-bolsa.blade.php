{{-- Distribución de MANO DE OBRA DIRECTA por OBRA dentro del grid de la bolsa (departamento $b).
     El costo completo por persona (salario + seguridad social, cruce por cédula) se calcula en el
     backend y llega en $b['mo_personas']. Aquí la captura es POR OBRA: en cada obra destino se
     agregan terceros (con saldo por aplicar) y su monto. Se guarda igual en mano_obra_asignacion
     (tercero, obra_destino, monto) vía operativo.mano-obra.guardar. Vars heredadas del padre:
     $mes,$anio,$moGuardadas,$moObras,$moObrasInfo,$moHayMesAnterior,$moMesAnt,$moAnioAnt,$puedeEditar --}}
@php
    $moFmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puede = $puedeEditar ?? auth()->user()->puedeEditarModulo('operacion');
    $moPersonas = collect($b['mo_personas'] ?? []);
    $porCed = $moPersonas->keyBy('cedula');
    $dep = $b['codigo'];

    // Líneas ya guardadas, invertidas a POR OBRA (para pre-renderizar las tarjetas).
    $moInit = [];
    foreach (($moGuardadas ?? []) as $ced => $obras) {
        $nom = $porCed[$ced]['nombre'] ?? (string) $ced;
        foreach ((array) $obras as $obra => $monto) {
            if ((float) $monto <= 0) continue;
            $moInit[] = ['obra' => (string) $obra, 'ced' => (string) $ced, 'nom' => (string) $nom, 'monto' => round((float) $monto)];
        }
    }
@endphp

@if($moPersonas->isNotEmpty())
<div class="mo-blk" data-depto="{{ $dep }}" data-mo-init='@json($moInit)' style="border-top:1px dashed #FDE68A;background:#FFFDF5;padding:10px 12px">
    <div style="font-size:11px;font-weight:700;color:#92400E;margin-bottom:8px">🧑‍🔧 MANO DE OBRA DIRECTA — agregar por obra destino</div>

    @unless($puede)
        <div style="font-size:11px;color:#92400E;background:#FEF9C3;border:1px solid #FDE68A;border-radius:6px;padding:6px 10px;margin-bottom:8px">Solo lectura: para aplicar, Contabilidad debe abrir el cierre del mes.</div>
    @endunless

    <datalist id="mo-obras-list-{{ $dep }}">
        @foreach($moObras as $o)<option value="{{ $o->codigo_proyecto }}">{{ $o->codigo_proyecto }} — {{ $o->nombre_obra }}</option>@endforeach
    </datalist>

    <form method="POST" action="{{ route('operativo.mano-obra.guardar') }}" class="mo-form">
        @csrf
        <input type="hidden" name="departamento" value="{{ $dep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">

        <div style="display:grid;grid-template-columns:1fr 1.7fr;gap:14px;align-items:start">
            {{-- Izquierda: MO por aplicar (pool por persona, saldo restante en vivo) --}}
            <div>
                <div style="font-size:11px;font-weight:700;color:#1B3F6E;margin-bottom:6px">MO por aplicar</div>
                <div style="display:flex;flex-direction:column;gap:4px">
                    @foreach($moPersonas as $p)
                    <div class="mo-persona" data-ced="{{ $p['cedula'] }}" data-nom="{{ $p['nombre'] }}" data-doc="{{ $p['doc'] }}" data-total="{{ $p['total'] }}"
                         style="border:1px solid #FDE68A;border-radius:6px;padding:5px 8px;background:#fff;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:11.5px;color:#1B3F6E;font-weight:600">{{ $p['nombre'] ?: $p['cedula'] }}</span>
                        <span style="font-size:10px;color:#9CA3AF;font-family:monospace">{{ $p['doc'] ?: $p['cedula'] }}</span>
                        <span style="margin-left:auto;font-size:11px;color:#374151">Por aplicar <b data-mo-rest style="color:#15803D">{{ $moFmt($p['total']) }}</b></span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Derecha: obras destino (tarjetas "Cómo queda el proyecto" con "Agregar mano de obra") --}}
            <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
                    <div style="font-size:11px;font-weight:700;color:#1B3F6E">Obras destino — cómo queda el proyecto</div>
                    @if($puede)
                    <div style="margin-left:auto;display:flex;gap:5px;align-items:center">
                        <input list="mo-obras-list-{{ $dep }}" placeholder="Agregar obra destino…" data-mo-nueva
                               style="padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                        <button type="button" onclick="moAddObra(this)" style="padding:4px 10px;background:white;border:1px solid #1B3F6E;border-radius:6px;font-size:11px;color:#1B3F6E;cursor:pointer">+ obra</button>
                    </div>
                    @endif
                </div>
                <div class="mo-obra-cards" data-list="mo-obras-list-{{ $dep }}" style="display:flex;flex-direction:column;gap:8px"></div>
                <div class="mo-obra-vacio" style="color:#9CA3AF;font-size:11px;padding:6px">Agrega una obra destino y aplícale mano de obra.</div>
            </div>
        </div>

        @if($puede)
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
            <button type="submit" style="padding:6px 14px;background:#1B3F6E;color:white;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">💾 Guardar MO</button>
            @if($moHayMesAnterior)
            <button type="button" onclick="this.closest('.mo-blk').querySelector('.mo-precargar').submit()" style="padding:6px 12px;background:#EFF6FF;border:1px solid #1B3F6E;border-radius:6px;font-size:12px;color:#1B3F6E;cursor:pointer">⤵ Precargar mes anterior</button>
            @endif
        </div>
        @endif
    </form>

    @if($puede)
    <form class="mo-precargar" method="POST" action="{{ route('operativo.mano-obra.precargar') }}" style="display:none"
          onsubmit="return confirm('¿Precargar la distribución de {{ $meses[$moMesAnt] ?? $moMesAnt }} {{ $moAnioAnt }}? Reparte el costo actual de cada persona en las mismas obras/proporciones del mes anterior. Reemplaza lo cargado este período.');">
        @csrf<input type="hidden" name="departamento" value="{{ $dep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
    </form>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;border-top:1px solid #FDE68A;padding-top:8px">
        <form method="GET" action="{{ route('operativo.mano-obra.plano') }}" style="display:flex;gap:5px;align-items:flex-end;margin:0">
            <input type="hidden" name="departamento" value="{{ $dep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <input type="number" name="documento" min="1" value="1" title="N° documento" style="width:70px;padding:5px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
            <button type="submit" style="padding:6px 12px;background:#16A34A;color:white;border:none;border-radius:6px;font-size:11.5px;cursor:pointer">⬇ Plano</button>
        </form>
        <form method="POST" action="{{ route('operativo.mano-obra.aplicar') }}" style="margin:0"
              onsubmit="return confirm('¿Aplicar en el sistema la MO guardada de esta área? Partida doble 14→61 (origen distribucion_plano), idempotente por bolsa+período.');">
            @csrf<input type="hidden" name="departamento" value="{{ $dep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            <button type="submit" style="padding:6px 12px;background:#15803D;color:white;border:none;border-radius:6px;font-size:11.5px;font-weight:600;cursor:pointer">✓ Aplicar</button>
        </form>
        <a href="{{ route('operativo.mano-obra.resumen', ['departamento'=>$dep,'mes'=>$mes,'anio'=>$anio]) }}" style="padding:6px 12px;background:white;border:1px solid #1B3F6E;border-radius:6px;font-size:11.5px;color:#1B3F6E;text-decoration:none">📊 Resumen</a>
    </div>
    @endif
</div>
@endif

@once
<script>
window.MO_OBRAS = @json($moObrasInfo ?? new stdClass);
window.MO_EDIT = @json($puede);
(function(){
    const fmt  = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const pct1 = (n) => (Math.round(n*10)/10).toLocaleString('es-CO',{minimumFractionDigits:1,maximumFractionDigits:1}) + '%';
    const moNum = (v) => { const n = parseInt(String(v).replace(/\D/g, ''), 10); return isNaN(n) ? 0 : n; };
    const esc = (s) => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    let moSeq = 100000;

    // ── Consultas de estado dentro de un bloque (una bolsa/departamento) ──
    const personas = (blk) => [...blk.querySelectorAll('.mo-persona')];
    function personaEl(blk, ced){ return personas(blk).find(p => p.dataset.ced === String(ced)) || null; }
    function totalDe(blk, ced){ const p = personaEl(blk, ced); return p ? (parseFloat(p.dataset.total)||0) : 0; }
    function nombreDe(blk, ced){ const p = personaEl(blk, ced); return p ? (p.dataset.nom || ced) : ced; }
    // Aplicado de una persona en TODAS las obras (opcionalmente excluyendo un input).
    function aplicado(blk, ced, exceptEl){
        let s = 0;
        blk.querySelectorAll('.mo-linea').forEach(l => {
            if (l.dataset.ced !== String(ced)) return;
            const inp = l.querySelector('[data-mo-monto]');
            if (inp === exceptEl) return;
            s += moNum(inp ? inp.value : 0);
        });
        return s;
    }
    function restante(blk, ced, exceptEl){ return Math.round(totalDe(blk, ced) - aplicado(blk, ced, exceptEl)); }

    // ── Costo/margen de la obra (incluye la MO aplicada), en una línea compacta ──
    function costoHTML(cod, moCarg){
        const info = (window.MO_OBRAS[cod]) || {nombre:'',ingreso:0,costo_apl:0,inventario:0};
        const otros = info.costo_apl, costoReal = otros + moCarg, mc = info.ingreso - costoReal;
        const mcp = info.ingreso ? (mc/info.ingreso*100) : null;
        const saldoTr = Math.max(0, info.inventario - moCarg);
        const cMc = mc < 0 ? '#DC2626' : '#15803D';
        const cMcp = (mcp !== null && mcp < 0) ? '#DC2626' : '#15803D';
        return '<div style="display:flex;flex-wrap:wrap;gap:4px 12px;font-size:10.5px;color:#374151">'+
            '<span>Ingreso <b>'+fmt(info.ingreso)+'</b></span>'+
            '<span>Materiales/otros <b>'+fmt(otros)+'</b></span>'+
            '<span>Mano de obra <b>'+fmt(moCarg)+'</b></span>'+
            '<span>Costo real <b>'+fmt(costoReal)+'</b></span>'+
            '<span>MC$ <b style="color:'+cMc+'">'+fmt(mc)+'</b></span>'+
            '<span>MC% <b style="color:'+cMcp+'">'+(mcp!==null?pct1(mcp):'—')+'</b></span>'+
            '<span>Saldo en tránsito <b>'+fmt(saldoTr)+'</b></span></div>';
    }

    // ── Construcción de tarjetas y líneas ──
    function crearLinea(cod, ced, nom, monto){
        const i = moSeq++;
        const row = document.createElement('div');
        row.className = 'mo-linea';
        row.dataset.ced = ced; row.dataset.nom = nom;
        row.style.cssText = 'display:flex;gap:6px;align-items:center;font-size:11px;padding:4px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px';
        const inputMonto = window.MO_EDIT
            ? '<input type="text" inputmode="numeric" name="asignaciones['+i+'][monto]" value="'+(monto?Math.round(monto).toLocaleString("es-CO"):"")+'" data-mo-monto oninput="moLineaInput(this)" style="width:100px;padding:2px 6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px;text-align:right;background:#fff">'
            : '<b data-mo-monto data-val="'+(monto||0)+'" style="min-width:90px;text-align:right;color:#92400E">'+fmt(monto||0)+'</b>';
        row.innerHTML =
            '<span style="flex:1;color:#92400E">🡒 <b>'+esc(nom)+'</b></span>'+
            inputMonto+
            '<input type="hidden" name="asignaciones['+i+'][cedula]" value="'+esc(ced)+'">'+
            '<input type="hidden" name="asignaciones['+i+'][nombre]" value="'+esc(nom)+'">'+
            '<input type="hidden" name="asignaciones['+i+'][obra]" value="'+esc(cod)+'">'+
            (window.MO_EDIT ? '<a href="#" onclick="moDelLinea(this);return false" style="color:#DC2626;text-decoration:none">✕</a>' : '');
        return row;
    }

    function crearCard(blk, cod){
        const info = (window.MO_OBRAS[cod]) || {nombre:''};
        const card = document.createElement('div');
        card.className = 'mo-obra'; card.dataset.obra = cod;
        card.style.cssText = 'border:1px solid #E5E7EB;border-radius:6px;padding:6px 8px;background:#fff';
        // Control compacto estilo "Asignar desde bolsa de área": Tercero + Monto + Asignar.
        const addRow = window.MO_EDIT
            ? '<div class="mo-add-linea" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:6px">'+
                '<div style="flex:2;min-width:170px"><label style="font-size:10px;color:#6B7280;display:block">Tercero</label>'+
                  '<select data-mo-add-ced style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px"></select></div>'+
                '<div style="flex:1;min-width:100px"><label style="font-size:10px;color:#6B7280;display:block">Monto</label>'+
                  '<input type="text" inputmode="numeric" data-mo-add-monto placeholder="0" oninput="moFmtOnly(this)" style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px;text-align:right"></div>'+
                '<button type="button" onclick="moAddLinea(this)" style="padding:7px 14px;background:#D97706;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">Asignar</button>'+
              '</div>'
            : '';
        card.innerHTML =
            '<div style="display:flex;align-items:center;gap:6px">'+
              '<div style="font-weight:600;color:#1B3F6E;font-size:11px;flex:1">'+esc(cod)+' <span style="font-weight:400;color:#9CA3AF">'+esc(info.nombre||'')+'</span></div>'+
              (window.MO_EDIT ? '<a href="#" onclick="moQuitarObra(this);return false" title="Quitar obra" style="color:#9CA3AF;text-decoration:none;font-size:13px">🗑</a>' : '')+
            '</div>'+
            '<div class="mo-obra-cost" style="margin-top:4px"></div>'+
            '<div class="mo-lineas" data-mo-lineas style="margin-top:4px"></div>'+
            addRow;
        blk.querySelector('.mo-obra-cards').appendChild(card);
        return card;
    }

    function cardDe(blk, cod){ return [...blk.querySelectorAll('.mo-obra')].find(c => c.dataset.obra === cod) || null; }

    // ── Recalcular todo el bloque ──
    function recalcBlk(blk){
        // Pool: saldo restante por persona.
        personas(blk).forEach(p => {
            const rest = restante(blk, p.dataset.ced, null);
            const el = p.querySelector('[data-mo-rest]');
            el.textContent = fmt(rest);
            el.style.color = rest < -0.5 ? '#DC2626' : '#15803D';
        });
        // Tarjetas: costo/margen + selector de terceros disponibles.
        blk.querySelectorAll('.mo-obra').forEach(card => {
            let moCarg = 0;
            card.querySelectorAll('.mo-linea [data-mo-monto]').forEach(i => moCarg += moNum(i.value !== undefined ? i.value : i.dataset.val));
            card.querySelector('.mo-obra-cost').innerHTML = costoHTML(card.dataset.obra, moCarg);
            const sel = card.querySelector('[data-mo-add-ced]');
            if (sel) {
                let opts = '<option value="">— Tercero con saldo —</option>';
                personas(blk).forEach(p => {
                    const rest = restante(blk, p.dataset.ced, null);
                    if (rest <= 0) return;
                    opts += '<option value="'+esc(p.dataset.ced)+'">'+esc(p.dataset.nom||p.dataset.ced)+' (queda '+fmt(rest)+')</option>';
                });
                sel.innerHTML = opts;
            }
        });
        const vacio = blk.querySelector('.mo-obra-vacio');
        if (vacio) vacio.style.display = blk.querySelector('.mo-obra') ? 'none' : 'block';
    }
    window.moRecalc = function(el){ const blk = el ? el.closest('.mo-blk') : null; (blk ? [blk] : document.querySelectorAll('.mo-blk')).forEach(recalcBlk); };

    // ── Acciones ──
    window.moFmtOnly = function(el){ const n = moNum(el.value); el.value = n ? n.toLocaleString('es-CO') : ''; };

    window.moLineaInput = function(el){
        const blk = el.closest('.mo-blk');
        const ced = el.closest('.mo-linea').dataset.ced;
        let v = moNum(el.value);
        const max = restante(blk, ced, el);          // tope = lo que le queda por aplicar
        if (v > max) v = max;
        el.value = v ? v.toLocaleString('es-CO') : '';
        recalcBlk(blk);
    };

    window.moDelLinea = function(btn){ const blk = btn.closest('.mo-blk'); btn.closest('.mo-linea').remove(); recalcBlk(blk); };

    window.moQuitarObra = function(btn){ const blk = btn.closest('.mo-blk'); btn.closest('.mo-obra').remove(); recalcBlk(blk); };

    window.moAddObra = function(btn){
        const blk = btn.closest('.mo-blk');
        const inp = blk.querySelector('[data-mo-nueva]');
        const cod = (inp.value || '').trim();
        if (!cod) { alert('Escribe o elige la obra destino.'); return; }
        let card = cardDe(blk, cod);
        if (!card) card = crearCard(blk, cod);
        inp.value = '';
        recalcBlk(blk);
        card.scrollIntoView({block:'nearest'});
    };

    window.moAddLinea = function(btn){
        const blk = btn.closest('.mo-blk');
        const card = btn.closest('.mo-obra');
        const sel = card.querySelector('[data-mo-add-ced]');
        const montoEl = card.querySelector('[data-mo-add-monto]');
        const ced = sel.value;
        if (!ced) { alert('Elige un tercero con saldo por aplicar.'); return; }
        // Si ya hay una línea de ese tercero en esta obra, se suma sobre ella.
        let linea = [...card.querySelectorAll('.mo-linea')].find(l => l.dataset.ced === ced);
        const max = restante(blk, ced, linea ? linea.querySelector('[data-mo-monto]') : null);
        let v = moNum(montoEl.value);
        if (v <= 0) { alert('Escribe el monto a aplicar.'); return; }
        if (v > max) v = max;
        if (v <= 0) { alert('Ese tercero ya no tiene saldo por aplicar.'); return; }
        if (linea) {
            const mi = linea.querySelector('[data-mo-monto]');
            mi.value = (moNum(mi.value) + v).toLocaleString('es-CO');
        } else {
            card.querySelector('[data-mo-lineas]').appendChild(crearLinea(card.dataset.obra, ced, nombreDe(blk, ced), v));
        }
        sel.value = ''; montoEl.value = '';
        recalcBlk(blk);
    };

    // ── Carga inicial: reconstruye las tarjetas desde lo guardado ──
    function initBlk(blk){
        let init = [];
        try { init = JSON.parse(blk.dataset.moInit || '[]'); } catch (e) {}
        init.forEach(l => {
            let card = cardDe(blk, l.obra) || crearCard(blk, l.obra);
            card.querySelector('[data-mo-lineas]').appendChild(crearLinea(l.obra, l.ced, l.nom, l.monto));
        });
        recalcBlk(blk);
    }
    document.querySelectorAll('.mo-blk').forEach(initBlk);

    // Al enviar, deja los montos con solo dígitos para que el backend reciba un número limpio.
    document.addEventListener('submit', e => {
        if (! e.target.classList || ! e.target.classList.contains('mo-form')) return;
        e.target.querySelectorAll('.mo-linea [data-mo-monto]').forEach(i => { if (i.tagName === 'INPUT') i.value = String(moNum(i.value)); });
    });
})();
</script>
@endonce
