<?php
/**
 * Logger propio del plugin: escribe a un archivo en uploads y permite leerlo/borrarlo.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter_Logger {

    const FILE = 'wc-exporter.log';
    const MAX_SIZE = 2097152; // 2 MB: al superarlo se rota (se vacía)

    /**
     * Ruta absoluta del archivo de log (crea el directorio si hace falta).
     *
     * @return string Ruta o '' si uploads no está disponible.
     */
    public static function get_file_path() {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return '';
        }
        $dir = trailingslashit($upload['basedir']) . 'wc-exporter';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/' . self::FILE;
    }

    /**
     * Agrega una línea al log con timestamp y nivel.
     *
     * @param string $message Mensaje.
     * @param string $level   Nivel (ERROR, WARN, INFO).
     */
    public static function log($message, $level = 'ERROR') {
        $path = self::get_file_path();
        if (!$path) {
            return;
        }
        if (file_exists($path) && filesize($path) > self::MAX_SIZE) {
            @file_put_contents($path, ''); // rotación simple
        }
        $line = '[' . current_time('mysql') . '] [' . $level . '] ' . self::sanitize($message) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Lee el final del log (por defecto últimos ~200 KB).
     *
     * @param int $max_bytes Máximo de bytes a leer desde el final.
     * @return string Contenido.
     */
    public static function read($max_bytes = 204800) {
        $path = self::get_file_path();
        if (!$path || !file_exists($path)) {
            return '';
        }
        $size = filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        if ($size > $max_bytes) {
            fseek($fh, $size - $max_bytes);
        }
        $data = stream_get_contents($fh);
        fclose($fh);
        return $data !== false ? $data : '';
    }

    /**
     * Vacía el log.
     */
    public static function clear() {
        $path = self::get_file_path();
        if ($path && file_exists($path)) {
            @file_put_contents($path, '');
        }
    }

    /**
     * Quita saltos de línea/tags HTML del mensaje para mantener una línea por entrada.
     */
    private static function sanitize($message) {
        $message = wp_strip_all_tags((string) $message);
        return str_replace(array("\r", "\n"), ' ', $message);
    }
}
