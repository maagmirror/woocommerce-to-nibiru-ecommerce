<?php
/**
 * Plugin Name: WooCommerce to Nibiru Exporter
 * Description: Exporta productos de WooCommerce a Nibiru eCommerce en batches.
 * Version: 1.0
 * Author: Nibiru team
 */

// Bloquear acceso directo
if (!defined('ABSPATH')) {
  exit;
}

// Agregar menú en el admin de WordPress
function wc_exporter_add_admin_menu() {
  add_menu_page(
    'WooCommerce Exporter',
    'WC Exporter',
    'manage_options',
    'wc_exporter',
    'wc_exporter_admin_page',
  );
}
add_action('admin_menu', 'wc_exporter_add_admin_menu');

// Página de configuración del plugin
function wc_exporter_admin_page() {
  ?>
  <div class="wrap">
    <h1>WooCommerce Exporter</h1>
    <form id="wc_exporter_form">
      <label for="api_key">API Key:</label>
      <input type="text" id="api_key" name="api_key" required>
      <br>
      <label for="api_url">API URL:</label>
      <input type="text" id="api_url" name="api_url" required>
      <br>
      <button type="button" id="start_export">Exportar Productos</button>
    </form>
    <div id="export_status"></div>
  </div>
  <script>
    document.getElementById('start_export').addEventListener('click', function () {
      let apiKey = document.getElementById('api_key').value;
      let apiUrl = document.getElementById('api_url').value;
      if (!apiKey || !apiUrl) {
        alert('Debes completar la API Key y la URL.');
        return;
      }

      fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'wc_export_products',
          api_key: apiKey,
          api_url: apiUrl
        })
      })
        .then(response => response.json())
        .then(data => {
          document.getElementById('export_status').innerHTML = data.message;
        })
        .catch(error => console.error('Error:', error));
    });
  </script>
  <?php
}

// Exportar productos en batches
function wc_export_products() {
  $api_key = $_POST['api_key'];
  $api_url = rtrim($_POST['api_url'], '/') . '/api/upload-product';

  $args = [
    'limit' => 10, // Exportar en batches de 10
    'status' => 'publish'
  ];
  $products = wc_get_products($args);

  foreach ($products as $product) {
    $categories = wp_get_post_terms($product->get_id(), 'product_cat');
    $category_id = !empty($categories) ? $categories[0]->term_id : 0;

    $variants = [];
    if ($product->is_type('variable')) {
      $children = $product->get_children();
      foreach ($children as $variation_id) {
        $variation = wc_get_product($variation_id);
        $variants[] = [
          'label' => 'Variante',
          'type' => 'dropdown',
          'is_visible' => 1,
          'use_different_price' => 1,
          'options' => [[
            'name' => $variation->get_name(),
            'price' => $variation->get_price(),
            'stock' => $variation->get_stock_quantity(),
          ]]
        ];
      }
    }

    $data = [
      'apikey' => $api_key,
      'sku' => $product->get_sku(),
      'category_id' => $category_id,
      'price' => (float) $product->get_price(),
      'currency' => get_woocommerce_currency(),
      'stock' => $product->get_stock_quantity(),
      'product_type' => $product->is_virtual() ? 'digital' : 'physical',
      'title' => $product->get_name(),
      'description' => $product->get_description(),
      'short_description' => $product->get_short_description(),
      'seo_title' => get_post_meta($product->get_id(), '_yoast_wpseo_title', true) ?: $product->get_name(),
      'seo_description' => get_post_meta($product->get_id(), '_yoast_wpseo_metadesc', true) ?: '',
      'seo_keywords' => get_post_meta($product->get_id(), '_yoast_wpseo_focuskw', true) ?: '',
      'brand_id' => '', // Ajustar si se obtiene la marca de alguna forma
      'visibility' => 1,
      'status' => 1,
      'image_urls' => [wp_get_attachment_url($product->get_image_id())],
      'variants' => $variants
    ];

    $response = wp_remote_post($api_url, [
      'body' => json_encode($data),
      'headers' => ['Content-Type' => 'application/json']
    ]);
  }

  wp_send_json(['message' => 'Exportación completada.']);
}
add_action('wp_ajax_wc_export_products', 'wc_export_products');