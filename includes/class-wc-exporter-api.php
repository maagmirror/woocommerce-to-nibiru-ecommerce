<?php
/**
 * Clase para manejar las comunicaciones con la API de Nibiru
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter_API {
    
    private $api_key;
    private $api_url;
    
    public function __construct($api_key, $api_url) {
        $this->api_key = $api_key;
        $this->api_url = rtrim($api_url, '/');
    }

    /**
     * Construye un WP_Error para respuestas HTTP no-2xx incluyendo un fragmento del body,
     * que suele traer el motivo real (p. ej. {"error":"Invalid API Key"} o aviso del WAF).
     *
     * @param array|WP_HTTP_Response $response Respuesta de wp_remote_*.
     * @param int                    $code     Código HTTP.
     * @return WP_Error
     */
    private function http_error($response, $code) {
        $body = (string) wp_remote_retrieve_body($response);
        $snippet = trim(wp_strip_all_tags($body));
        if (function_exists('mb_substr')) {
            if (mb_strlen($snippet) > 300) {
                $snippet = mb_substr($snippet, 0, 300) . '…';
            }
        } elseif (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300) . '…';
        }

        $msg = sprintf('Error HTTP: %d %s', (int) $code, wp_remote_retrieve_response_message($response));
        if ($snippet !== '') {
            $msg .= ' — Respuesta: ' . $snippet;
        }

        return new WP_Error('http_error', $msg, array('status' => (int) $code, 'body' => $body));
    }
    
    /**
     * Obtiene todas las categorías del ecommerce remoto
     * 
     * @return array|WP_Error Array con categorías o error
     */
    public function get_categories() {
        $url = "{$this->api_url}/api/categorias?apikey={$this->api_key}";
        $response = wp_remote_get($url, array('timeout' => 15));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error($response, $code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
    
    /**
     * Crea una categoría en el ecommerce remoto (POST /api/upload-category).
     * Campos típicos: name, parent_id (ID remoto), description, visibility, show_on_main_menu; apikey se añade aquí.
     *
     * @param array $category_data Datos de la categoría
     * @return array|WP_Error Respuesta de la API o error
     */
    public function create_category($category_data) {
        $url = "{$this->api_url}/api/upload-category";
        $data = array_merge(array('apikey' => $this->api_key), $category_data);
        
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json')
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error($response, $code);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
    
    /**
     * Obtiene todas las marcas del ecommerce remoto (GET /api/brands).
     * La respuesta es un array plano de marcas.
     *
     * @return array|WP_Error Array con marcas o error
     */
    public function get_brands() {
        $url = "{$this->api_url}/api/brands?apikey={$this->api_key}";
        $response = wp_remote_get($url, array('timeout' => 15));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error($response, $code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Crea o actualiza una marca en el ecommerce remoto (POST /api/upload-brand).
     * Campos: name (obligatorio), image_url (obligatorio para crear), title_meta_tag,
     * description, keywords, content; apikey se añade aquí.
     *
     * @param array $brand_data Datos de la marca
     * @return array|WP_Error Respuesta de la API o error
     */
    public function create_brand($brand_data) {
        $url = "{$this->api_url}/api/upload-brand";
        $data = array_merge(array('apikey' => $this->api_key), $brand_data);

        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json')
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error($response, $code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Sube un producto al ecommerce remoto
     *
     * @param array $product_data Datos del producto
     * @return array|WP_Error Respuesta de la API o error
     */
    public function upload_product($product_data) {
        $url = "{$this->api_url}/api/upload-product";
        $data = array_merge(array('apikey' => $this->api_key), $product_data);
        
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body' => json_encode($data),
            'headers' => array('Content-Type' => 'application/json')
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $this->http_error($response, $code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
}
