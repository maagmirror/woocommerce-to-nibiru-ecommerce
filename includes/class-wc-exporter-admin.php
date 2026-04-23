<?php
/**
 * Clase para la interfaz de administración
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }
    
    /**
     * Agrega el menú en el admin
     */
    public function add_admin_menu() {
        add_menu_page(
            'WooCommerce Exporter',
            'woo to nibiru',
            'manage_options',
            'wc_exporter',
            array($this, 'admin_page'),
            'dashicons-download'
        );
    }
    
    /**
     * Página de administración
     */
    public function admin_page() {
        $total_products = $this->get_total_products();
        $export_nonce = wp_create_nonce('wc_exporter_export');
        $ajax_url = admin_url('admin-ajax.php');
        $rest_url = esc_url_raw(rest_url('wc-exporter/v1/export-batch'));
        $rest_nonce = wp_create_nonce('wp_rest');
        ?>
        <style>
            .wc-exp-wrap { max-width: 920px; }
            .wc-exp-status {
                margin-top: 20px;
                padding: 16px;
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                max-height: 560px;
                overflow-y: auto;
            }
            .wc-exp-batch {
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid #dcdcde;
            }
            .wc-exp-batch:last-child { border-bottom: none; }
            .wc-exp-log-lines {
                font-size: 13px;
                line-height: 1.5;
                color: #1d2327;
                margin: 0 0 12px 0;
            }
            .wc-exp-cards {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 12px;
            }
            .wc-exp-card {
                display: flex;
                gap: 12px;
                align-items: flex-start;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 10px 12px;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
            }
            .wc-exp-card--success { border-left: 4px solid #00a32a; }
            .wc-exp-card--error { border-left: 4px solid #d63638; }
            .wc-exp-card--info { border-left: 4px solid #72aee6; }
            .wc-exp-thumb {
                width: 56px;
                height: 56px;
                object-fit: cover;
                border-radius: 6px;
                background: #f0f0f1;
                flex-shrink: 0;
            }
            .wc-exp-thumb--empty {
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                color: #787c82;
                text-align: center;
                line-height: 1.2;
                padding: 4px;
            }
            .wc-exp-card-body { min-width: 0; flex: 1; }
            .wc-exp-card-title {
                font-weight: 600;
                font-size: 13px;
                margin: 0 0 4px 0;
                line-height: 1.3;
                word-break: break-word;
            }
            .wc-exp-card-meta {
                font-size: 12px;
                color: #50575e;
                margin: 0 0 6px 0;
            }
            .wc-exp-card-detail {
                font-size: 12px;
                margin: 0;
                color: #1d2327;
            }
            .wc-exp-card-detail--err { color: #b32d2e; font-weight: 500; }
            .wc-exp-card details { margin-top: 8px; font-size: 12px; }
            .wc-exp-card pre {
                margin: 8px 0 0 0;
                padding: 10px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                max-height: 220px;
                overflow: auto;
                white-space: pre-wrap;
                word-break: break-word;
                font-size: 11px;
            }
            .wc-exp-resume-hint {
                font-size: 13px;
                color: #50575e;
                margin: 0 0 8px 0;
                padding: 8px 10px;
                background: #f0f6fc;
                border-left: 4px solid #72aee6;
                border-radius: 4px;
            }
            .wc-exp-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                margin-top: 8px;
            }
            .wc-exp-toolbar button.secondary {
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                color: #2c3338;
                padding: 6px 12px;
                border-radius: 3px;
                cursor: pointer;
            }
            .wc-exp-toolbar button.secondary:hover {
                background: #f0f0f1;
            }
        </style>
        <div class="wrap wc-exp-wrap">
            <h1 style="text-align:center;">WooCommerce Exporter</h1>
            <p>
                <strong>Total de productos en WooCommerce:</strong>
                <span id="total_products"><?php echo esc_html($total_products); ?></span>
            </p>
            <p>
                <strong>Productos exportados:</strong>
                <span id="exported_products">0</span>
            </p>
            <form id="wc_exporter_form"
                style="display:flex; flex-direction:column; gap:10px; background:#fff; padding:20px; border-radius:5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                <label for="api_key">API Key:</label>
                <input type="text" id="api_key" name="api_key" placeholder="Ejemplo: 1234567890abcdef" required>
                
                <label for="api_url">API URL:</label>
                <input type="text" id="api_url" name="api_url" placeholder="Ejemplo: https://site.com" required>
                
                <label for="show_log">Mostrar datos enviados:</label>
                <input type="checkbox" id="show_log" name="show_log">
                
                <label for="force_stock">Forzar stock mínimo (si stock=0, asignar 1):</label>
                <input type="checkbox" id="force_stock" name="force_stock" checked>
                
                <label for="force_categories">Forzar reexportar categorías:</label>
                <input type="checkbox" id="force_categories" name="force_categories" checked>
                
                <label for="reset_progress" style="margin-top:6px;">Empezar desde el primer producto (ignora progreso guardado en este navegador):</label>
                <input type="checkbox" id="reset_progress" name="reset_progress">
                
                <p id="wc_resume_hint" class="wc-exp-resume-hint" style="display:none;"></p>
                
                <button type="button" id="start_export"
                    style="background:#0073aa; color:white; padding:10px; border:none; cursor:pointer; border-radius:3px;">
                    Exportar Productos
                </button>
                <div class="wc-exp-toolbar">
                    <button type="button" class="secondary" id="wc_clear_saved">Borrar datos guardados en el navegador</button>
                    <span class="description" style="margin:0;">Se guardan API URL, clave y el último offset para poder reanudar si recargas la página (solo en este equipo/navegador).</span>
                </div>
            </form>
            <div id="export_status" class="wc-exp-status"></div>
        </div>
        <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
                var exportNonce = <?php echo wp_json_encode($export_nonce); ?>;
                var restUrl = <?php echo wp_json_encode($rest_url); ?>;
                var restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
                var STOR_KEY = 'wc_exporter_v1';

                function readStore() {
                    try {
                        var raw = localStorage.getItem(STOR_KEY);
                        return raw ? JSON.parse(raw) : {};
                    } catch (e) {
                        return {};
                    }
                }

                function writeStore(obj) {
                    try {
                        localStorage.setItem(STOR_KEY, JSON.stringify(obj));
                    } catch (e) {}
                }

                function mergeStore(partial) {
                    var s = readStore();
                    var k;
                    for (k in partial) {
                        if (Object.prototype.hasOwnProperty.call(partial, k)) {
                            s[k] = partial[k];
                        }
                    }
                    writeStore(s);
                    return s;
                }

                function saveFormToStore() {
                    mergeStore({
                        api_key: document.getElementById('api_key').value,
                        api_url: document.getElementById('api_url').value,
                        show_log: document.getElementById('show_log').checked,
                        force_stock: document.getElementById('force_stock').checked,
                        force_categories: document.getElementById('force_categories').checked
                    });
                }

                var saveTimer;
                function scheduleSaveForm() {
                    clearTimeout(saveTimer);
                    saveTimer = setTimeout(saveFormToStore, 400);
                }

                function updateResumeHint() {
                    var el = document.getElementById('wc_resume_hint');
                    var s = readStore();
                    var off = s.resume_offset;
                    if (off != null && off !== '' && !isNaN(Number(off)) && Number(off) > 0 && !document.getElementById('reset_progress').checked) {
                        el.style.display = 'block';
                        el.textContent = 'Progreso guardado: continuará desde el offset ' + String(off) + '. Marca «Empezar desde el primer producto» si quieres reiniciar.';
                    } else if (off != null && Number(off) > 0) {
                        el.style.display = 'block';
                        el.textContent = 'Hay un offset guardado (' + String(off) + '), pero reiniciar está marcado: se empezará desde 0.';
                    } else {
                        el.style.display = 'none';
                    }
                }

                function restoreFormFromStore() {
                    var s = readStore();
                    if (s.api_key) {
                        document.getElementById('api_key').value = s.api_key;
                    }
                    if (s.api_url) {
                        document.getElementById('api_url').value = s.api_url;
                    }
                    if (typeof s.show_log === 'boolean') {
                        document.getElementById('show_log').checked = s.show_log;
                    }
                    if (typeof s.force_stock === 'boolean') {
                        document.getElementById('force_stock').checked = s.force_stock;
                    }
                    if (typeof s.force_categories === 'boolean') {
                        document.getElementById('force_categories').checked = s.force_categories;
                    }
                    if (typeof s.last_session_exported === 'number' && s.last_session_exported >= 0) {
                        document.getElementById('exported_products').textContent = String(s.last_session_exported);
                    }
                    updateResumeHint();
                }

                ['api_key', 'api_url', 'show_log', 'force_stock', 'force_categories'].forEach(function (id) {
                    var el = document.getElementById(id);
                    el.addEventListener('change', function () {
                        scheduleSaveForm();
                        updateResumeHint();
                    });
                    if (el.type === 'text') {
                        el.addEventListener('input', scheduleSaveForm);
                    }
                });
                document.getElementById('reset_progress').addEventListener('change', updateResumeHint);

                document.getElementById('wc_clear_saved').addEventListener('click', function () {
                    if (window.confirm('¿Borrar API URL, clave, opciones y progreso guardados en este navegador?')) {
                        try {
                            localStorage.removeItem(STOR_KEY);
                        } catch (e) {}
                        document.getElementById('reset_progress').checked = false;
                        updateResumeHint();
                    }
                });

                restoreFormFromStore();

                function buildPayload(offset, apiKey, apiUrl, showLog, forceStock, forceCategories) {
                    return {
                        offset: offset,
                        api_key: apiKey,
                        api_url: apiUrl,
                        show_log: showLog,
                        force_stock: forceStock,
                        force_categories: forceCategories
                    };
                }

                function fetchViaRest(payload) {
                    var ctrl = new AbortController();
                    var tid = setTimeout(function () {
                        ctrl.abort();
                    }, 25000);
                    return fetch(restUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: ctrl.signal,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': restNonce
                        },
                        body: JSON.stringify(payload)
                    }).finally(function () {
                        clearTimeout(tid);
                    });
                }

                function fetchViaAjax(payload) {
                    return fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'wc_export_products',
                            nonce: exportNonce,
                            api_key: payload.api_key,
                            api_url: payload.api_url,
                            show_log: payload.show_log ? '1' : '0',
                            force_stock: payload.force_stock ? '1' : '0',
                            force_categories: payload.force_categories ? '1' : '0',
                            offset: String(payload.offset)
                        })
                    });
                }

                /**
                 * Intenta REST primero; si falla, cuerpo rest_no_route, timeout o red → admin-ajax.
                 * rest_no_route en el JSON = plugin desactualizado en el servidor o ruta no registrada.
                 */
                function fetchExportBatch(payload) {
                    return fetchViaRest(payload)
                        .then(function (response) {
                            return response.clone().text().then(function (text) {
                                var useAjax = !response.ok;
                                if (!useAjax && text) {
                                    try {
                                        var j = JSON.parse(text);
                                        if (j && (j.code === 'rest_no_route' || j.code === 'rest_not_logged_in')) {
                                            useAjax = true;
                                        }
                                    } catch (e) {}
                                }
                                if (useAjax) {
                                    return fetchViaAjax(payload);
                                }
                                return response;
                            });
                        })
                        .catch(function () {
                            return fetchViaAjax(payload);
                        });
                }

                function renderProductCard(p) {
                    var mod = p.status === 'success' ? 'success' : (p.status === 'error' ? 'error' : 'info');
                    var wrap = document.createElement('div');
                    wrap.className = 'wc-exp-card wc-exp-card--' + mod;

                    if (p.thumb_url) {
                        var img = document.createElement('img');
                        img.className = 'wc-exp-thumb';
                        img.src = p.thumb_url;
                        img.alt = '';
                        img.loading = 'lazy';
                        wrap.appendChild(img);
                    } else {
                        var ph = document.createElement('div');
                        ph.className = 'wc-exp-thumb wc-exp-thumb--empty';
                        ph.textContent = 'Sin imagen';
                        wrap.appendChild(ph);
                    }

                    var body = document.createElement('div');
                    body.className = 'wc-exp-card-body';

                    var h = document.createElement('p');
                    h.className = 'wc-exp-card-title';
                    h.textContent = p.title || '';
                    body.appendChild(h);

                    var sku = document.createElement('p');
                    sku.className = 'wc-exp-card-meta';
                    sku.textContent = 'SKU: ' + (p.sku || '—');
                    body.appendChild(sku);

                    if (p.detail) {
                        var det = document.createElement('p');
                        det.className = 'wc-exp-card-detail' + (p.status === 'error' ? ' wc-exp-card-detail--err' : '');
                        det.textContent = p.detail;
                        body.appendChild(det);
                    }

                    if (p.request_json) {
                        var dr = document.createElement('details');
                        var sr = document.createElement('summary');
                        sr.textContent = 'JSON enviado a la API';
                        dr.appendChild(sr);
                        var pr = document.createElement('pre');
                        pr.textContent = p.request_json;
                        dr.appendChild(pr);
                        body.appendChild(dr);
                    }
                    if (p.response_json) {
                        var dr2 = document.createElement('details');
                        var sr2 = document.createElement('summary');
                        sr2.textContent = 'Respuesta de la API';
                        dr2.appendChild(sr2);
                        var pr2 = document.createElement('pre');
                        pr2.textContent = p.response_json;
                        dr2.appendChild(pr2);
                        body.appendChild(dr2);
                    }

                    wrap.appendChild(body);
                    return wrap;
                }

                function appendBatch(statusBox, data) {
                    var batch = document.createElement('div');
                    batch.className = 'wc-exp-batch';

                    if (data.message) {
                        var logP = document.createElement('div');
                        logP.className = 'wc-exp-log-lines';
                        logP.innerHTML = data.message;
                        batch.appendChild(logP);
                    }

                    var previews = data.product_previews || [];
                    if (previews.length) {
                        var grid = document.createElement('div');
                        grid.className = 'wc-exp-cards';
                        for (var i = 0; i < previews.length; i++) {
                            grid.appendChild(renderProductCard(previews[i]));
                        }
                        batch.appendChild(grid);
                    }

                    statusBox.appendChild(batch);
                    statusBox.scrollTop = statusBox.scrollHeight;
                }

                document.getElementById('start_export').addEventListener('click', function () {
                    var apiKey = document.getElementById('api_key').value;
                    var apiUrl = document.getElementById('api_url').value;
                    var showLog = document.getElementById('show_log').checked;
                    var forceStock = document.getElementById('force_stock').checked;
                    var forceCategories = document.getElementById('force_categories').checked;
                    var resetProgress = document.getElementById('reset_progress').checked;
                    var statusBox = document.getElementById('export_status');

                    var startOffset = 0;
                    if (resetProgress) {
                        mergeStore({ resume_offset: null });
                        startOffset = 0;
                    } else {
                        var st = readStore();
                        if (st.resume_offset != null && st.resume_offset !== '' && !isNaN(Number(st.resume_offset))) {
                            startOffset = parseInt(st.resume_offset, 10);
                        }
                    }

                    saveFormToStore();

                    var exportedCount = 0;
                    if (!resetProgress && startOffset > 0) {
                        var prevEx = readStore().last_session_exported;
                        if (typeof prevEx === 'number' && prevEx >= 0) {
                            exportedCount = prevEx;
                        }
                    }
                    document.getElementById('exported_products').textContent = String(exportedCount);

                    function exportBatch(offset) {
                        var payload = buildPayload(offset, apiKey, apiUrl, showLog, forceStock, forceCategories);
                        fetchExportBatch(payload)
                            .then(function (response) {
                                if (!response.ok) {
                                    var hint = '';
                                    if (response.status === 404) {
                                        hint = ' Ni REST ni admin-ajax devolvieron una respuesta OK. Revisa permalinks, .htaccess y la pestaña Red: filtra por «export» o mira el cuerpo de admin-ajax (debe ser JSON del plugin).';
                                    }
                                    if (response.status === 403) {
                                        hint = ' Recarga la página por si el nonce de seguridad caducó.';
                                    }
                                    return response.text().then(function (t) {
                                        var msg = 'Error HTTP ' + response.status + ' ' + response.statusText + '.' + hint;
                                        try {
                                            var j = JSON.parse(t);
                                            if (j && j.message) {
                                                msg = j.message + ' (' + response.status + ')';
                                            }
                                        } catch (e2) {}
                                        throw new Error(msg);
                                    });
                                }
                                return response.text();
                            })
                            .then(function (text) {
                                var data;
                                try {
                                    data = JSON.parse(text);
                                } catch (e) {
                                    throw new Error('Respuesta no válida (¿HTML en lugar de JSON?). Primeros caracteres: ' + text.substring(0, 180));
                                }

                                if (typeof data !== 'object' || data === null || Array.isArray(data)) {
                                    throw new Error('El servidor devolvió JSON inesperado (no es un objeto). Si ves admin-ajax con 200, revisa avisos PHP o salida antes del JSON.');
                                }

                                if (data.success === false) {
                                    var err = document.createElement('p');
                                    err.style.cssText = 'color:#b32d2e;font-weight:600;margin:0;';
                                    err.textContent = data.message || 'Error desconocido';
                                    statusBox.appendChild(err);
                                    return;
                                }

                                appendBatch(statusBox, data);
                                exportedCount += data.exported_count || 0;
                                document.getElementById('exported_products').textContent = String(exportedCount);
                                mergeStore({
                                    last_session_exported: exportedCount,
                                    resume_offset: data.next_offset != null ? data.next_offset : null
                                });
                                updateResumeHint();

                                if (data.next_offset !== null && data.next_offset !== undefined) {
                                    exportBatch(data.next_offset);
                                } else {
                                    mergeStore({ resume_offset: null });
                                    updateResumeHint();
                                    var done = document.createElement('p');
                                    done.style.cssText = 'color:#00a32a;font-weight:600;margin:12px 0 0 0;';
                                    done.textContent = 'Exportación completada. Total en esta sesión: ' + exportedCount + ' productos.';
                                    statusBox.appendChild(done);
                                    statusBox.scrollTop = statusBox.scrollHeight;
                                }
                            })
                            .catch(function (error) {
                                var err = document.createElement('p');
                                err.style.cssText = 'color:#b32d2e;font-weight:600;margin:0;';
                                err.textContent = 'Error: ' + error.message;
                                statusBox.appendChild(err);
                                console.error('Exportación:', error);
                            });
                    }

                    statusBox.innerHTML = '';
                    var start = document.createElement('p');
                    start.style.margin = '0 0 12px 0';
                    start.textContent = 'Iniciando exportación desde offset ' + String(startOffset) + ' (REST; si falla, admin-ajax automáticamente)…';
                    statusBox.appendChild(start);
                    exportBatch(startOffset);
                });
            })();
        </script>
        <?php
    }
    
    /**
     * Obtiene la cantidad total de productos
     */
    private function get_total_products() {
        $count = wp_count_posts('product');
        return $count->publish;
    }
}
