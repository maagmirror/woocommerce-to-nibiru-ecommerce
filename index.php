<?php
/**
 * Plugin Name: WooCommerce Exporter to Nibiru Ecommerce
 * Description: Exporta productos y categorías de WooCommerce a Nibiru eCommerce en batches. Si la categoría del producto no existe en el ecommerce remoto, se crea. Puedes forzar la reexportación de categorías para ignorar el mapping previo.
 * Version: 1.3
 * Author: Nibiru Team
 */

if (!defined('ABSPATH')) {
  exit;
}

// Agregar menú en el admin de WordPress
function wc_exporter_add_admin_menu() {
  add_menu_page(
    'WooCommerce Exporter',
    'woo to nibiru',
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
  <div class="wrap" style="max-width:600px;">
    <h1 style="text-align:center;">WooCommerce Exporter</h1>
    <p>
      <strong>Total de productos en WooCommerce:</strong>
      <span id="total_products"><?php echo $total_products; ?></span>
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
        style="background:#0073aa; color:white; padding:10px; border:none; cursor:pointer;">
        Exportar Productos
      </button>
    </form>
    <div id="export_status" style="margin-top:20px; padding:10px; background:#f7f7f7; border:1px solid #ddd;"></div>
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

add_action('wp_ajax_wc_export_products', 'wc_export_products');

function wc_export_products() {
  if (!isset($_POST['api_key']) || !isset($_POST['api_url'])) {
    wp_send_json(['message' => 'Error: Falta API Key o API URL.']);
    return;
  }

  $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
  $api_key = sanitize_text_field($_POST['api_key']);
  $api_url = rtrim(sanitize_text_field($_POST['api_url']), '/');
  $force_stock = (isset($_POST['force_stock']) && $_POST['force_stock'] === '1');
  $force_categories = (isset($_POST['force_categories']) && $_POST['force_categories'] === '1');

  // Si se fuerza reexportar categorías, limpiar el mapping.
  if ($force_categories) {
    $category_mapping = array();
    update_option('wc_exporter_category_mapping', $category_mapping);
  } else {
    $category_mapping = get_option('wc_exporter_category_mapping', array());
  }

  $products = wc_get_products([
    'limit' => 10,
    'offset' => $offset,
    'status' => 'publish'
  ]);
  $log = [];

  foreach ($products as $product) {
    // Procesar imágenes
    $images = [];
    if ($product->get_image_id()) {
      $img_url = wp_get_attachment_url($product->get_image_id());
      $images[] = $img_url;
      $log[] = "Imagen principal: {$img_url}";
    }
    $gallery_image_ids = $product->get_gallery_image_ids();
    if (!empty($gallery_image_ids)) {
      foreach ($gallery_image_ids as $image_id) {
        $img_url = wp_get_attachment_url($image_id);
        $images[] = $img_url;
        $log[] = "Imagen de galería: {$img_url}";
      }
    }
    if (empty($images)) {
      $images = ["https://via.placeholder.com/500"];
      $log[] = "No se encontraron imágenes, se usa placeholder.";
    }

    // Datos básicos del producto
    $product_stock = (int) $product->get_stock_quantity();
    if ($force_stock && $product_stock <= 0) {
      $product_stock = 1;
      $log[] = "Forzado: Stock ajustado a 1 para producto ID {$product->get_id()}";
    }
    $data = [
      'apikey' => $api_key,
      'sku' => $product->get_sku() ?: $product->get_id(),
      'title' => $product->get_name(),
      'price' => (float) $product->get_price(),
      'currency' => get_woocommerce_currency(),
      'stock' => $product_stock,
      'product_type' => 'physical',
      'visibility' => 1,
      'status' => 1,
      'image_urls' => $images,
      'description' => wp_strip_all_tags($product->get_description()),
      'short_description' => wp_strip_all_tags($product->get_short_description()),
      'seo_title' => $product->get_name(),
      'seo_description' => wp_strip_all_tags($product->get_short_description()),
      'seo_keywords' => implode(', ', wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names'])),
    ];

    // Procesamiento de categorías
    $cat_ids = $product->get_category_ids();
    if (!empty($cat_ids)) {
      $wc_cat_id = $cat_ids[0];
      $remote_cat_id = 0;
      if (isset($category_mapping[$wc_cat_id])) {
        $remote_cat_id = $category_mapping[$wc_cat_id];
        $log[] = "Mapping existente: WooCatID {$wc_cat_id} => RemoteCatID {$remote_cat_id}";
      } else {
        $term = get_term($wc_cat_id, 'product_cat');
        if (!$term) {
          $log[] = "No se encontró el término para WooCatID {$wc_cat_id}";
          $remote_cat_id = 0;
        } else {
          $log[] = "Procesando categoría: WooCatID {$wc_cat_id} - Name: {$term->name} - Slug: {$term->slug}";
          // Consultar categorías remotas mediante GET
          $remote_response = wp_remote_get("{$api_url}/api/categorias?apikey={$api_key}", ['timeout' => 15]);
          if (!is_wp_error($remote_response)) {
            $remote_body = json_decode(wp_remote_retrieve_body($remote_response), true);
            $log[] = "Respuesta GET categorías remotas: " . json_encode($remote_body);
            if (isset($remote_body['success']) && $remote_body['success'] && !empty($remote_body['categories'])) {
              foreach ($remote_body['categories'] as $remote_cat) {
                if (isset($remote_cat['slug']) && $remote_cat['slug'] == $term->slug) {
                  $remote_cat_id = $remote_cat['id'];
                  $log[] = "Categoría encontrada en remoto por slug: {$term->slug} => RemoteCatID {$remote_cat_id}";
                  break;
                }
              }
            } else {
              $log[] = "Respuesta GET categorías remotas inválida o vacía.";
            }
          } else {
            $log[] = "Error en GET categorías remotas: " . $remote_response->get_error_message();
          }
          // Si no se encontró, intentar crear la categoría
          if (!$remote_cat_id) {
            $cat_data = [
              'apikey' => $api_key,
              'parent_id' => $term->parent ? (int) $term->parent : 0,
              'name' => $term->name,
              'description' => $term->description,
            ];
            $log[] = "Intentando crear categoría con datos: " . json_encode($cat_data);
            $cat_response = wp_remote_post("{$api_url}/api/upload-category", [
              'timeout' => 15,
              'body' => json_encode($cat_data),
              'headers' => ['Content-Type' => 'application/json']
            ]);
            if (is_wp_error($cat_response)) {
              $log[] = "Error al crear categoría {$term->name}: " . $cat_response->get_error_message();
              $remote_cat_id = 0;
            } else {
              $cat_body = json_decode(wp_remote_retrieve_body($cat_response), true);
              $log[] = "Respuesta POST crear categoría: " . json_encode($cat_body);
              if (isset($cat_body['success']) && $cat_body['success']) {
                $remote_cat_id = $cat_body['category_id'];
                $log[] = "Categoría {$term->name} creada exitosamente, RemoteCatID: {$remote_cat_id}";
              } else {
                if (isset($cat_body['category_id'])) {
                  $remote_cat_id = $cat_body['category_id'];
                  $log[] = "Categoría {$term->name} ya existe, RemoteCatID: {$remote_cat_id}";
                } else {
                  $log[] = "Error al crear categoría {$term->name}: " . ($cat_body['message'] ?? 'Respuesta inesperada.');
                  $remote_cat_id = 0;
                }
              }
            }
          }
          // Guardar mapping para futuros usos
          $category_mapping[$wc_cat_id] = $remote_cat_id;
          update_option('wc_exporter_category_mapping', $category_mapping);
        }
      }
      $data['category_id'] = $remote_cat_id;
    } else {
      $log[] = "Producto ID {$product->get_id()} no tiene categorías asignadas.";
      $data['category_id'] = 0;
    }

    // Procesar variantes (si el producto es variable)
    if ($product->is_type('variable')) {
      $variation_attributes = $product->get_variation_attributes();
      $available_variations = $product->get_available_variations();
      $variants = [];
      $attribute_keys_order = array_keys($variation_attributes); // Para mantener orden

      foreach ($variation_attributes as $attr_slug => $options) {
        $label = wc_attribute_label($attr_slug);
        $type = (strpos(strtolower($attr_slug), 'color') !== false) ? "color" : "dropdown";

        $options_array = [];
        foreach ($options as $option_value) {
          $matched = false;
          foreach ($available_variations as $variation) {
            $key = "attribute_" . $attr_slug;
            if (isset($variation['attributes'][$key]) && $variation['attributes'][$key] === $option_value) {
              $variation_id = $variation['variation_id'];
              $stock = (int) get_post_meta($variation_id, '_stock', true);
              if ($force_stock && $stock <= 0) {
                $stock = 1;
              }
              $options_array[] = [
                "name" => $option_value,
                "price" => $variation['display_price'],
                "stock" => $stock,
                "color" => (strpos(strtolower($attr_slug), 'color') !== false) ? $option_value : ""
              ];
              $matched = true;
              break;
            }
          }
          if (!$matched) {
            $options_array[] = [
              "name" => $option_value,
              "price" => $product->get_price(), // fallback
              "stock" => 0
            ];
          }
        }

        $variants[] = [
          "label" => $label,
          "type" => $type,
          "is_visible" => 1,
          "use_different_price" => 1,
          "options" => $options_array
        ];
      }
      $data["variants"] = $variants;

      // 🔄 Combinaciones de variantes (Color + Talle, etc.)
      $combinations = [];
      foreach ($available_variations as $variation) {
        $variation_id = $variation['variation_id'];
        $comb_options = [];

        foreach ($variation['attributes'] as $attr_key => $attr_val) {
          $comb_options[] = $attr_val;
        }

        $comb_stock = (int) get_post_meta($variation_id, '_stock', true);
        if ($force_stock && $comb_stock <= 0) {
          $comb_stock = 1;
        }

        $comb_price = $variation['display_price'];
        $comb_sku = get_post_meta($variation_id, '_sku', true) ?: "VAR-{$variation_id}";

        // Intentar buscar imagen de la variación
        $image_id = $variation['image_id'] ?? null;
        $comb_images = [];
        if ($image_id) {
          $img_url = wp_get_attachment_url($image_id);
          if ($img_url) {
            $comb_images[] = $img_url;
          }
        }

        $combinations[] = [
          "options" => $comb_options,
          "sku" => $comb_sku,
          "price" => $comb_price,
          "stock" => $comb_stock,
          "is_visible" => 1,
          "images" => $comb_images
        ];
      }

      $data["combinations"] = $combinations;

    }

    // Enviar el producto al endpoint remoto
    $response = wp_remote_post("{$api_url}/api/upload-product", [
      'timeout' => 15,
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

  $next_offset = count($products) == 10 ? $offset + 10 : null;
  wp_send_json([
    'message' => implode('<br>', $log),
    'exported_count' => count($products),
    'next_offset' => $next_offset
  ]);
}
