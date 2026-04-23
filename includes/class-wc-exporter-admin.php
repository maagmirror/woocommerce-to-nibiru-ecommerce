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
                
                <button type="button" id="start_export"
                    style="background:#0073aa; color:white; padding:10px; border:none; cursor:pointer; border-radius:3px;">
                    Exportar Productos
                </button>
            </form>
            <div id="export_status" class="wc-exp-status"></div>
        </div>
        <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
                var exportNonce = <?php echo wp_json_encode($export_nonce); ?>;

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
                    var statusBox = document.getElementById('export_status');
                    var exportedCount = 0;

                    function exportBatch(offset) {
                        fetch(ajaxUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({
                                action: 'wc_export_products',
                                nonce: exportNonce,
                                api_key: apiKey,
                                api_url: apiUrl,
                                show_log: showLog ? '1' : '0',
                                force_stock: forceStock ? '1' : '0',
                                force_categories: forceCategories ? '1' : '0',
                                offset: String(offset)
                            })
                        })
                            .then(function (response) {
                                if (!response.ok) {
                                    var hint = '';
                                    if (response.status === 404) {
                                        hint = ' Comprueba que exista wp-admin/admin-ajax.php y que ningún plugin o regla del servidor bloquee AJAX.';
                                    }
                                    throw new Error('Error HTTP ' + response.status + ' ' + response.statusText + '.' + hint);
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

                                if (data.success === false) {
                                    var err = document.createElement('p');
                                    err.style.cssText = 'color:#b32d2e;font-weight:600;margin:0;';
                                    err.textContent = data.message || 'Error desconocido';
                                    statusBox.appendChild(err);
                                    return;
                                }

                                appendBatch(statusBox, data);
                                exportedCount += data.exported_count || 0;
                                document.getElementById('exported_products').textContent = exportedCount;

                                if (data.next_offset !== null && data.next_offset !== undefined) {
                                    exportBatch(data.next_offset);
                                } else {
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
                    start.textContent = 'Iniciando exportación…';
                    statusBox.appendChild(start);
                    exportBatch(0);
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
