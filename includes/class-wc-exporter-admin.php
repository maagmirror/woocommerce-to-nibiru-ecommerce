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
        add_action('admin_post_wc_exporter_run_batch', array($this, 'handle_admin_post_batch'));
    }
    
    /**
     * Procesa un lote vía admin-post.php (evita POST a admin.php, bloqueado en algunos hosting/WAF).
     */
    public function handle_admin_post_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json(array('success' => false, 'message' => 'No tienes permisos para realizar esta acción.'));
            return;
        }
        
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wc_exporter_run_batch')) {
            wp_send_json(array('success' => false, 'message' => 'Sesión de seguridad caducada. Recarga esta página e inténtalo de nuevo.'));
            return;
        }
        
        if (!class_exists('WC_Exporter')) {
            wp_send_json(array('success' => false, 'message' => 'Error interno del plugin.'));
            return;
        }
        
        ob_start();
        $exporter = new WC_Exporter();
        $result = $exporter->run_export_batch(
            array(
                'offset'           => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
                'api_key'          => isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '',
                'api_url'          => isset($_POST['api_url']) ? sanitize_text_field(wp_unslash($_POST['api_url'])) : '',
                'force_stock'      => !empty($_POST['force_stock']) && $_POST['force_stock'] === '1',
                'force_categories' => !empty($_POST['force_categories']) && $_POST['force_categories'] === '1',
                'force_brands'     => !empty($_POST['force_brands']) && $_POST['force_brands'] === '1',
                'show_log'         => !empty($_POST['show_log']) && $_POST['show_log'] === '1',
                'brand_source'     => isset($_POST['brand_source']) ? sanitize_key(wp_unslash($_POST['brand_source'])) : '',
                'brand_attribute'  => isset($_POST['brand_attribute']) ? sanitize_key(wp_unslash($_POST['brand_attribute'])) : '',
            )
        );
        ob_end_clean();
        wp_send_json($result);
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
        $has_native_brands = taxonomy_exists('product_brand');
        $has_pwb_brands = taxonomy_exists('pwb-brand');
        $attribute_taxonomies = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : array();
        $export_nonce = wp_create_nonce('wc_exporter_export');
        $ajax_url = admin_url('admin-ajax.php');
        $admin_post_url = admin_url('admin-post.php');
        $admin_post_nonce = wp_create_nonce('wc_exporter_run_batch');
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

                <label for="brand_source" style="margin-top:6px;">Exportar marcas desde:</label>
                <select id="brand_source" name="brand_source">
                    <option value="">No exportar marcas</option>
                    <option value="product_tag">Etiquetas de producto (product_tag)</option>
                    <option value="product_brand"<?php echo $has_native_brands ? '' : ' disabled'; ?>>
                        Marcas nativas de WooCommerce<?php echo $has_native_brands ? '' : ' (no disponible)'; ?>
                    </option>
                    <option value="pwb-brand"<?php echo $has_pwb_brands ? '' : ' disabled'; ?>>
                        Perfect Brands for WooCommerce<?php echo $has_pwb_brands ? '' : ' (no disponible)'; ?>
                    </option>
                    <option value="attribute"<?php echo !empty($attribute_taxonomies) ? '' : ' disabled'; ?>>
                        Atributo de producto<?php echo !empty($attribute_taxonomies) ? '' : ' (no hay atributos)'; ?>
                    </option>
                </select>

                <div id="brand_attribute_wrap" style="display:none;">
                    <label for="brand_attribute">Atributo a usar como marca:</label>
                    <select id="brand_attribute" name="brand_attribute">
                        <?php foreach ($attribute_taxonomies as $att) :
                            $tax = 'pa_' . $att->attribute_name; ?>
                            <option value="<?php echo esc_attr($tax); ?>">
                                <?php echo esc_html($att->attribute_label . ' (' . $tax . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p class="description" style="margin:0;">
                    Las marcas nativas y Perfect Brands usan la imagen del término. Etiquetas y atributos no
                    tienen imagen: la marca se crea igual usando el placeholder del sitio
                    (<code>/assets/img/no-image.jpg</code>), reemplazable después en nibiru. Si la marca ya
                    existe en nibiru, solo se asigna por nombre.
                </p>

                <label for="force_brands">Forzar reexportar marcas:</label>
                <input type="checkbox" id="force_brands" name="force_brands">

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
                var adminPostUrl = <?php echo wp_json_encode($admin_post_url); ?>;
                var adminPostNonce = <?php echo wp_json_encode($admin_post_nonce); ?>;
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
                        force_categories: document.getElementById('force_categories').checked,
                        force_brands: document.getElementById('force_brands').checked,
                        brand_source: document.getElementById('brand_source').value,
                        brand_attribute: document.getElementById('brand_attribute') ? document.getElementById('brand_attribute').value : ''
                    });
                }

                function toggleBrandAttr() {
                    var wrap = document.getElementById('brand_attribute_wrap');
                    if (wrap) {
                        wrap.style.display = (document.getElementById('brand_source').value === 'attribute') ? 'block' : 'none';
                    }
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
                    if (typeof s.force_brands === 'boolean') {
                        document.getElementById('force_brands').checked = s.force_brands;
                    }
                    if (typeof s.brand_source === 'string') {
                        var bsEl = document.getElementById('brand_source');
                        if (bsEl.querySelector('option[value="' + s.brand_source + '"]:not([disabled])')) {
                            bsEl.value = s.brand_source;
                        }
                    }
                    if (typeof s.brand_attribute === 'string' && document.getElementById('brand_attribute')) {
                        var baEl = document.getElementById('brand_attribute');
                        if (baEl.querySelector('option[value="' + s.brand_attribute + '"]')) {
                            baEl.value = s.brand_attribute;
                        }
                    }
                    toggleBrandAttr();
                    if (typeof s.last_session_exported === 'number' && s.last_session_exported >= 0) {
                        document.getElementById('exported_products').textContent = String(s.last_session_exported);
                    }
                    updateResumeHint();
                }

                ['api_key', 'api_url', 'show_log', 'force_stock', 'force_categories', 'force_brands', 'brand_source', 'brand_attribute'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el) {
                        return;
                    }
                    el.addEventListener('change', function () {
                        scheduleSaveForm();
                        updateResumeHint();
                    });
                    if (el.type === 'text') {
                        el.addEventListener('input', scheduleSaveForm);
                    }
                });
                document.getElementById('brand_source').addEventListener('change', toggleBrandAttr);
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

                function buildPayload(offset, apiKey, apiUrl, showLog, forceStock, forceCategories, forceBrands, brandSource, brandAttribute) {
                    return {
                        offset: offset,
                        api_key: apiKey,
                        api_url: apiUrl,
                        show_log: showLog,
                        force_stock: forceStock,
                        force_categories: forceCategories,
                        force_brands: forceBrands,
                        brand_source: brandSource,
                        brand_attribute: brandAttribute
                    };
                }

                /**
                 * admin-post.php: patrón WordPress para acciones admin (muchas reglas WAF bloquean POST a admin.php).
                 */
                function fetchViaAdminPost(payload) {
                    return fetch(adminPostUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'wc_exporter_run_batch',
                            _wpnonce: adminPostNonce,
                            api_key: payload.api_key,
                            api_url: payload.api_url,
                            show_log: payload.show_log ? '1' : '0',
                            force_stock: payload.force_stock ? '1' : '0',
                            force_categories: payload.force_categories ? '1' : '0',
                            force_brands: payload.force_brands ? '1' : '0',
                            brand_source: payload.brand_source || '',
                            brand_attribute: payload.brand_attribute || '',
                            offset: String(payload.offset)
                        })
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
                            force_brands: payload.force_brands ? '1' : '0',
                            brand_source: payload.brand_source || '',
                            brand_attribute: payload.brand_attribute || '',
                            offset: String(payload.offset)
                        })
                    });
                }

                /**
                 * 1) admin-post.php (wc_exporter_run_batch)
                 * 2) Si falla, admin-ajax.
                 */
                function fetchExportBatch(payload) {
                    return fetchViaAdminPost(payload)
                        .then(function (response) {
                            if (response.ok) {
                                return response;
                            }
                            return fetchViaAjax(payload);
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

                    if (p.brand) {
                        var brand = document.createElement('p');
                        brand.className = 'wc-exp-card-meta';
                        brand.textContent = 'Marca: ' + p.brand;
                        body.appendChild(brand);
                    }

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
                    var forceBrands = document.getElementById('force_brands').checked;
                    var brandSource = document.getElementById('brand_source').value;
                    var brandAttrEl = document.getElementById('brand_attribute');
                    var brandAttribute = (brandSource === 'attribute' && brandAttrEl) ? brandAttrEl.value : '';
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
                        var payload = buildPayload(offset, apiKey, apiUrl, showLog, forceStock, forceCategories, forceBrands, brandSource, brandAttribute);
                        fetchExportBatch(payload)
                            .then(function (response) {
                                if (!response.ok) {
                                    var hint = '';
                                    if (response.status === 404) {
                                        hint = ' Falló admin-post.php y el respaldo admin-ajax. Si el hosting bloquea wp-admin, pide revisar reglas WAF o lista blanca.';
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

                                if (data.stopped) {
                                    appendBatch(statusBox, data);
                                    exportedCount += data.exported_count || 0;
                                    document.getElementById('exported_products').textContent = String(exportedCount);
                                    // Se reanuda desde este mismo lote tras corregir la marca.
                                    mergeStore({ last_session_exported: exportedCount, resume_offset: offset });
                                    updateResumeHint();
                                    var stop = document.createElement('p');
                                    stop.style.cssText = 'color:#b32d2e;font-weight:600;margin:12px 0 0 0;';
                                    stop.textContent = 'Exportación detenida por un error de marca. Revisá el log, corregí y volvé a iniciar (se reanuda desde este lote).';
                                    statusBox.appendChild(stop);
                                    statusBox.scrollTop = statusBox.scrollHeight;
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
                    start.textContent = 'Iniciando exportación desde offset ' + String(startOffset) + ' (admin-post.php; si falla, admin-ajax)…';
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
