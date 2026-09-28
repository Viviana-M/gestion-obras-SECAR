{{-- Sección integrada: Distribución de mano de obra por tercero (reemplaza al módulo aparte).
     Reparte la MO de cada persona (tercero) de una bolsa a las obras destino, con panel en vivo
     "Cómo queda el proyecto". Reutiliza los endpoints operativo.mano-obra.* --}}
@php
    $moFmt = fn ($n) => '$'.number_format((float) $n, 0, ',', '.');
    $moMeses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $moPrefill = [];
    foreach ($moGuardadas as $ter => $filas) {
        foreach ($filas as $f) {
            $moPrefill[$ter][$f->obra_destino] = ($moPrefill[$ter][$f->obra_destino] ?? 0) + (float) $f->monto;
        }
    }
    $moRow = 0;
    $moPuede = $puedeEditar ?? auth()->user()->puedeEditarModulo('operacion');
@endphp

<div class="card" style="padding:0;margin-top:1.5rem;overflow:hidden">
    <div style="background:#1B3F6E;color:white;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="font-size:14px;font-weight:700">🧑‍🔧 Mano de obra directa por persona</div>
        <div style="font-size:11.5px;opacity:.85">Personas del maestro de mano de obra directa con MO en la bolsa (salario + PILA). Las demás ya están distribuidas.</div>
    </div>

    <div style="padding:14px 16px">
        {{-- Selector de bolsa origen (recarga la página conservando período/depto) --}}
        <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0 0 12px">
            <input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
            @if($depEfectivo)<input type="hidden" name="departamento" value="{{ $depEfectivo }}">@endif
            <div>
                <label style="font-size:11px;color:#6B7280;display:block;margin-bottom:3px">Bolsa (origen de la MO)</label>
                <select name="mo_bolsa" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:white">
                    @foreach($moBolsas as $b)
                    <option value="{{ $b->codigo }}" {{ $moBolsa === $b->codigo ? 'selected' : '' }}>{{ $b->codigo }} — {{ $b->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <a href="{{ route('operativo.mano-obra.resumen', ['bolsa'=>$moBolsa,'mes'=>$mes,'anio'=>$anio]) }}"
               style="padding:7px 14px;background:white;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;color:#1B3F6E;text-decoration:none;height:36px;display:inline-flex;align-items:center">📊 Resumen por obra</a>
            @if($moHayMesAnterior && $moPuede)
            <button type="button" onclick="document.getElementById('mo-form-precargar').submit()"
                    style="padding:7px 14px;background:#EFF6FF;border:1px solid #1B3F6E;border-radius:8px;font-size:13px;color:#1B3F6E;cursor:pointer">⤵ Precargar del mes anterior</button>
            @endif
        </form>
        @if($moHayMesAnterior && $moPuede)
        <form id="mo-form-precargar" method="POST" action="{{ route('operativo.mano-obra.precargar') }}" style="display:none"
              onsubmit="return confirm('¿Precargar la distribución de {{ $moMeses[$moMesAnt] ?? $moMesAnt }} {{ $moAnioAnt }}? Reparte el saldo actual de cada persona en las mismas obras y proporciones del mes anterior. Reemplaza lo cargado este período. Podrás ajustar antes de aplicar.');">
            @csrf<input type="hidden" name="bolsa" value="{{ $moBolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
        </form>
        @endif

        @if(empty($moSaldos))
        <div style="text-align:center;color:#9CA3AF;padding:1.5rem;font-size:13px">No hay saldo de mano de obra por repartir en esta bolsa y período.</div>
        @else

        <datalist id="mo-obras-list">
            @foreach($moObrasInfo as $cod => $inf)<option value="{{ $cod }}">{{ $cod }} — {{ $inf['nombre'] }}</option>@endforeach
        </datalist>

        <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:16px;align-items:start">
            {{-- Columna izquierda: matriz por tercero --}}
            <div>
                <form method="POST" action="{{ route('operativo.mano-obra.guardar') }}" id="mo-form">
                    @csrf
                    <input type="hidden" name="bolsa" value="{{ $moBolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
                    @foreach($moSaldos as $t)
                    @php $pre = $moPrefill[$t['tercero']] ?? ['' => 0]; @endphp
                    <div style="border:1px solid #E5E7EB;border-radius:8px;margin-bottom:8px" data-mo-card data-mo-saldo="{{ $t['saldo'] }}">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 12px;background:#F9FAFB;border-bottom:1px solid #E5E7EB;flex-wrap:wrap">
                            <div><span style="font-weight:600;color:#1B3F6E;font-size:13px">{{ $t['nombre'] ?: $t['tercero'] }}</span>
                                <span style="font-size:10px;color:#9CA3AF;font-family:monospace"> · {{ $t['doc'] ?: $t['tercero'] }}</span></div>
                            <div style="font-size:11.5px;color:#374151">Saldo: <b>{{ $moFmt($t['saldo']) }}</b> · Por asignar: <b data-mo-pend style="color:#15803D">{{ $moFmt($t['saldo']) }}</b></div>
                        </div>
                        <div style="padding:6px 12px">
                            <table style="width:100%;border-collapse:collapse;font-size:12px"><tbody data-mo-rows>
                                @foreach($pre as $obra => $monto)
                                <tr>
                                    <td style="padding:3px 6px 3px 0;width:48%">
                                        <input list="mo-obras-list" name="asignaciones[{{ $moRow }}][obra]" value="{{ $obra }}" placeholder="Obra" data-mo-obra
                                               {{ $moPuede ? '' : 'disabled' }} style="width:100%;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px">
                                        <input type="hidden" name="asignaciones[{{ $moRow }}][tercero]" value="{{ $t['tercero'] }}">
                                    </td>
                                    <td style="padding:3px 6px;width:32%">
                                        <input type="number" step="0.01" min="0" name="asignaciones[{{ $moRow }}][monto]" value="{{ $monto ? round($monto,2) : '' }}" placeholder="Monto"
                                               data-mo-monto oninput="moRecalc()" {{ $moPuede ? '' : 'disabled' }} style="width:100%;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right">
                                    </td>
                                    <td style="padding:3px 0 3px 6px;width:24px;text-align:center">
                                        @if($moPuede)<button type="button" onclick="moQuitar(this)" style="border:none;background:#FEF2F2;color:#DC2626;width:22px;height:22px;border-radius:6px;cursor:pointer">×</button>@endif
                                    </td>
                                </tr>
                                @php $moRow++; @endphp
                                @endforeach
                            </tbody></table>
                            @if($moPuede)<button type="button" onclick="moAgregar(this,'{{ $t['tercero'] }}')" style="margin-top:2px;padding:3px 10px;background:white;border:1px dashed #1B3F6E;border-radius:6px;font-size:11px;color:#1B3F6E;cursor:pointer">+ obra</button>@endif
                        </div>
                    </div>
                    @endforeach

                    @if($moPuede)
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                        <button type="submit" style="padding:8px 16px;background:#1B3F6E;color:white;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer">💾 Guardar mano de obra</button>
                    </div>
                    @endif
                </form>

                @if($moPuede)
                <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:flex-end;border-top:1px solid #F3F4F6;padding-top:10px">
                    <form method="GET" action="{{ route('operativo.mano-obra.plano') }}" style="display:flex;gap:6px;align-items:flex-end;margin:0">
                        <input type="hidden" name="bolsa" value="{{ $moBolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
                        <div><label style="font-size:10px;color:#6B7280;display:block">N° doc</label><input type="number" name="documento" min="1" value="1" style="width:80px;padding:6px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"></div>
                        <button type="submit" style="padding:7px 14px;background:#16A34A;color:white;border:none;border-radius:8px;font-size:12.5px;cursor:pointer;height:34px">⬇ Plano</button>
                    </form>
                    <form method="POST" action="{{ route('operativo.mano-obra.aplicar') }}" style="margin:0"
                          onsubmit="return confirm('¿Aplicar en el sistema la MO guardada de esta bolsa? Partida doble 14→61 (origen distribucion_plano), idempotente. Se puede deshacer en «Planos aplicados».');">
                        @csrf<input type="hidden" name="bolsa" value="{{ $moBolsa }}"><input type="hidden" name="mes" value="{{ $mes }}"><input type="hidden" name="anio" value="{{ $anio }}">
                        <button type="submit" style="padding:7px 14px;background:#15803D;color:white;border:none;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;height:34px">✓ Aplicar en el sistema</button>
                    </form>
                </div>
                @endif
            </div>

            {{-- Columna derecha: "Cómo queda el proyecto" (en vivo) --}}
            <div>
                <div style="font-size:12px;font-weight:700;color:#1B3F6E;margin-bottom:6px">Cómo queda el proyecto</div>
                <div id="mo-cards" style="display:flex;flex-direction:column;gap:8px"></div>
                <div id="mo-cards-vacio" style="color:#9CA3AF;font-size:12px;padding:8px">Asigna MO a una obra para ver su resultado.</div>
            </div>
        </div>
        @endif
    </div>
</div>

@if(!empty($moSaldos))
<script>
(function(){
    const moObras = @json($moObrasInfo);
    const fmt = (n) => '$' + Math.round(n).toLocaleString('es-CO');
    const pct1 = (n) => (Math.round(n*10)/10).toLocaleString('es-CO',{minimumFractionDigits:1,maximumFractionDigits:1}) + '%';
    let moIdx = {{ $moRow }};

    window.moRecalc = function(){
        // Pendiente por asignar por tercero.
        document.querySelectorAll('[data-mo-card]').forEach(card => {
            const saldo = parseFloat(card.dataset.moSaldo)||0;
            let suma = 0;
            card.querySelectorAll('[data-mo-monto]').forEach(i => suma += (parseFloat(i.value)||0));
            const p = card.querySelector('[data-mo-pend]');
            const rest = saldo - suma;
            p.textContent = fmt(rest);
            p.style.color = rest < -0.5 ? '#DC2626' : '#15803D';
        });
        // Agregado por obra destino.
        const porObra = {};
        document.querySelectorAll('#mo-form [data-mo-monto]').forEach(inp => {
            const tr = inp.closest('tr');
            const obra = (tr.querySelector('[data-mo-obra]').value || '').trim();
            const m = parseFloat(inp.value)||0;
            if (!obra || m <= 0) return;
            porObra[obra] = (porObra[obra]||0) + m;
        });
        const cont = document.getElementById('mo-cards');
        const vacio = document.getElementById('mo-cards-vacio');
        cont.innerHTML = '';
        const obras = Object.keys(porObra).sort((a,b)=>porObra[b]-porObra[a]);
        vacio.style.display = obras.length ? 'none' : 'block';
        obras.forEach(cod => {
            const info = moObras[cod] || {nombre:'', ingreso:0, costo_apl:0, inventario:0};
            const moCarg = porObra[cod];
            const materiales = 0;                          // materiales/otros: flujo existente (baseline)
            const totalCarg = moCarg + materiales;
            const costoApl = info.costo_apl + totalCarg;   // costo aplicado del proyecto (con lo nuevo)
            const mcPesos = info.ingreso - costoApl;
            const mcPct = info.ingreso ? (mcPesos/info.ingreso*100) : null;
            const saldoTr = Math.max(0, info.inventario - totalCarg);
            const div = document.createElement('div');
            div.style.cssText = 'border:1px solid #E5E7EB;border-radius:8px;padding:10px 12px;background:#F8FAFC';
            div.innerHTML =
                '<div style="font-weight:600;color:#1B3F6E;font-size:12.5px">'+cod+' <span style="font-weight:400;color:#9CA3AF;font-size:10px">'+(info.nombre||'')+'</span></div>'+
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;font-size:11.5px;color:#374151;margin-top:4px">'+
                  '<div>Ingreso</div><div style="text-align:right">'+fmt(info.ingreso)+'</div>'+
                  '<div>MC$</div><div style="text-align:right;font-weight:600;color:'+(mcPesos<0?'#DC2626':'#15803D')+'">'+fmt(mcPesos)+'</div>'+
                  '<div>MC%</div><div style="text-align:right;font-weight:600;color:'+(mcPct!==null&&mcPct<0?'#DC2626':'#15803D')+'">'+(mcPct!==null?pct1(mcPct):'—')+'</div>'+
                  '<div>Mano de obra cargada</div><div style="text-align:right">'+fmt(moCarg)+'</div>'+
                  '<div>Materiales / otros</div><div style="text-align:right">'+fmt(materiales)+'</div>'+
                  '<div>Total cargado</div><div style="text-align:right;font-weight:600">'+fmt(totalCarg)+'</div>'+
                  '<div>Saldo en tránsito</div><div style="text-align:right">'+fmt(saldoTr)+'</div>'+
                '</div>';
            cont.appendChild(div);
        });
    };

    window.moQuitar = function(btn){ const tr=btn.closest('tr'); tr.parentNode.removeChild(tr); moRecalc(); };
    window.moAgregar = function(btn, tercero){
        const tbody = btn.previousElementSibling.querySelector('[data-mo-rows]');
        const i = moIdx++;
        const tr = document.createElement('tr');
        tr.innerHTML =
            '<td style="padding:3px 6px 3px 0;width:48%"><input list="mo-obras-list" name="asignaciones['+i+'][obra]" placeholder="Obra" data-mo-obra style="width:100%;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px"><input type="hidden" name="asignaciones['+i+'][tercero]" value="'+tercero.replace(/"/g,"&quot;")+'"></td>'+
            '<td style="padding:3px 6px;width:32%"><input type="number" step="0.01" min="0" name="asignaciones['+i+'][monto]" placeholder="Monto" data-mo-monto oninput="moRecalc()" style="width:100%;padding:5px 8px;border:1px solid #E5E7EB;border-radius:6px;font-size:12px;text-align:right"></td>'+
            '<td style="padding:3px 0 3px 6px;width:24px;text-align:center"><button type="button" onclick="moQuitar(this)" style="border:none;background:#FEF2F2;color:#DC2626;width:22px;height:22px;border-radius:6px;cursor:pointer">×</button></td>';
        tbody.appendChild(tr);
    };
    // Recalcular también al cambiar la obra destino.
    document.getElementById('mo-form').addEventListener('input', e => { if (e.target.matches('[data-mo-obra]')) moRecalc(); });
    moRecalc();
})();
</script>
@endif
