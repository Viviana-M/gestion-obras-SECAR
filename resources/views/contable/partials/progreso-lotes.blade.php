{{--
  Barra de progreso para cargas por lotes (AJAX). Intercepta el submit del formulario
  $formId, sube el archivo a $prepararUrl y procesa en lotes contra $procesarUrl, pintando
  el avance. Si el JavaScript no corre, el formulario envía normal (respaldo síncrono).

  Parámetros: $formId, $prepararUrl, $procesarUrl, opcional $pid (prefijo de ids).
--}}
@php($pid = $pid ?? 'lotes')
<div id="{{ $pid }}-box" style="display:none;margin-top:14px">
    <div style="background:#E5E7EB;border-radius:999px;height:14px;overflow:hidden">
        <div id="{{ $pid }}-bar" style="height:100%;width:0%;background:#1B3F6E;transition:width .25s ease"></div>
    </div>
    <div id="{{ $pid }}-txt" style="margin-top:8px;font-size:13px;color:#374151"></div>
</div>
<script>
(function () {
    var form = document.getElementById(@json($formId));
    if (!form) return;
    var box = document.getElementById(@json($pid.'-box'));
    var bar = document.getElementById(@json($pid.'-bar'));
    var txt = document.getElementById(@json($pid.'-txt'));
    var PREP = @json($prepararUrl);
    var PROC = @json($procesarUrl);
    var TOKEN = @json(csrf_token());
    var H = { 'X-CSRF-TOKEN': TOKEN, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function miles(n) { try { return Number(n).toLocaleString('es-CO'); } catch (e) { return n; } }

    function terminar(msg, warning, redirigir) {
        bar.style.width = '100%';
        bar.style.background = '#15803D';
        txt.innerHTML = '<b style="color:#15803D">✓ ' + esc(msg) + '</b>' +
            (warning ? '<div style="color:#B45309;margin-top:6px">' + esc(warning) + '</div>' : '');
        setTimeout(function () { window.location = redirigir || window.location.href; }, warning ? 4000 : 1400);
    }
    function fallar(msg, btn) {
        bar.style.width = '100%';
        bar.style.background = '#DC2626';
        txt.innerHTML = '<span style="color:#DC2626">' + esc(msg) + '</span>';
        if (btn) btn.disabled = false;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[type=submit]');
        if (btn) btn.disabled = true;
        box.style.display = 'block';
        bar.style.background = '#1B3F6E';
        bar.style.width = '4%';
        txt.textContent = 'Subiendo y preparando el archivo…';

        var fd = new FormData(form);
        fetch(PREP, { method: 'POST', headers: H, body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error(res.d.error || 'No se pudo preparar el archivo.');
                var d = res.d;
                if (d.done || (d.total || 0) === 0) {
                    terminar(d.mensaje || 'Archivo procesado.', d.warning, d.redirigir);
                    return;
                }
                bar.style.width = '6%';
                txt.textContent = 'Procesando 0 de ' + miles(d.total) + '…';
                siguiente(d.carga_id, d.total, btn);
            })
            .catch(function (err) { fallar(err.message || String(err), btn); });
    });

    function siguiente(id, total, btn) {
        var fd = new FormData();
        fd.append('carga_id', id);
        fetch(PROC, { method: 'POST', headers: H, body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error(res.d.error || 'Error procesando el archivo.');
                var d = res.d;
                var t = d.total || total || 1;
                var pct = Math.max(6, Math.min(100, Math.round((d.procesadas || 0) * 100 / Math.max(1, t))));
                bar.style.width = pct + '%';
                txt.textContent = 'Procesando ' + miles(d.procesadas || 0) + ' de ' + miles(t) + '…';
                if (d.done) {
                    terminar(d.mensaje || 'Listo.', d.warning, d.redirigir);
                } else {
                    siguiente(id, t, btn);
                }
            })
            .catch(function (err) { fallar(err.message || String(err), btn); });
    }
})();
</script>
