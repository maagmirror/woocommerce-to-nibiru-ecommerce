<?php
/**
 * Plugin Name: WooCommerce Exporter to Nibiru Ecommerce
 * Description: Exporta productos y categorías de WooCommerce a Nibiru eCommerce en batches. Si la categoría del producto no existe en el ecommerce remoto, se crea. Puedes forzar la reexportación de categorías para ignorar el mapping previo.
 * Version: 2.0
 * Author: Nibiru Team
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('WC_EXPORTER_VERSION', '2.0');
define('WC_EXPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_EXPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Cargar clases
require_once WC_EXPORTER_PLUGIN_DIR . 'includes/class-wc-exporter-currency.php';
require_once WC_EXPORTER_PLUGIN_DIR . 'includes/class-wc-exporter-api.php';
require_once WC_EXPORTER_PLUGIN_DIR . 'includes/class-wc-exporter.php';
require_once WC_EXPORTER_PLUGIN_DIR . 'includes/class-wc-exporter-admin.php';

// Inicializar el plugin
function wc_exporter_init() {
    // Verificar que WooCommerce esté activo
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wc_exporter_woocommerce_missing_notice');
        return;
    }
    
    $exporter = new WC_Exporter();
    $admin = new WC_Exporter_Admin();
}
add_action('plugins_loaded', 'wc_exporter_init');

// Mostrar aviso si WooCommerce no está activo
function wc_exporter_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('WooCommerce Exporter to Nibiru Ecommerce requiere que WooCommerce esté instalado y activo.', 'wc-exporter'); ?></p>
    </div>
    <?php
}