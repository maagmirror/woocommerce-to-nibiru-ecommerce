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
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
    
    /**
     * Crea una categoría en el ecommerce remoto
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
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
}
