@php $ce = $colEstado[$o['estado']] ?? ['#F3F4F6','#6B7280']; @endphp
<div class="card obra-card" id="card-{{ $cod }}" data-buscar="{{ strtolower($cod.' '.($o['nombre'] ?? '').' '.($o['cliente'] ?? '')) }}" data-sin-ingreso="{{ $o['requiere_autorizacion'] ? '1' : '0' }}" style="margin-bottom:10px;padding:0;overflow:hidden">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;cursor:pointer;flex-wrap:wrap" data-toggle-obra="{{ $cod }}">
        <div style="min-width:0">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span id="dot-{{ $cod }}" style="width:9px;height:9px;border-radius:50%;background:{{ $colSem[$o['semaforo']] }};display:inline-block"></span>
                {{-- Encabezado: código - nombre del proyecto · cliente.
                     Nombre completo (hace wrap si es largo) + title como tooltip; no se pierde texto. --}}
                <span style="font-weight:600;color:#1B3F6E">{{ $cod }}</span>
                <span style="font-size:12px;color:#374151;word-break:break-word" title="{{ $o['nombre'] }}">- {{ $o['nombre'] }}</span>
                @if(!empty($o['cliente']))
                    <span style="font-size:11px;color:#6B7280;word-break:break-word" title="{{ $o['cliente'] }}">· 🏢 {{ $o['cliente'] }}</span>
                @endif
                <select name="estado_obra[{{ $cod }}]" onclick="event.stopPropagation()" onchange="cambiarEstado('{{ $cod }}', this.value)"
                    style="font-size:11px;padding:3px 8px;border-radius:8px;border:1px solid {{ $ce[1] }};background:{{ $ce[0] }};color:{{ $ce[1] }};font-weight:500;cursor:pointer">
                    <option value="abierta" {{ $o['estado']=='abierta'?'selected':'' }}>Abierta</option>
                    <option value="parcial" {{ $o['estado']=='parcial'?'selected':'' }}>Parcial</option>
                    <option value="cerrada" {{ $o['estado']=='cerrada'?'selected':'' }}>Cerrada</option>
                </select>
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
            <div style="font-weight:600;color:#854D0E" id="aplicar-tot-{{ $cod }}">{{ $fmt($o['sum_aplicar'] + ($o['sum_bolsa'] ?? 0) + $o['sum_prov']) }}</div>
        </div>
    </div>

    {{-- RENTABILIDAD DEL MES (primero: todo lo del mes; MC dinámico al aplicar inventario) --}}
    <div style="background:#FFFBEB;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#854D0E;letter-spacing:.4px;margin-bottom:6px">RENTABILIDAD DEL MES · {{ mb_strtoupper($mesNombre) }}</div>
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px">
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Ingresos del mes</div>
                <div style="font-size:14px;font-weight:600;color:#854D0E">{{ $fmt($o['ingreso_mes']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Costo aplicado del mes</div>
                <div style="font-size:14px;font-weight:600;color:#374151">{{ $fmt($o['costo_mes_c6']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Inventario en tránsito aplicado</div>
                <div style="font-size:14px;font-weight:600;color:#1B3F6E" id="aplic6-{{ $cod }}">{{ $fmt($o['aplicado_mes']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Provisión (costo sin aplicar)</div>
                <div style="font-size:14px;font-weight:600;color:#B45309" id="provsa-{{ $cod }}">{{ $fmt($o['costo_sin_aplicar'] ?? 0) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Margen del mes ($)</div>
                <div style="font-size:14px;font-weight:600;color:{{ $o['mc_mes_pesos'] >= 0 ? '#15803D' : '#DC2626' }}" id="mcmes-{{ $cod }}">{{ $fmt($o['mc_mes_pesos']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#B45309;line-height:1.3">Rentabilidad % MC</div>
                <div style="font-size:14px;font-weight:600;color:#854D0E" id="mcpct-{{ $cod }}">{{ $o['mc_mes_pct'] === null ? '—' : $o['mc_mes_pct'].'%' }}</div>
            </div>
        </div>
    </div>

    {{-- ACUMULADO DE CIERRE (después: acumulado hasta este mes incluyendo la distribución) --}}
    <div style="background:#F9FAFB;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#6B7280;letter-spacing:.4px;margin-bottom:6px">ACUMULADO A {{ mb_strtoupper($mesNombre) }} · INCLUYE ESTA DISTRIBUCIÓN</div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Facturado acumulado</div>
                <div style="font-size:14px;font-weight:600;color:#1B3F6E">{{ $fmt($o['ingreso_acum']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Costo acumulado (con distribución)</div>
                <div style="font-size:14px;font-weight:600;color:#374151" id="costoacum-{{ $cod }}">{{ $fmt($o['costo_acum_cierre']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">Margen acumulado ($)</div>
                <div style="font-size:14px;font-weight:600;color:{{ $o['margen_acum_cierre_pesos'] >= 0 ? '#15803D' : '#DC2626' }}" id="mcacum-{{ $cod }}">{{ $fmt($o['margen_acum_cierre_pesos']) }}</div>
            </div>
            <div>
                <div style="font-size:9px;color:#9CA3AF;line-height:1.3">MC % acumulado</div>
                <div style="font-size:14px;font-weight:600;color:#374151" id="mcacumpct-{{ $cod }}">{{ $o['mc_acum_cierre_pct'] === null ? '—' : $o['mc_acum_cierre_pct'].'%' }}</div>
            </div>
        </div>
    </div>

    {{-- PROYECCIÓN DE RENTABILIDAD (oferta comercial vs realidad) --}}
    @php
        $mcp = $o['pr_mc_proy']; $ofc = $o['pr_mc_ofertado'];
        $colProy = '#312E81';
        if ($mcp !== null && $ofc !== null) $colProy = $mcp >= $ofc ? '#15803D' : '#DC2626';
        $deltaMc = ($mcp !== null && $ofc !== null) ? round($mcp - $ofc, 1) : null;
    @endphp
    <div style="background:#EEF2FF;padding:10px 16px;border-top:1px solid #E5E7EB">
        <div style="font-size:9px;font-weight:700;color:#4338CA;letter-spacing:.4px;margin-bottom:8px">PROYECCIÓN DE RENTABILIDAD · OFERTA vs REALIDAD</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">

            {{-- Grupo 1 · RENTABILIDAD (lo importante: ofertado vs proyectado) --}}
            <div style="flex:1;min-width:220px;background:#fff;border:1px solid #E0E7FF;border-radius:8px;padding:10px 12px">
                <div style="font-size:9px;font-weight:700;color:#6366F1;letter-spacing:.3px;margin-bottom:8px">RENTABILIDAD · MC %</div>
                <div style="display:flex;align-items:center;gap:12px">
                    <div>
                        <div style="font-size:9px;color:#9CA3AF">Ofertado</div>
                        <div style="font-size:20px;font-weight:700;color:#312E81">{{ $ofc === null ? '—' : $ofc.'%' }}</div>
                    </div>
                    <div style="font-size:16px;color:#C7D2FE">→</div>
                    <div>
                        <div style="font-size:9px;color:#9CA3AF">Proyectado (real)</div>
                        <div style="font-size:20px;font-weight:700;color:{{ $colProy }}">{{ $mcp === null ? '—' : $mcp.'%' }}</div>
                    </div>
                </div>
                @if($deltaMc !== null)
                <div style="font-size:10px;font-weight:600;margin-top:8px;color:{{ $deltaMc >= 0 ? '#15803D' : '#DC2626' }}">
                    {{ $deltaMc >= 0 ? '▲ +'.$deltaMc : '▼ '.$deltaMc }} pts vs oferta
                </div>
                @else
                <div style="font-size:10px;color:#9CA3AF;margin-top:8px">Sin oferta registrada</div>
                @endif
                <div style="font-size:9px;color:#818CF8;margin-top:6px;line-height:1.4">
                    <b>MC % = (Ingreso proyectado − Costo real) ÷ Ingreso proyectado</b>
                </div>
                <div style="font-size:9px;color:#A5B4FC;margin-top:2px;line-height:1.4">
                    Ingreso proyectado = valor oferta comercial · Costo real = costo acum + inventario en tránsito
                </div>
            </div>

            {{-- Grupo 2 · FACTURACIÓN --}}
            <div style="flex:1;min-width:230px;background:#fff;border:1px solid #E0E7FF;border-radius:8px;padding:10px 12px">
                <div style="font-size:9px;font-weight:700;color:#6366F1;letter-spacing:.3px;margin-bottom:6px">FACTURACIÓN</div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0">
                    <span style="font-size:10px;color:#6B7280">Valor oferta comercial</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $fmt($o['pr_valor_oferta']) }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0;border-top:1px solid #F1F5F9">
                    <span style="font-size:10px;color:#6B7280">Diferencia por facturar</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $fmt($o['pr_dif_facturar']) }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0;border-top:1px solid #F1F5F9">
                    <span style="font-size:10px;color:#6B7280">Avance de facturación</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $o['pr_avance_fact'] === null ? '—' : $o['pr_avance_fact'].'%' }}</span>
                </div>
                @if($o['pr_avance_fact'] !== null)
                <div style="height:5px;border-radius:3px;background:#EEF2FF;overflow:hidden;margin-top:5px">
                    <div style="height:100%;width:{{ min(100, max(0, $o['pr_avance_fact'])) }}%;background:#6366F1"></div>
                </div>
                @endif
            </div>

            {{-- Grupo 3 · COSTO Y EJECUCIÓN --}}
            <div style="flex:1.3;min-width:250px;background:#fff;border:1px solid #E0E7FF;border-radius:8px;padding:10px 12px">
                <div style="font-size:9px;font-weight:700;color:#6366F1;letter-spacing:.3px;margin-bottom:6px">COSTO Y EJECUCIÓN</div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0">
                    <span style="font-size:10px;color:#6B7280">Inventario en tránsito</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $fmt($o['pr_inv_obra']) }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0;border-top:1px solid #F1F5F9">
                    <span style="font-size:10px;color:#374151;font-weight:600">Costo total</span>
                    <span style="font-size:13px;font-weight:700;color:#312E81">{{ $fmt($o['pr_costo_total']) }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0;border-top:1px solid #F1F5F9">
                    <span style="font-size:10px;color:#6B7280">Costo presupuestado</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $fmt($o['pr_costo_presup']) }}</span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:4px 0;border-top:1px solid #F1F5F9">
                    <span style="font-size:10px;color:#6B7280">Avance ejecución obra</span>
                    <span style="font-size:13px;font-weight:600;color:#312E81">{{ $o['pr_avance_ejec'] === null ? '—' : $o['pr_avance_ejec'].'%' }}</span>
                </div>
                @if($o['pr_avance_ejec'] !== null)
                <div style="height:5px;border-radius:3px;background:#EEF2FF;overflow:hidden;margin-top:5px">
                    <div style="height:100%;width:{{ min(100, max(0, $o['pr_avance_ejec'])) }}%;background:#6366F1"></div>
                </div>
                @endif
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
                Este proyecto no tuvo ingreso en el período. Sus cantidades arrancan en $0 pero puedes escribir
                el monto que quieres distribuir en las categorías; ese monto <b>no se aplica todavía</b>: al solicitar
                se envía a gerencia con su impacto en el margen, y solo se aplica (14→6) cuando gerencia lo aprueba.
            </p>
            @if($o['autorizacion_estado'] === 'pendiente')
                <div style="font-size:11px;color:#6B7280">
                    Solicitado: {{ $fmt($o['autorizacion_monto'] ?? 0) }} · Motivo: <i>{{ $o['autorizacion_motivo'] }}</i>
                </div>
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
                    @php
                        // Ítems que componen esta cuenta 14 (agrupados por la cuenta 14 de la llave).
                        $grupoIt   = $o['items_por_cuenta'][$sub['cuenta_14']] ?? null;
                        $tieneItems = $grupoIt && !empty($grupoIt['items']);
                        $itcId     = 'itc-'.$cod.'-'.\Illuminate\Support\Str::slug($sub['cuenta_14']);
                    @endphp
                    <tr style="border-top:1px solid #F3F4F6{{ $tieneItems ? ';cursor:pointer' : '' }}"
                        @if($tieneItems) onclick="toggleItemsCuenta('{{ $itcId }}', this)" title="Ver ítems que componen esta cuenta 14" @endif>
                        <td style="padding:4px 6px;font-family:monospace;color:#9CA3AF;white-space:nowrap">@if($tieneItems)<span class="caret-itc" style="color:#6366F1;display:inline-block;width:10px">▸</span> @endif{{ $sub['cuenta_14'] }}</td>
                        <td style="padding:4px 6px;text-align:center;color:#D1D5DB">→</td>
                        <td style="padding:4px 6px;font-family:monospace;color:{{ $sub['cuenta_61'] === 'SIN HOMOLOGAR' ? '#DC2626' : '#1B3F6E' }}">{{ $sub['cuenta_61'] }}</td>
                        <td style="padding:4px 6px;color:#6B7280">{{ Str::limit($sub['nombre'], 26) }}</td>
                        <td style="padding:4px 6px;text-align:right;color:#854D0E">{{ $fmt($sub['pendiente']) }}</td>
                        <td style="padding:4px 6px;text-align:right">
                            <input type="number" min="0" max="{{ round($sub['pendiente']) }}" step="1"
                                value="{{ round($sub['aplicar']) }}"
                                name="aplicar[{{ $cod }}][{{ $sub['cuenta_14'] }}]"
                                onclick="event.stopPropagation()"
                                data-cod="{{ $cod }}" data-tipo="aplicar" data-bloqueado="{{ $o['bloqueado_ingreso'] ? '1' : '0' }}"
                                data-tope="{{ round($sub['tope'] ?? 0) }}" data-periodo="{{ $sub['periodo'] ?? 0 }}"
                                oninput="capear(this);marcarTocada(this);recalc('{{ $cod }}')"
                                @if($o['bloqueado_ingreso']) title="Se aplicará solo cuando gerencia apruebe la autorización" @endif
                                style="width:100px;padding:3px 6px;border:1px solid {{ $o['bloqueado_ingreso'] ? '#FCA5A5' : '#E5E7EB' }};border-radius:4px;font-size:11px;text-align:right">
                        </td>
                    </tr>
                    @if($tieneItems)
                    @php
                        // Conciliación contra la CUENTA 14 (calculada en el controlador): la suma de
                        // ítems pendientes debe igualar el saldo pendiente acumulado de esta cuenta 14.
                        $saldo14   = (float) $grupoIt['total_cuenta'];
                        $difItc    = (float) $grupoIt['diferencia'];
                        $cuadraItc = (bool) $grupoIt['cuadra'];
                    @endphp
                    <tr id="{{ $itcId }}" style="display:none">
                        <td colspan="6" style="padding:0 2px 10px;background:#FBFCFE">
                            <div style="overflow-x:auto;border:1px solid #E5E7EB;border-radius:8px">
                                <table style="width:100%;border-collapse:collapse;font-size:11px;min-width:860px;background:#fff">
                                    <tr style="color:#9CA3AF;text-align:left;background:#F9FAFB">
                                        <td style="padding:4px 8px">Ítem</td>
                                        <td style="padding:4px 8px">Tipo inventario</td>
                                        <td style="padding:4px 8px">Movimiento</td>
                                        <td style="padding:4px 8px">Tercero</td>
                                        <td style="padding:4px 8px;text-align:right">Cantidad</td>
                                        <td style="padding:4px 8px">Fecha</td>
                                        <td style="padding:4px 8px">N° documento</td>
                                        <td style="padding:4px 8px;text-align:right">Costo</td>
                                        <td style="padding:4px 8px">Estado</td>
                                        <td style="padding:4px 8px"></td>
                                    </tr>
                                    @foreach($grupoIt['items'] as $it)
                                    <tr style="border-top:1px solid #F3F4F6;{{ $it['reintegro'] ? 'color:#DC2626' : '' }}">
                                        <td style="padding:4px 8px">{{ $it['item'] }}</td>
                                        <td style="padding:4px 8px">{{ $it['tipo_inventario'] }}</td>
                                        <td style="padding:4px 8px">{{ $it['movimiento'] }}</td>
                                        <td style="padding:4px 8px">{{ Str::limit($it['tercero'], 26) }}</td>
                                        <td style="padding:4px 8px;text-align:right">{{ $it['cantidad'] !== null ? rtrim(rtrim(number_format($it['cantidad'], 2, '.', ''), '0'), '.') : '' }}</td>
                                        <td style="padding:4px 8px;white-space:nowrap">{{ $it['fecha'] }}</td>
                                        <td style="padding:4px 8px;font-family:monospace">{{ $it['numero_documento'] }}</td>
                                        <td style="padding:4px 8px;text-align:right;font-weight:600">{{ $it['reintegro'] ? '−'.$fmt($it['costo']) : $fmt($it['costo']) }}</td>
                                        <td style="padding:4px 8px;white-space:nowrap">
                                            @if($it['reconocido'])
                                                <span style="font-size:10px;color:#15803D">✅ Reconocido</span>
                                            @elseif($it['monto_reconocido'] > 0.5)
                                                <span style="font-size:10px;color:#B45309" title="Reconocido {{ $fmt($it['monto_reconocido']) }} de {{ $fmt($it['costo']) }}">⏳ Parcial · pend. {{ $fmt($it['pendiente']) }}</span>
                                            @else
                                                <span style="font-size:10px;color:#6B7280">⏳ Pendiente</span>
                                            @endif
                                        </td>
                                        <td style="padding:4px 8px;text-align:right">
                                            <button type="button" onclick="event.stopPropagation();abrirReasignar({{ $it['id'] }}, '{{ $cod }}')" style="font-size:10px;padding:3px 8px;border:1px solid #6366F1;border-radius:6px;color:#4338CA;background:white;cursor:pointer;white-space:nowrap">Reasignar</button>
                                        </td>
                                    </tr>
                                    @endforeach
                                    {{-- CONCILIACIÓN contra el saldo de la cuenta 14 --}}
                                    <tr style="border-top:2px solid #E5E7EB;background:#F9FAFB">
                                        <td colspan="8" style="padding:6px 8px;text-align:right;color:#6B7280">Suma de ítems (salidas − reintegros)</td>
                                        <td colspan="2" style="padding:6px 8px;text-align:right">{{ $fmt($grupoIt['suma_items']) }}</td>
                                    </tr>
                                    <tr style="background:#EFF6FF">
                                        <td colspan="8" style="padding:6px 8px;text-align:right;color:#1B3F6E">Reconocido (reclasificado 14→61)</td>
                                        <td colspan="2" style="padding:6px 8px;text-align:right;color:#1B3F6E">{{ $fmt($grupoIt['reconocido_total']) }}</td>
                                    </tr>
                                    <tr style="font-weight:600;background:#EFF6FF">
                                        <td colspan="8" style="padding:6px 8px;text-align:right;color:#1B3F6E">Pendiente por reconocer (ítems)</td>
                                        <td colspan="2" style="padding:6px 8px;text-align:right;color:#1B3F6E">{{ $fmt($grupoIt['pendiente_total']) }}</td>
                                    </tr>
                                    <tr style="font-weight:600;background:#F9FAFB">
                                        <td colspan="8" style="padding:6px 8px;text-align:right;color:#6B7280">Saldo de la cuenta 14 (pendiente)</td>
                                        <td colspan="2" style="padding:6px 8px;text-align:right;color:#854D0E">{{ $fmt($saldo14) }}</td>
                                    </tr>
                                    <tr style="font-weight:700;background:{{ $cuadraItc ? '#F0FDF4' : '#FEF2F2' }}">
                                        <td colspan="8" style="padding:7px 8px;text-align:right;color:{{ $cuadraItc ? '#15803D' : '#B91C1C' }}">
                                            {{ $cuadraItc ? '✓ Cuadra con la cuenta 14' : '⚠ Diferencia — revisar (ítems faltantes o sin cruzar)' }}
                                        </td>
                                        <td colspan="2" style="padding:7px 8px;text-align:right;color:{{ $cuadraItc ? '#15803D' : '#B91C1C' }}">{{ $fmt($difItc) }}</td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @endif
                    @endforeach
                </table>
            </div>
            @endif
        @endforeach

        <div style="margin-top:14px;border-top:1px dashed #E5E7EB;padding-top:10px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <span style="font-size:12px;font-weight:600;color:#854D0E">Provisiones (costo en tránsito) <span style="font-weight:400;color:#9CA3AF">· se conservan cada mes hasta reversarlas</span></span>
                @if($puedeEditar ?? false)
                <button type="button" onclick="toggleProvForm('{{ $cod }}')" style="font-size:11px;padding:4px 10px;border:1px solid #1B3F6E;border-radius:6px;background:white;color:#1B3F6E;cursor:pointer">+ Provisión</button>
                @endif
            </div>

            <div id="provs-{{ $cod }}">
                @forelse($o['provisiones'] as $pr)
                <div data-prov-id="{{ $pr['id'] }}" data-cod="{{ $cod }}" data-monto="{{ round($pr['monto']) }}" style="display:flex;align-items:center;justify-content:space-between;font-size:11px;padding:5px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px">
                    <span>🧾 <span style="font-family:monospace">{{ $pr['cuenta_14'] }}</span> → <span style="font-family:monospace">{{ $pr['cuenta_26'] }}</span>{{ $pr['descripcion'] ? ' · '.Str::limit($pr['descripcion'], 28) : '' }}
                        <span style="color:#9CA3AF">· activa desde {{ $pr['desde'] }}</span>
                        @if($pr['nueva'])<span style="font-size:9px;padding:1px 6px;border-radius:6px;background:#DCFCE7;color:#15803D;margin-left:4px">nueva</span>@endif
                    </span>
                    <span style="display:flex;align-items:center;gap:10px"><b>{{ $fmt($pr['monto']) }}</b>
                        @if($puedeEditar ?? false)
                        <a href="#" onclick="reversarProvision({{ $pr['id'] }});return false" style="color:#DC2626;text-decoration:none;font-weight:600">Reversar</a>
                        @endif
                    </span>
                </div>
                @empty
                @endforelse
                <div class="prov-vacio-{{ $cod }}" style="font-size:11px;color:#9CA3AF;padding:2px 4px;{{ count($o['provisiones']) ? 'display:none' : '' }}">Sin provisiones activas.</div>
            </div>

            @if($puedeEditar ?? false)
            <div id="provform-{{ $cod }}" style="display:none;margin-top:8px;background:#F9FAFB;border-radius:8px;padding:10px">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                    <div style="flex:2;min-width:180px">
                        <label style="font-size:10px;color:#6B7280;display:block">Cuenta 14 (según lo que provisionan)</label>
                        <select id="prov-cta-{{ $cod }}" style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                            @foreach($catalogo as $cat)
                                <option value="{{ $cat->cuenta_14 }}">{{ $cat->cuenta_14 }} · {{ Str::limit($cat->nombre, 28) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="flex:1;min-width:110px">
                        <label style="font-size:10px;color:#6B7280;display:block">Monto</label>
                        <input type="text" inputmode="numeric" id="prov-monto-{{ $cod }}" placeholder="0" style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                    </div>
                    <div style="flex:2;min-width:140px">
                        <label style="font-size:10px;color:#6B7280;display:block">Descripción</label>
                        <input type="text" id="prov-desc-{{ $cod }}" placeholder="Factura pendiente..." style="width:100%;padding:6px;border:1px solid #E5E7EB;border-radius:6px;font-size:11px">
                    </div>
                    <button type="button" onclick="crearProvision('{{ $cod }}')" style="padding:7px 14px;background:#1B3F6E;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">Agregar</button>
                </div>
            </div>
            @endif
        </div>

        {{-- ASIGNAR DESDE BOLSA DE ÁREA (origen del costo) --}}
        @if(!empty($bolsas) && !$o['bloqueado_ingreso'])
        <div style="margin-top:14px;border-top:1px dashed #FDE68A;padding-top:10px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                <span style="width:9px;height:9px;border-radius:2px;background:#D97706;display:inline-block"></span>
                <span style="font-size:12px;font-weight:600;color:#854D0E">Asignar desde bolsa de área</span>
            </div>

            <div id="asigns-{{ $cod }}">
                @foreach($o['asignaciones_bolsa'] as $i => $ab)
                <div data-bolsa="{{ $ab['bolsa'] }}" data-monto="{{ round($ab['monto']) }}" data-detalle="{{ json_encode($ab['detalle'] ?? []) }}" style="font-size:11px;padding:5px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <span>🡒 Desde <b>{{ $ab['bolsa'] }}</b></span>
                        <span style="display:flex;align-items:center;gap:8px"><b>{{ $fmt($ab['monto']) }}</b>
                            <a href="#" onclick="quitarBolsa(this,'{{ $cod }}');return false" style="color:#DC2626;text-decoration:none">✕</a></span>
                    </div>
                    <div style="margin-top:2px">
                        @foreach(($ab['detalle'] ?? []) as $d)
                            @php $per = (int)($d['periodo'] ?? 0); $mm = $per % 100; $yy = intdiv($per, 100); @endphp
                            <div style="font-size:10px;color:#92400E">· <span style="font-family:monospace">{{ $d['cuenta_14'] }}</span> {{ $per ? sprintf('%02d/%d', $mm, $yy) : '—' }} → <span style="font-family:monospace">{{ $d['cuenta_61'] }}</span>: {{ $fmt($d['monto']) }}</div>
                        @endforeach
                    </div>
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][s{{ $i }}][bolsa]" value="{{ $ab['bolsa'] }}">
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][s{{ $i }}][monto]" value="{{ round($ab['monto']) }}" data-cod="{{ $cod }}" data-tipo="bolsa" data-bolsa="{{ $ab['bolsa'] }}">
                </div>
                @endforeach
            </div>

            @if($puedeEditar)
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
                <div style="flex:2;min-width:200px">
                    <label style="font-size:10px;color:#6B7280;display:block">Bolsa</label>
                    <select id="asignbolsa-cta-{{ $cod }}" onchange="actualizarDispObra('{{ $cod }}')" style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px">
                        <option value="">— Elegir bolsa —</option>
                        @foreach($bolsas as $b)
                            <option value="{{ $b['codigo'] }}">{{ $b['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1;min-width:110px">
                    <label style="font-size:10px;color:#6B7280;display:block">Monto</label>
                    <input type="number" id="asignbolsa-monto-{{ $cod }}" min="0" step="1" placeholder="0" style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px">
                </div>
                <button type="button" onclick="asignarBolsa('{{ $cod }}')" style="padding:7px 14px;background:#D97706;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">Asignar</button>
            </div>
            {{-- Disponible de la bolsa elegida (acumulado al mes), en vivo, sin subir al panel --}}
            <div id="disp-obra-wrap-{{ $cod }}" style="font-size:11px;color:#92400E;margin-top:6px;display:none">
                Disponible en <b id="disp-obra-bolsa-{{ $cod }}">—</b>: <b id="disp-obra-{{ $cod }}">$0</b>
            </div>
            @endif
        </div>
        @endif

        @if($o['total_reversado'] > 0)
        <div style="margin-top:12px;background:#FEF2F2;border-radius:8px;padding:8px 12px;font-size:11px;color:#DC2626">
            ⚠ Reversado de más (alerta, no editable): {{ $fmt($o['total_reversado']) }}
        </div>
        @endif
    </div>
</div>
