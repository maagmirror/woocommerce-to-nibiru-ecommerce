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
        ?>
        <div class="wrap" style="max-width:600px;">
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
            <div id="export_status" style="margin-top:20px; padding:10px; background:#f7f7f7; border:1px solid #ddd; max-height:400px; overflow-y:auto;"></div>
        </div>
        <script>
            document.getElementById('start_export').addEventListener('click', function () {
                let apiKey = document.getElementById('api_key').value;
                let apiUrl = document.getElementById('api_url').value;
                let showLog = document.getElementById('show_log').checked;
                let forceStock = document.getElementById('force_stock').checked;
                let forceCategories = document.getElementById('force_categories').checked;
                let statusBox = document.getElementById('export_status');
                let exportedCount = 0;
                
                function exportBatch(offset) {
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'wc_export_products',
                            api_key: apiKey,
                            api_url: apiUrl,
                            show_log: showLog ? '1' : '0',
                            force_stock: forceStock ? '1' : '0',
                            force_categories: forceCategories ? '1' : '0',
                            offset: offset
                        })
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Error HTTP: ' + response.status + ' ' + response.statusText);
                            }
                            return response.text();
                        })
                        .then(text => {
                            let data;
                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                throw new Error('Error al parsear respuesta: ' + text.substring(0, 200));
                            }
                            
                            if (data.success === false) {
                                statusBox.innerHTML += `<p style='color:red; font-weight:bold;'>${data.message}</p>`;
                                return;
                            }
                            
                            statusBox.innerHTML += `<p>${data.message || 'Sin mensaje'}</p>`;
                            statusBox.scrollTop = statusBox.scrollHeight;
                            exportedCount += data.exported_count || 0;
                            document.getElementById('exported_products').textContent = exportedCount;
                            if (data.next_offset !== null && data.next_offset !== undefined) {
                                exportBatch(data.next_offset);
                            } else {
                                statusBox.innerHTML += `<p style='color:green; font-weight:bold;'>¡Exportación completada! Total: ${exportedCount} productos.</p>`;
                            }
                        })
                        .catch(error => {
                            statusBox.innerHTML += `<p style='color:red; font-weight:bold;'>Error: ${error.message}</p>`;
                            console.error('Error en exportación:', error);
                        });
                }
                statusBox.innerHTML = "<p>Iniciando exportación...</p>";
                exportBatch(0);
            });
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
