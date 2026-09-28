{{-- Distribución de mano de obra por TERCERO dentro del grid de la bolsa (departamento $b).
     Cada cuenta de MO se abre por tercero (saldo real); se asigna a obra(s). Reutiliza los
     endpoints operativo.mano-obra.* (por departamento). Vars heredadas del padre:
     $mes,$anio,$moGuardadas,$moObras,$moObrasInfo,$moHayMesAnterior,$moMesAnt,$moAnioAnt,$puedeEditar --}}
@php
    $moFmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $puede = $puedeEditar ?? auth()->user()->puedeEditarModulo('operacion');
    $moLineas = collect($b['lineas'])->filter(fn ($l) => ! empty($l['es_mo']) && ! empty($l['terceros']))->values();
    $dep = $b['codigo'];
    $i = 0;
@endphp

@if($moLineas->isNotEmpty())
<div class="mo-blk" data-depto="{{ $dep }}" style="border-top:1px dashed #FDE68A;background:#FFFDF5;padding:10px 12px">
    <div style="font-size:11px;font-weight:700;color:#92400E;margin-bottom:8px">🧑‍🔧 MANO DE OBRA POR TERCERO — asignar a obra destino</div>

    @unless($puede)
        <div style="font-size:11px;color:#92400E;background:#FEF9C3;border:1px solid #FDE68A;border-radius:6px;padding:6px 10px;margin-bottom:8px">Solo lectura: para asignar, Contabilidad debe abrir el cierre del mes.</div>
    @endunless

    <datalist id="mo-obras-list">
        @foreach($moObras as $o)<option value="{{ $o->codigo_proyecto }}">{{ $o->codigo_proyecto }} — {{ $o->nombre_obra }}</option>@endforeach
    </datalist>

    <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:14px;align-items:start">
        {{-- Izquierda: terceros por cuenta de MO --}}
        <div>
            {{-- Barra de asignación en bloque --}}
            @if($puede)
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:6px;font-size:11px;color:#6B7280">
                <span>En bloque:</span>
                <input list="mo-obras-list" placeholder="Obra destino" data-mo-bulk-obra style="padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                <button type="button" onclick="moBulk(this)" style="padding:4px 10px;background:white;border:1px solid #1B3F6E;border-radius:6px;font-size:11px;color:#1B3F6E;cursor:pointer">Asignar seleccionados</button>
            </div>
            @endif

            <form method="POST" action="{{ route('operativo.mano-obra.guardar') }}" class="mo-form">
                @csrf
                <input type="hidden" name="departamento" value="{{ $dep }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
                @foreach($moLineas as $l)
                <div style="margin-bottom:8px">
                    <div style="font-size:11px;color:#374151;font-weight:600">
                        <span style="font-family:monospace">{{ $l['un_codigo'] }} · {{ $l['cuenta_14'] }}</span>
                        <span style="color:#9CA3AF;font-weight:400">{{ \Illuminate\Support\Str::limit($l['nombre'], 28) }}</span>
                    </div>
                    @foreach($l['terceros'] as $t)
                    @php $pre = $moGuardadas[$l['un_codigo'].'|'.$l['cuenta_14'].'|'.$t['tercero']] ?? ['' => 0]; @endphp
                    <div class="mo-ter" data-saldo="{{ $t['saldo'] }}" style="border:1px solid #FDE68A;border-radius:6px;margin:4px 0;padding:5px 8px;background:#fff">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            @if($puede)<input type="checkbox" data-mo-check title="Seleccionar para asignación en bloque">@endif
                            <span style="font-size:11.5px;color:#1B3F6E;font-weight:600">{{ $t['nombre'] ?: $t['tercero'] }}</span>
                            <span style="font-size:10px;color:#9CA3AF;font-family:monospace">{{ $t['doc'] ?: $t['tercero'] }}</span>
                            <span style="margin-left:auto;font-size:11px;color:#374151">Saldo <b>{{ $moFmt($t['saldo']) }}</b> · Por asignar <b data-mo-pend style="color:#15803D">{{ $moFmt($t['saldo']) }}</b></span>
                        </div>
                        <div data-mo-rows>
                            @foreach($pre as $obra => $monto)
                            <div style="display:flex;gap:5px;margin-top:4px">
                                <input list="mo-obras-list" name="asignaciones[{{ $i }}][obra]" value="{{ $obra }}" placeholder="Obra destino" data-mo-obra {{ $puede ? '' : 'disabled' }}
                                       style="flex:1;padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                                <input type="number" step="0.01" min="0" name="asignaciones[{{ $i }}][monto]" value="{{ $monto ? round($monto,2) : '' }}" placeholder="Monto" data-mo-monto oninput="moRecalc(this)" {{ $puede ? '' : 'disabled' }}
                                       style="width:110px;padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px;text-align:right">
                                <input type="hidden" name="asignaciones[{{ $i }}][un]" value="{{ $l['un_codigo'] }}">
                                <input type="hidden" name="asignaciones[{{ $i }}][cuenta_14]" value="{{ $l['cuenta_14'] }}">
                                <input type="hidden" name="asignaciones[{{ $i }}][tercero]" value="{{ $t['tercero'] }}">
                                <input type="hidden" name="asignaciones[{{ $i }}][tercero_doc]" value="{{ $t['doc'] }}">
                                <input type="hidden" name="asignaciones[{{ $i }}][tercero_nombre]" value="{{ $t['nombre'] }}">
                                @if($puede)<button type="button" onclick="moDelRow(this)" style="border:none;background:#FEF2F2;color:#DC2626;width:24px;border-radius:6px;cursor:pointer">×</button>@endif
                            </div>
                            @php $i++; @endphp
                            @endforeach
                        </div>
                        @if($puede)<button type="button" onclick="moAddRow(this)" data-un="{{ $l['un_codigo'] }}" data-cta="{{ $l['cuenta_14'] }}" data-ter="{{ $t['tercero'] }}" data-doc="{{ $t['doc'] }}" data-nom="{{ $t['nombre'] }}" style="margin-top:3px;padding:2px 8px;background:white;border:1px dashed #1B3F6E;border-radius:6px;font-size:10px;color:#1B3F6E;cursor:pointer">+ obra</button>@endif
                    </div>
                    @endforeach
                </div>
                @endforeach
                @if($puede)
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                    <button type="submit" style="padding:6px 14px;background:#1B3F6E;color:white;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer">💾 Guardar MO</button>
                    @if($moHayMesAnterior)
                    <button type="button" onclick="this.closest('.mo-blk').querySelector('.mo-precargar').submit()" style="padding:6px 12px;background:#EFF6FF;border:1px solid #1B3F6E;border-radius:6px;font-size:12px;color:#1B3F6E;cursor:pointer">⤵ Precargar mes anterior</button>
                    @endif
                </div>
                @endif
            </form>

            @if($puede)
            <form class="mo-precargar" method="POST" action="{{ route('operativo.mano-obra.precargar') }}" style="display:none"
                  onsubmit="return confirm('¿Precargar la distribución de {{ $meses[$moMesAnt] ?? $moMesAnt }} {{ $moAnioAnt }}? Reparte el saldo actual en las mismas obras/proporciones del mes anterior. Reemplaza lo cargado este período.');">
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

        {{-- Derecha: "Cómo queda el proyecto" (en vivo) --}}
        <div>
            <div style="font-size:11px;font-weight:700;color:#1B3F6E;margin-bottom:6px">Cómo queda el proyecto</div>
            <div class="mo-cards" style="display:flex;flex-direction:column;gap:6px"></div>
            <div class="mo-cards-vacio" style="color:#9CA3AF;font-size:11px;padding:6px">Asigna MO a una obra para ver su resultado.</div>
        </div>
    </div>
</div>
@endif

@once
<script>
window.MO_OBRAS = @json($moObrasInfo);
(function(){
    const fmt = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const pct1 = (n) => (Math.round(n*10)/10).toLocaleString('es-CO',{minimumFractionDigits:1,maximumFractionDigits:1}) + '%';
    let moSeq = 100000;

    window.moRecalc = function(el){
        const blk = el ? el.closest('.mo-blk') : null;
        (blk ? [blk] : document.querySelectorAll('.mo-blk')).forEach(recalcBlk);
    };
    function recalcBlk(blk){
        blk.querySelectorAll('.mo-ter').forEach(ter => {
            const saldo = parseFloat(ter.dataset.saldo)||0;
            let suma = 0; ter.querySelectorAll('[data-mo-monto]').forEach(i => suma += (parseFloat(i.value)||0));
            const p = ter.querySelector('[data-mo-pend]');
            const rest = saldo - suma; p.textContent = fmt(rest);
            p.style.color = rest < -0.5 ? '#DC2626' : '#15803D';
        });
        const porObra = {};
        blk.querySelectorAll('.mo-form [data-mo-monto]').forEach(inp => {
            const row = inp.closest('div'); const obra = (row.querySelector('[data-mo-obra]').value||'').trim();
            const m = parseFloat(inp.value)||0; if (!obra || m<=0) return;
            porObra[obra] = (porObra[obra]||0) + m;
        });
        const cont = blk.querySelector('.mo-cards'), vacio = blk.querySelector('.mo-cards-vacio');
        cont.innerHTML = '';
        const obras = Object.keys(porObra).sort((a,b)=>porObra[b]-porObra[a]);
        vacio.style.display = obras.length ? 'none':'block';
        obras.forEach(cod => {
            const info = (window.MO_OBRAS[cod])||{nombre:'',ingreso:0,costo_apl:0,inventario:0};
            const moCarg = porObra[cod], mat = 0, totalCarg = moCarg + mat;
            const costoApl = info.costo_apl + totalCarg, mc = info.ingreso - costoApl;
            const mcp = info.ingreso ? (mc/info.ingreso*100) : null;
            const saldoTr = Math.max(0, info.inventario - totalCarg);
            const d = document.createElement('div');
            d.style.cssText = 'border:1px solid #E5E7EB;border-radius:6px;padding:6px 8px;background:#fff';
            d.innerHTML = '<div style="font-weight:600;color:#1B3F6E;font-size:11px">'+cod+' <span style="font-weight:400;color:#9CA3AF">'+(info.nombre||'')+'</span></div>'+
                '<div style="display:grid;grid-template-columns:1fr auto;gap:1px 8px;font-size:10.5px;color:#374151;margin-top:3px">'+
                '<div>Ingreso</div><div style="text-align:right">'+fmt(info.ingreso)+'</div>'+
                '<div>MC$</div><div style="text-align:right;font-weight:600;color:'+(mc<0?'#DC2626':'#15803D')+'">'+fmt(mc)+'</div>'+
                '<div>MC%</div><div style="text-align:right;font-weight:600;color:'+(mcp!==null&&mcp<0?'#DC2626':'#15803D')+'">'+(mcp!==null?pct1(mcp):'—')+'</div>'+
                '<div>Mano de obra cargada</div><div style="text-align:right">'+fmt(moCarg)+'</div>'+
                '<div>Materiales / otros</div><div style="text-align:right">'+fmt(mat)+'</div>'+
                '<div>Total cargado</div><div style="text-align:right;font-weight:600">'+fmt(totalCarg)+'</div>'+
                '<div>Saldo en tránsito</div><div style="text-align:right">'+fmt(saldoTr)+'</div></div>';
            cont.appendChild(d);
        });
    }
    window.moDelRow = function(btn){ const blk=btn.closest('.mo-blk'); btn.closest('div').remove(); recalcBlk(blk); };
    window.moAddRow = function(btn){
        const rows = btn.previousElementSibling; const i = moSeq++;
        const d = btn.dataset;
        const row = document.createElement('div');
        row.style.cssText='display:flex;gap:5px;margin-top:4px';
        const esc = s => (s||'').replace(/"/g,'&quot;');
        row.innerHTML =
            '<input list="mo-obras-list" name="asignaciones['+i+'][obra]" placeholder="Obra destino" data-mo-obra style="flex:1;padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">'+
            '<input type="number" step="0.01" min="0" name="asignaciones['+i+'][monto]" placeholder="Monto" data-mo-monto oninput="moRecalc(this)" style="width:110px;padding:4px 6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px;text-align:right">'+
            '<input type="hidden" name="asignaciones['+i+'][un]" value="'+esc(d.un)+'"><input type="hidden" name="asignaciones['+i+'][cuenta_14]" value="'+esc(d.cta)+'">'+
            '<input type="hidden" name="asignaciones['+i+'][tercero]" value="'+esc(d.ter)+'"><input type="hidden" name="asignaciones['+i+'][tercero_doc]" value="'+esc(d.doc)+'"><input type="hidden" name="asignaciones['+i+'][tercero_nombre]" value="'+esc(d.nom)+'">'+
            '<button type="button" onclick="moDelRow(this)" style="border:none;background:#FEF2F2;color:#DC2626;width:24px;border-radius:6px;cursor:pointer">×</button>';
        rows.appendChild(row);
    };
    window.moBulk = function(btn){
        const blk = btn.closest('.mo-blk');
        const obra = (blk.querySelector('[data-mo-bulk-obra]').value||'').trim();
        if (!obra) { alert('Escribe la obra destino para asignar en bloque.'); return; }
        blk.querySelectorAll('.mo-ter').forEach(ter => {
            const chk = ter.querySelector('[data-mo-check]'); if (!chk || !chk.checked) return;
            const first = ter.querySelector('[data-mo-obra]');
            if (first && !first.value.trim()) {
                first.value = obra;
                const monto = first.parentElement.querySelector('[data-mo-monto]');
                const saldo = parseFloat(ter.dataset.saldo)||0;
                if (monto && !monto.value) monto.value = Math.round(saldo);
            }
            chk.checked = false;
        });
        recalcBlk(blk);
    };
    document.addEventListener('input', e => { if (e.target.matches('[data-mo-obra]')) moRecalc(e.target); });
    document.querySelectorAll('.mo-blk').forEach(recalcBlk);
})();
</script>
@endonce
