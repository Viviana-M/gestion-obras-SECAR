@php $ce = $colEstado[$o['estado']] ?? ['#F3F4F6','#6B7280']; @endphp
<div class="card obra-card" id="card-{{ $cod }}" data-buscar="{{ strtolower($cod.' '.($o['cliente'] ?? '')) }}" data-sin-ingreso="{{ $o['requiere_autorizacion'] ? '1' : '0' }}" style="margin-bottom:10px;padding:0;overflow:hidden">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;cursor:pointer;flex-wrap:wrap" data-toggle-obra="{{ $cod }}">
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
                <span style="font-size:12px;color:#9CA3AF">{{ Str::limit($o['nombre'], 36) }}</span>
                @if(!empty($o['cliente']))
                    <span style="font-size:11px;color:#6B7280">· 🏢 {{ Str::limit($o['cliente'], 28) }}</span>
                @endif
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
                                data-cod="{{ $cod }}" data-tipo="aplicar" data-bloqueado="{{ $o['bloqueado_ingreso'] ? '1' : '0' }}"
                                data-tope="{{ round($sub['tope'] ?? 0) }}" data-periodo="{{ $sub['periodo'] ?? 0 }}"
                                oninput="capear(this);recalc('{{ $cod }}')"
                                @if($o['bloqueado_ingreso']) title="Se aplicará solo cuando gerencia apruebe la autorización" @endif
                                style="width:100px;padding:3px 6px;border:1px solid {{ $o['bloqueado_ingreso'] ? '#FCA5A5' : '#E5E7EB' }};border-radius:4px;font-size:11px;text-align:right">
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

        {{-- ASIGNAR DESDE BOLSA DE ÁREA (origen del costo) --}}
        @if(!empty($bolsas) && !$o['bloqueado_ingreso'])
        <div style="margin-top:14px;border-top:1px dashed #FDE68A;padding-top:10px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                <span style="width:9px;height:9px;border-radius:2px;background:#D97706;display:inline-block"></span>
                <span style="font-size:12px;font-weight:600;color:#854D0E">Asignar desde bolsa de área</span>
            </div>

            <div id="asigns-{{ $cod }}">
                @foreach($o['asignaciones_bolsa'] as $i => $ab)
                <div data-bolsa="{{ $ab['bolsa'] }}" data-monto="{{ round($ab['monto']) }}" style="display:flex;align-items:center;justify-content:space-between;font-size:11px;padding:5px 8px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:6px;margin-top:4px">
                    <span>🡒 Desde <b>{{ $ab['bolsa'] }}</b></span>
                    <span style="display:flex;align-items:center;gap:8px"><b>{{ $fmt($ab['monto']) }}</b>
                        <a href="#" onclick="quitarBolsa(this,'{{ $cod }}');return false" style="color:#DC2626;text-decoration:none">✕</a></span>
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][s{{ $i }}][bolsa]" value="{{ $ab['bolsa'] }}">
                    <input type="hidden" name="asignacion_bolsa[{{ $cod }}][s{{ $i }}][monto]" value="{{ round($ab['monto']) }}" data-cod="{{ $cod }}" data-tipo="bolsa" data-bolsa="{{ $ab['bolsa'] }}">
                </div>
                @endforeach
            </div>

            @if($puedeEditar)
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
                <div style="flex:2;min-width:200px">
                    <label style="font-size:10px;color:#6B7280;display:block">Bolsa (UN)</label>
                    <select id="asignbolsa-cta-{{ $cod }}" style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px">
                        <option value="">— Elegir bolsa —</option>
                        @foreach($bolsas as $b)
                            <option value="{{ $b['codigo'] }}">{{ $b['codigo'] }} · {{ Str::limit($b['nombre'], 26) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1;min-width:110px">
                    <label style="font-size:10px;color:#6B7280;display:block">Monto</label>
                    <input type="number" id="asignbolsa-monto-{{ $cod }}" min="0" step="1" placeholder="0" style="width:100%;padding:6px;border:1px solid #FDE68A;border-radius:6px;font-size:11px">
                </div>
                <button type="button" onclick="asignarBolsa('{{ $cod }}')" style="padding:7px 14px;background:#D97706;color:white;border:none;border-radius:6px;font-size:11px;cursor:pointer">Asignar</button>
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
