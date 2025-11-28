<?php
/**
 * Clase para detectar la moneda del producto
 * Integración con Currency per Product for WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter_Currency {
    
    /**
     * Obtiene la moneda de un producto
     * Intenta usar el plugin Currency per Product si está disponible
     * 
     * @param int $product_id ID del producto
     * @return string Código de moneda (USD, UYU, EUR, etc.)
     */
    public static function get_product_currency($product_id) {
        // Intentar usar el plugin Currency per Product for WooCommerce
        // Primero intentar función global (método más seguro)
        if (function_exists('alg_wc_cpp_get_product_currency')) {
            $currency = alg_wc_cpp_get_product_currency($product_id);
            if (!empty($currency)) {
                return $currency;
            }
        }
        
        // Intentar acceder directamente a la instancia del plugin
        if (function_exists('alg_wc_cpp')) {
            $cpp_instance = alg_wc_cpp();
            if ($cpp_instance && method_exists($cpp_instance, 'get_product_currency')) {
                $currency = $cpp_instance->get_product_currency($product_id);
                if (!empty($currency)) {
                    return $currency;
                }
            }
        }
        
        // Intentar obtener instancia de la clase si existe
        if (class_exists('Alg_WC_CPP_Core')) {
            // Intentar obtener una instancia singleton si existe
            if (method_exists('Alg_WC_CPP_Core', 'instance')) {
                $cpp_instance = Alg_WC_CPP_Core::instance();
                if ($cpp_instance && method_exists($cpp_instance, 'get_product_currency')) {
                    $currency = $cpp_instance->get_product_currency($product_id);
                    if (!empty($currency)) {
                        return $currency;
                    }
                }
            }
        }
        
        // Fallback: usar la función proporcionada por el usuario
        // Esta función replica la lógica del plugin Currency per Product
        $currency = self::get_product_currency_fallback($product_id);
        if (!empty($currency)) {
            return $currency;
        }
        
        // Último fallback: moneda por defecto de WooCommerce
        if (function_exists('get_woocommerce_currency')) {
            return get_woocommerce_currency();
        }
        
        // Si WooCommerce no está disponible, retornar USD por defecto
        return 'USD';
    }
    
    /**
     * Función fallback que replica la lógica del plugin Currency per Product
     * Basada en la función proporcionada por el usuario
     * 
     * @param int $product_id ID del producto
     * @return string Código de moneda
     */
    private static function get_product_currency_fallback($product_id) {
        // Verificar si existe la función del plugin directamente
        if (function_exists('alg_wc_cpp_get_product_currency')) {
            return alg_wc_cpp_get_product_currency($product_id);
        }
        
        // Intentar obtener desde meta del producto
        $currency = get_post_meta($product_id, '_alg_wc_cpp_currency', true);
        if (!empty($currency)) {
            return $currency;
        }
        
        // Lógica completa del plugin (replicada)
        $base_currency = get_option('woocommerce_currency');
        $do_check_by_users = ('yes' === get_option('alg_wc_cpp_by_users_enabled', 'no'));
        $do_check_by_user_roles = ('yes' === get_option('alg_wc_cpp_by_user_roles_enabled', 'no'));
        $do_check_by_product_cats = ('yes' === get_option('alg_wc_cpp_by_product_cats_enabled', 'no'));
        $do_check_by_product_tags = ('yes' === get_option('alg_wc_cpp_by_product_tags_enabled', 'no'));
        
        if ($do_check_by_users || $do_check_by_user_roles || $do_check_by_product_cats || $do_check_by_product_tags) {
            if ($do_check_by_users || $do_check_by_user_roles) {
                $product_author_id = get_post_field('post_author', $product_id);
            }
            if ($do_check_by_product_cats) {
                $_product_cats = self::get_product_terms($product_id, 'product_cat');
            }
            if ($do_check_by_product_tags) {
                $_product_tags = self::get_product_terms($product_id, 'product_tag');
            }
            
            $total_number = apply_filters('alg_wc_cpp', 1, 'value_total_number');
            for ($i = 1; $i <= $total_number; $i++) {
                if ($do_check_by_users) {
                    $users = get_option('alg_wc_cpp_users_' . $i, '');
                    if (!empty($users) && in_array($product_author_id, $users, true)) {
                        return get_option('alg_wc_cpp_currency_' . $i, $base_currency);
                    }
                }
                if ($do_check_by_user_roles) {
                    $user_roles = get_option('alg_wc_cpp_user_roles_' . $i, '');
                    if (!empty($user_roles) && self::is_user_role($user_roles, $product_author_id)) {
                        return get_option('alg_wc_cpp_currency_' . $i, $base_currency);
                    }
                }
                if ($do_check_by_product_cats) {
                    $product_cats = get_option('alg_wc_cpp_product_cats_' . $i, '');
                    if (!empty($_product_cats) && !empty($product_cats)) {
                        $_intersect = array_intersect($_product_cats, $product_cats);
                        if (!empty($_intersect)) {
                            return get_option('alg_wc_cpp_currency_' . $i, $base_currency);
                        }
                    }
                }
                if ($do_check_by_product_tags) {
                    $product_tags = get_option('alg_wc_cpp_product_tags_' . $i, '');
                    if (!empty($_product_tags) && !empty($product_tags)) {
                        $_intersect = array_intersect($_product_tags, $product_tags);
                        if (!empty($_intersect)) {
                            return get_option('alg_wc_cpp_currency_' . $i, $base_currency);
                        }
                    }
                }
            }
        }
        
        return $base_currency;
    }
    
    /**
     * Obtiene términos de un producto
     */
    private static function get_product_terms($product_id, $taxonomy) {
        $terms = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'ids'));
        return is_array($terms) ? $terms : array();
    }
    
    /**
     * Verifica si un usuario tiene un rol específico
     */
    private static function is_user_role($roles, $user_id) {
        if (empty($roles) || !is_array($roles)) {
            return false;
        }
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        return !empty(array_intersect($roles, $user->roles));
    }
    
    /**
     * Obtiene la moneda de una variación
     * 
     * @param int $variation_id ID de la variación
     * @param int $parent_product_id ID del producto padre
     * @return string Código de moneda
     */
    public static function get_variation_currency($variation_id, $parent_product_id) {
        // Primero intentar obtener la moneda de la variación directamente
        $currency = self::get_product_currency($variation_id);
        
        // Si no tiene moneda propia, usar la del producto padre
        $default_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
        if (empty($currency) || $currency === $default_currency) {
            $currency = self::get_product_currency($parent_product_id);
        }
        
        return $currency;
    }
}
