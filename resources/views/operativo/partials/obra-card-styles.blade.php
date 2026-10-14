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
