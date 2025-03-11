<?php
/**
 * Plugin Name: WooCommerce Exporter
 * Description: Exporta productos y categorías de WooCommerce a otro eCommerce en batches.
 * Version: 1.2
 * Author: Tu Nombre
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

// Obtener cantidad total de productos en WooCommerce
function wc_get_total_products() {
  $count = wp_count_posts('product');
  return $count->publish;
}

// Página de configuración del plugin
function wc_exporter_admin_page() {
  $total_products = wc_get_total_products();
  ?>
  <div class="wrap" style="max-width: 600px;">
    <h1 style="text-align: center;">WooCommerce Exporter</h1>
    <p><strong>Total de productos en WooCommerce:</strong> <span id="total_products"><?php echo $total_products; ?></span>
    </p>
    <p><strong>Productos exportados:</strong> <span id="exported_products">0</span></p>

    <form id="wc_exporter_form"
      style="display: flex; flex-direction: column; gap: 10px; background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
      <label for="api_key">API Key:</label>
      <input type="text" id="api_key" name="api_key" placeholder="Ejemplo: 1234567890abcdef" required>

      <label for="api_url">API URL:</label>
      <input type="text" id="api_url" name="api_url" placeholder="Ejemplo: https://site.com" required>

      <label for="show_log">Mostrar datos enviados:</label>
      <input type="checkbox" id="show_log" name="show_log">

      <button type="button" id="start_export"
        style="background: #0073aa; color: white; padding: 10px; border: none; cursor: pointer;">Exportar
        Productos</button>
    </form>
    <div id="export_status" style="margin-top: 20px; padding: 10px; background: #f7f7f7; border: 1px solid #ddd;"></div>
  </div>
  <script>
    document.getElementById('start_export').addEventListener('click', function () {
      let apiKey = document.getElementById('api_key').value;
      let apiUrl = document.getElementById('api_url').value;
      let showLog = document.getElementById('show_log').checked;
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
            offset: offset
          })
        })
          .then(response => response.json())
          .then(data => {
            statusBox.innerHTML += `<p>${data.message}</p>`;
            exportedCount += data.exported_count;
            document.getElementById('exported_products').textContent = exportedCount;

            if (data.next_offset !== null) {
              exportBatch(data.next_offset);
            }
          })
          .catch(error => {
            statusBox.innerHTML += `<p style='color:red;'>Error: ${error.message}</p>`;
          });
      }

      statusBox.innerHTML = "<p>Iniciando exportación...</p>";
      exportBatch(0);
    });
  </script>
  <?php
}

// Agregar la acción AJAX para exportar productos
add_action('wp_ajax_wc_export_products', 'wc_export_products');

// Exportar productos en batches
function wc_export_products() {
  if (!isset($_POST['api_key']) || !isset($_POST['api_url'])) {
    wp_send_json(['message' => 'Error: Falta API Key o API URL.']);
    return;
  }

  // Obtener el offset enviado desde el front, o 0 por defecto
  $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
  $api_key = sanitize_text_field($_POST['api_key']);
  $api_url = rtrim(sanitize_text_field($_POST['api_url']), '/');

  // Consultar 10 productos a partir del offset
  $products = wc_get_products([
    'limit' => 10,
    'offset' => $offset,
    'status' => 'publish'
  ]);
  $log = [];

  foreach ($products as $product) {
    $images = [];
    if ($product->get_image_id()) {
      $images[] = wp_get_attachment_url($product->get_image_id());
    }
    $gallery_image_ids = $product->get_gallery_image_ids();
    if (!empty($gallery_image_ids)) {
      foreach ($gallery_image_ids as $image_id) {
        $images[] = wp_get_attachment_url($image_id);
      }
    }
    if (empty($images)) {
      $images = ["https://via.placeholder.com/500"];
    }

    $data = [
      'apikey' => $api_key,
      'sku' => $product->get_sku() ?: $product->get_id(),
      'title' => $product->get_name(),
      'price' => (float) $product->get_price(),
      'currency' => get_woocommerce_currency(),
      'stock' => (int) $product->get_stock_quantity(),
      'product_type' => 'physical',
      'visibility' => 1,
      'status' => 1,
      'image_urls' => $images
    ];

    $response = wp_remote_post("{$api_url}/api/upload-product", [
      'body' => json_encode($data),
      'headers' => ['Content-Type' => 'application/json']
    ]);

    if (is_wp_error($response)) {
      $log[] = "Error en SKU {$data['sku']}: " . $response->get_error_message();
    } else {
      $body = json_decode(wp_remote_retrieve_body($response), true);
      if (isset($body['error'])) {
        $log[] = "Error en SKU {$data['sku']}: " . $body['error'];
      } else {
        $log[] = "SKU {$data['sku']} - Ecomm Response: " . json_encode($body);
      }
    }
  }

  // Calcular el siguiente offset: si se obtuvieron 10 productos, hay más productos para exportar
  $next_offset = count($products) == 10 ? $offset + 10 : null;

  wp_send_json([
    'message' => implode('<br>', $log),
    'exported_count' => count($products),
    'next_offset' => $next_offset
  ]);
}
