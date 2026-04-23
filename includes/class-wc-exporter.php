<?php
/**
 * Clase principal del exportador
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter {
    
    private $api;
    private $category_mapping;
    
    public function __construct() {
        add_action('wp_ajax_wc_export_products', array($this, 'export_products'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Ruta REST alternativa a admin-ajax (útil si admin-ajax.php está bloqueado).
     */
    public function register_rest_routes() {
        register_rest_route(
            'wc-exporter/v1',
            '/export-batch',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_export_batch'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            )
        );
    }
    
    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_export_batch($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response(
                array(
                    'success' => false,
                    'message' => 'Nonce REST inválido o caducado. Recarga la página.',
                ),
                403
            );
        }
        
        $body = $request->get_json_params();
        if (!is_array($body) || empty($body)) {
            $body = $request->get_body_params();
        }
        if (!is_array($body)) {
            $body = array();
        }
        
        $result = $this->run_export_batch(
            array(
                'offset'           => isset($body['offset']) ? (int) $body['offset'] : 0,
                'api_key'          => isset($body['api_key']) ? sanitize_text_field((string) $body['api_key']) : '',
                'api_url'          => isset($body['api_url']) ? sanitize_text_field((string) $body['api_url']) : '',
                'force_stock'      => $this->coerce_bool($body['force_stock'] ?? false),
                'force_categories' => $this->coerce_bool($body['force_categories'] ?? false),
                'show_log'         => $this->coerce_bool($body['show_log'] ?? false),
            )
        );
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * @param mixed $value Valor desde JSON o POST.
     */
    private function coerce_bool($value) {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        return false;
    }
    
    /**
     * Exporta productos en batches (admin-ajax).
     */
    public function export_products() {
        ob_start();
        
        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json(array('success' => false, 'message' => 'No tienes permisos para realizar esta acción.'));
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'wc_exporter_export')) {
            ob_end_clean();
            wp_send_json(array('success' => false, 'message' => 'Sesión de seguridad caducada. Recarga esta página e inténtalo de nuevo.'));
            return;
        }
        
        $result = $this->run_export_batch(
            array(
                'offset'           => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
                'api_key'          => isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '',
                'api_url'          => isset($_POST['api_url']) ? sanitize_text_field(wp_unslash($_POST['api_url'])) : '',
                'force_stock'      => !empty($_POST['force_stock']) && $_POST['force_stock'] === '1',
                'force_categories' => !empty($_POST['force_categories']) && $_POST['force_categories'] === '1',
                'show_log'         => !empty($_POST['show_log']) && $_POST['show_log'] === '1',
            )
        );
        
        ob_end_clean();
        wp_send_json($result);
    }
    
    /**
     * Ejecuta un lote de exportación (compartido por AJAX y REST).
     *
     * @param array $args offset, api_key, api_url, force_stock, force_categories, show_log
     * @return array Respuesta lista para JSON
     */
    private function run_export_batch(array $args) {
        if (!function_exists('wc_get_products')) {
            return array('success' => false, 'message' => 'Error: WooCommerce no está disponible.');
        }
        
        if (!class_exists('WC_Exporter_API') || !class_exists('WC_Exporter_Currency')) {
            return array('success' => false, 'message' => 'Error: Clases del plugin no están cargadas correctamente.');
        }
        
        $api_key = isset($args['api_key']) ? $args['api_key'] : '';
        $api_url = isset($args['api_url']) ? $args['api_url'] : '';
        if ($api_key === '' || $api_url === '') {
            return array('success' => false, 'message' => 'Error: Falta API Key o API URL.');
        }
        
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
        $api_url = rtrim($api_url, '/');
        $force_stock = !empty($args['force_stock']);
        $force_categories = !empty($args['force_categories']);
        $show_log = !empty($args['show_log']);
        
        try {
            $this->api = new WC_Exporter_API($api_key, $api_url);
            
            if ($force_categories) {
                $this->category_mapping = array();
                update_option('wc_exporter_category_mapping', $this->category_mapping);
            } else {
                $this->category_mapping = get_option('wc_exporter_category_mapping', array());
            }
            
            $products = wc_get_products(
                array(
                    'limit'  => 10,
                    'offset' => $offset,
                    'status' => 'publish',
                )
            );
            
            if (is_wp_error($products)) {
                return array('success' => false, 'message' => 'Error al obtener productos: ' . $products->get_error_message());
            }
            
            $log = array();
            
            if (empty($products)) {
                return array(
                    'message'          => 'No hay más productos para exportar.',
                    'exported_count'   => 0,
                    'next_offset'      => null,
                    'product_previews' => array(),
                );
            }
            
            $product_previews = array();
            
            foreach ($products as $product) {
                $preview = array(
                    'title'         => $product->get_name(),
                    'sku'           => '',
                    'thumb_url'     => $product->get_image_id() ? (string) wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '',
                    'status'        => 'info',
                    'detail'        => '',
                    'request_json'  => null,
                    'response_json' => null,
                );
                
                try {
                    $product_data = $this->process_product($product, $force_stock, $log);
                    $preview['sku'] = isset($product_data['sku']) ? (string) $product_data['sku'] : '';
                    
                    if ($show_log) {
                        $preview['request_json'] = self::json_debug($product_data);
                    }
                    
                    $response = $this->api->upload_product($product_data);
                    
                    if (is_wp_error($response)) {
                        $preview['status'] = 'error';
                        $preview['detail'] = $response->get_error_message();
                        $log[] = "❌ Error en SKU {$product_data['sku']}: " . $response->get_error_message();
                    } elseif (isset($response['error'])) {
                        $preview['status'] = 'error';
                        $preview['detail'] = (string) $response['error'];
                        $log[] = "❌ Error en SKU {$product_data['sku']}: " . $response['error'];
                    } else {
                        $action = isset($response['action']) ? $response['action'] : 'unknown';
                        $product_id_rem = isset($response['product_id']) ? $response['product_id'] : 'N/A';
                        $preview['status'] = 'success';
                        $preview['detail'] = "{$action} · ID remoto: {$product_id_rem}";
                        $log[] = "✅ SKU {$product_data['sku']} - {$action} - Product ID: {$product_id_rem}";
                        if ($show_log) {
                            $preview['response_json'] = self::json_debug($response);
                        }
                    }
                } catch (Exception $e) {
                    $preview['status'] = 'error';
                    $preview['detail'] = $e->getMessage();
                    $log[] = "Error procesando producto ID {$product->get_id()}: " . $e->getMessage();
                }
                
                $product_previews[] = $preview;
            }
            
            $next_offset = count($products) == 10 ? $offset + 10 : null;
            
            return array(
                'success'          => true,
                'message'          => implode('<br>', $log),
                'exported_count'   => count($products),
                'next_offset'      => $next_offset,
                'product_previews' => $product_previews,
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error fatal: ' . $e->getMessage() . ' en línea ' . $e->getLine(),
            );
        } catch (Error $e) {
            return array(
                'success' => false,
                'message' => 'Error fatal PHP: ' . $e->getMessage() . ' en línea ' . $e->getLine() . ' del archivo ' . $e->getFile(),
            );
        }
    }
    
    /**
     * Procesa un producto y retorna sus datos para la API
     */
    private function process_product($product, $force_stock, &$log) {
        $product_id = $product->get_id();
        
        // Obtener moneda del producto
        $currency = WC_Exporter_Currency::get_product_currency($product_id);
        $log[] = "Producto ID {$product_id} - Moneda detectada: {$currency}";
        
        // Procesar imágenes del producto principal
        $images = $this->get_product_images($product, $log);
        
        // Datos básicos
        $product_stock = (int) $product->get_stock_quantity();
        if ($force_stock && $product_stock <= 0) {
            $product_stock = 1;
            $log[] = "Forzado: Stock ajustado a 1 para producto ID {$product_id}";
        }
        
        $data = array(
            'sku' => $product->get_sku() ?: $product->get_id(),
            'title' => $product->get_name(),
            'price' => (float) $product->get_price(),
            'currency' => $currency,
            'stock' => $product_stock,
            'product_type' => 'physical',
            'visibility' => 1,
            'status' => 1,
            'image_urls' => $images,
            'description' => $this->sanitize_html_with_styles($product->get_description()),
            'short_description' => $this->sanitize_html_with_styles($product->get_short_description()),
            'seo_title' => $product->get_name(),
            'seo_description' => $this->sanitize_html_with_styles($product->get_short_description()),
            'seo_keywords' => implode(', ', wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names'))),
        );
        
        // Procesar categoría
        $category_id = $this->process_category($product, $log);
        $data['category_id'] = $category_id;
        
        // Procesar variantes si es producto variable
        if ($product->is_type('variable')) {
            $variants_data = $this->process_variants($product, $currency, $force_stock, $log);
            $data['variants'] = $variants_data['variants'];
            $data['combinations'] = $variants_data['combinations'];
        }
        
        return $data;
    }
    
    /**
     * Serializa datos para depuración en la UI (UTF-8 tolerante).
     */
    private static function json_debug($data) {
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = wp_json_encode($data, $flags);
        return $json ? $json : '{"_error":"No se pudo serializar JSON"}';
    }
    
    /**
     * Obtiene las imágenes de un producto
     */
    private function get_product_images($product, &$log) {
        $images = array();
        
        // Imagen principal
        if ($product->get_image_id()) {
            $img_url = $this->get_absolute_image_url($product->get_image_id());
            if ($img_url) {
                $images[] = $img_url;
                $log[] = "Imagen principal: {$img_url}";
            }
        }
        
        // Imágenes de galería
        $gallery_image_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_image_ids)) {
            foreach ($gallery_image_ids as $image_id) {
                $img_url = $this->get_absolute_image_url($image_id);
                if ($img_url) {
                    $images[] = $img_url;
                    $log[] = "Imagen de galería: {$img_url}";
                }
            }
        }
        
        // Si no hay imágenes, usar placeholder
        if (empty($images)) {
            $images = array("https://via.placeholder.com/500");
            $log[] = "No se encontraron imágenes, se usa placeholder.";
        }
        
        return $images;
    }
    
    /**
     * Obtiene la URL absoluta de una imagen asegurando que sea accesible públicamente
     * 
     * @param int $attachment_id ID del attachment
     * @return string URL absoluta de la imagen
     */
    private function get_absolute_image_url($attachment_id) {
        if (!$attachment_id) {
            return '';
        }
        
        // Obtener URL del attachment
        $url = wp_get_attachment_url($attachment_id);
        
        if (!$url) {
            return '';
        }
        
        // Si la URL ya es absoluta (empieza con http:// o https://), retornarla
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }
        
        // Si es relativa, convertirla a absoluta usando home_url()
        if (strpos($url, '/') === 0) {
            // URL relativa desde la raíz
            return home_url($url);
        } else {
            // URL relativa desde el directorio actual
            return home_url('/' . $url);
        }
    }
    
    /**
     * Obtiene múltiples imágenes de una variación
     * Incluye imagen de la variación y galería si existe
     */
    private function get_variation_images($variation_id, &$log) {
        $images = array();
        
        // Imagen principal de la variación
        $image_id = get_post_thumbnail_id($variation_id);
        if ($image_id) {
            $img_url = $this->get_absolute_image_url($image_id);
            if ($img_url) {
                $images[] = $img_url;
                $log[] = "Imagen variación {$variation_id}: {$img_url}";
            }
        }
        
        // Galería de la variación (si existe en meta)
        $gallery_ids = get_post_meta($variation_id, '_wc_additional_variation_images', true);
        if (!empty($gallery_ids)) {
            $gallery_ids = explode(',', $gallery_ids);
            foreach ($gallery_ids as $gallery_id) {
                $gallery_id = trim($gallery_id);
                if ($gallery_id) {
                    $img_url = $this->get_absolute_image_url($gallery_id);
                    if ($img_url) {
                        $images[] = $img_url;
                        $log[] = "Imagen galería variación {$variation_id}: {$img_url}";
                    }
                }
            }
        }
        
        // También buscar en otros meta comunes para galerías de variaciones
        $additional_images = get_post_meta($variation_id, '_product_image_gallery', true);
        if (!empty($additional_images)) {
            $image_ids = explode(',', $additional_images);
            foreach ($image_ids as $img_id) {
                $img_id = trim($img_id);
                if ($img_id && $img_id != $image_id) {
                    $img_url = $this->get_absolute_image_url($img_id);
                    if ($img_url) {
                        $images[] = $img_url;
                    }
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Procesa la categoría del producto
     */
    private function process_category($product, &$log) {
        $cat_ids = $product->get_category_ids();
        if (empty($cat_ids)) {
            $log[] = "Producto ID {$product->get_id()} no tiene categorías asignadas.";
            return 0;
        }
        
        return $this->ensure_wc_category_on_remote((int) $cat_ids[0], $log);
    }
    
    /**
     * Garantiza que una categoría WooCommerce exista en remoto (mapeo, GET por slug o POST /api/upload-category).
     * POST espera parent_id como ID remoto del padre, no el term_id de WordPress.
     */
    private function ensure_wc_category_on_remote($wc_cat_id, &$log) {
        $wc_cat_id = (int) $wc_cat_id;
        if ($wc_cat_id <= 0) {
            return 0;
        }
        
        if (isset($this->category_mapping[$wc_cat_id])) {
            $remote_cat_id = (int) $this->category_mapping[$wc_cat_id];
            $log[] = "Mapping existente: WooCatID {$wc_cat_id} => RemoteCatID {$remote_cat_id}";
            return $remote_cat_id;
        }
        
        $term = get_term($wc_cat_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            $log[] = "No se encontró el término para WooCatID {$wc_cat_id}";
            return 0;
        }
        
        $log[] = "Procesando categoría: WooCatID {$wc_cat_id} - Name: {$term->name} - Slug: {$term->slug}";
        
        $remote_cat_id = $this->find_remote_category($term->slug, $log);
        
        if (!$remote_cat_id) {
            $remote_cat_id = $this->create_remote_category($term, $log);
        }
        
        if ($remote_cat_id) {
            $this->category_mapping[$wc_cat_id] = $remote_cat_id;
            update_option('wc_exporter_category_mapping', $this->category_mapping);
        }
        
        return $remote_cat_id;
    }
    
    /**
     * Busca una categoría en el ecommerce remoto por slug
     */
    private function find_remote_category($slug, &$log) {
        $response = $this->api->get_categories();
        
        if (is_wp_error($response)) {
            $log[] = "Error en GET categorías remotas: " . $response->get_error_message();
            return 0;
        }
        
        if (isset($response['success']) && $response['success'] && !empty($response['categories'])) {
            foreach ($response['categories'] as $remote_cat) {
                if (isset($remote_cat['slug']) && $remote_cat['slug'] == $slug) {
                    $log[] = "Categoría encontrada en remoto por slug: {$slug} => RemoteCatID {$remote_cat['id']}";
                    return (int) $remote_cat['id'];
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Crea una categoría en el ecommerce remoto
     */
    private function create_remote_category($term, &$log) {
        $remote_parent_id = 0;
        if (!empty($term->parent)) {
            $remote_parent_id = $this->ensure_wc_category_on_remote((int) $term->parent, $log);
            if (!$remote_parent_id) {
                $log[] = "Padre WooCatID {$term->parent} no pudo resolverse al remoto; usando parent_id 0 (comportamiento API).";
                $remote_parent_id = 0;
            }
        }
        
        // POST /api/upload-category: name (slug autogenerado), parent_id remoto, opcionales description, visibility, show_on_main_menu
        $cat_data = array(
            'name' => $term->name,
            'parent_id' => $remote_parent_id,
            'description' => $term->description ? $term->description : '',
            'visibility' => 1,
        );
        
        $log[] = "Intentando crear categoría con datos: " . json_encode($cat_data);
        
        $response = $this->api->create_category($cat_data);
        
        if (is_wp_error($response)) {
            $log[] = "Error al crear categoría {$term->name}: " . $response->get_error_message();
            return 0;
        }
        
        if (isset($response['success']) && $response['success']) {
            $remote_cat_id = (int) $response['category_id'];
            $log[] = "Categoría {$term->name} creada exitosamente, RemoteCatID: {$remote_cat_id}";
            return $remote_cat_id;
        } elseif (isset($response['category_id'])) {
            $remote_cat_id = (int) $response['category_id'];
            $log[] = "Categoría {$term->name} ya existe, RemoteCatID: {$remote_cat_id}";
            return $remote_cat_id;
        } else {
            $log[] = "Error al crear categoría {$term->name}: " . (isset($response['message']) ? $response['message'] : 'Respuesta inesperada.');
            return 0;
        }
    }
    
    /**
     * Procesa las variantes de un producto variable
     */
    private function process_variants($product, $parent_currency, $force_stock, &$log) {
        $variation_attributes = $product->get_variation_attributes();
        $available_variations = $product->get_available_variations();
        $variants = array();
        
        foreach ($variation_attributes as $attr_slug => $options) {
            $label = wc_attribute_label($attr_slug);
            $type = (strpos(strtolower($attr_slug), 'color') !== false) ? "color" : "dropdown";
            
            $options_array = array();
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
                        $options_array[] = array(
                            "name" => $option_value,
                            "price" => $variation['display_price'],
                            "stock" => $stock,
                            "color" => (strpos(strtolower($attr_slug), 'color') !== false) ? $option_value : ""
                        );
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $options_array[] = array(
                        "name" => $option_value,
                        "price" => $product->get_price(),
                        "stock" => 0
                    );
                }
            }
            
            $variants[] = array(
                "label" => $label,
                "type" => $type,
                "is_visible" => 1,
                "use_different_price" => 1,
                "options" => $options_array
            );
        }
        
        // Procesar combinaciones con múltiples imágenes
        $combinations = array();
        foreach ($available_variations as $variation) {
            $variation_id = $variation['variation_id'];
            $comb_options = array();
            
            foreach ($variation['attributes'] as $attr_key => $attr_val) {
                $comb_options[] = $attr_val;
            }
            
            $comb_stock = (int) get_post_meta($variation_id, '_stock', true);
            if ($force_stock && $comb_stock <= 0) {
                $comb_stock = 1;
            }
            
            $comb_price = $variation['display_price'];
            $comb_sku = get_post_meta($variation_id, '_sku', true) ?: "VAR-{$variation_id}";
            
            // Obtener múltiples imágenes de la variación
            $comb_images = $this->get_variation_images($variation_id, $log);
            
            // Si no hay imágenes en la variación, usar las del producto padre
            if (empty($comb_images)) {
                $parent_product = wc_get_product($product->get_id());
                $comb_images = $this->get_product_images($parent_product, $log);
            }
            
            // Obtener moneda de la variación
            $variation_currency = WC_Exporter_Currency::get_variation_currency($variation_id, $product->get_id());
            
            $combinations[] = array(
                "options" => $comb_options,
                "sku" => $comb_sku,
                "price" => $comb_price,
                "stock" => $comb_stock,
                "is_visible" => 1,
                "images" => $comb_images
            );
            
            $log[] = "Combinación SKU {$comb_sku} - Moneda: {$variation_currency} - Imágenes: " . count($comb_images);
        }
        
        return array(
            'variants' => $variants,
            'combinations' => $combinations
        );
    }
    
    /**
     * Sanitiza HTML manteniendo atributos style
     * Permite mantener estilos inline como color, background, etc.
     * 
     * @param string $html Contenido HTML a sanitizar
     * @return string HTML sanitizado con estilos preservados
     */
    private function sanitize_html_with_styles($html) {
        if (empty($html)) {
            return '';
        }
        
        // Definir etiquetas permitidas con sus atributos, incluyendo style
        $allowed_html = array(
            'p' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'br' => array(),
            'strong' => array(
                'style' => true,
                'class' => true,
            ),
            'em' => array(
                'style' => true,
                'class' => true,
            ),
            'u' => array(
                'style' => true,
                'class' => true,
            ),
            's' => array(
                'style' => true,
                'class' => true,
            ),
            'ul' => array(
                'style' => true,
                'class' => true,
            ),
            'ol' => array(
                'style' => true,
                'class' => true,
            ),
            'li' => array(
                'style' => true,
                'class' => true,
            ),
            'a' => array(
                'href' => true,
                'title' => true,
                'target' => true,
                'style' => true,
                'class' => true,
            ),
            'img' => array(
                'src' => true,
                'alt' => true,
                'title' => true,
                'width' => true,
                'height' => true,
                'style' => true,
                'class' => true,
            ),
            'h1' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'h2' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'h3' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'h4' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'h5' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'h6' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'div' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'span' => array(
                'style' => true,
                'class' => true,
                'id' => true,
            ),
            'table' => array(
                'style' => true,
                'class' => true,
                'border' => true,
                'cellpadding' => true,
                'cellspacing' => true,
            ),
            'thead' => array(
                'style' => true,
                'class' => true,
            ),
            'tbody' => array(
                'style' => true,
                'class' => true,
            ),
            'tr' => array(
                'style' => true,
                'class' => true,
            ),
            'td' => array(
                'style' => true,
                'class' => true,
                'colspan' => true,
                'rowspan' => true,
            ),
            'th' => array(
                'style' => true,
                'class' => true,
                'colspan' => true,
                'rowspan' => true,
            ),
            'blockquote' => array(
                'style' => true,
                'class' => true,
            ),
            'code' => array(
                'style' => true,
                'class' => true,
            ),
            'pre' => array(
                'style' => true,
                'class' => true,
            ),
        );
        
        // Sanitizar HTML manteniendo estilos
        return wp_kses($html, $allowed_html);
    }
}
