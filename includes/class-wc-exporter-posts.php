<?php
/**
 * Sección "Posts": exporta entradas (post_type=post) de WordPress al blog del
 * ecommerce Nibiru vía POST /api/upload-post. Independiente del export de productos
 * (misma API key/URL, mismo patrón de batch + log). Idempotente por el ID del post
 * de WP (external_id) del lado del ecommerce.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Exporter_Posts {

    const BATCH_SIZE = 10;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'), 20);
        add_action('admin_post_wc_exporter_run_posts_batch', array($this, 'handle_batch'));
    }

    public function add_menu() {
        add_submenu_page(
            'wc_exporter',
            'Exportar Posts',
            'Posts (Blog)',
            'manage_options',
            'wc_exporter_posts',
            array($this, 'render_page')
        );
    }

    /**
     * Total de posts publicados (para la barra de progreso).
     */
    private function get_total_posts() {
        $counts = wp_count_posts('post');
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    /**
     * Handler del lote vía admin-post.php (evita POST a admin.php, bloqueado en algunos WAF).
     */
    public function handle_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json(array('success' => false, 'message' => 'No tienes permisos para realizar esta acción.'));
            return;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'wc_exporter_run_posts_batch')) {
            wp_send_json(array('success' => false, 'message' => 'Sesión de seguridad caducada. Recarga esta página e inténtalo de nuevo.'));
            return;
        }

        ob_start();
        $result = $this->run_posts_batch(array(
            'offset'    => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
            'api_key'   => isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '',
            'api_url'   => isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '',
            'lang_id'   => isset($_POST['lang_id']) ? (int) $_POST['lang_id'] : 1,
            'show_log'  => !empty($_POST['show_log']) && $_POST['show_log'] === '1',
        ));
        ob_end_clean();
        wp_send_json($result);
    }

    /**
     * Procesa un lote de posts y los empuja al ecommerce.
     * @return array respuesta lista para JSON (success, message, exported_count, next_offset, post_previews)
     */
    public function run_posts_batch(array $args) {
        if (!class_exists('WC_Exporter_API')) {
            return array('success' => false, 'message' => 'Error: clases del plugin no cargadas.');
        }
        $api_key = isset($args['api_key']) ? $args['api_key'] : '';
        $api_url = isset($args['api_url']) ? rtrim($args['api_url'], '/') : '';
        if ($api_key === '' || $api_url === '') {
            return array('success' => false, 'message' => 'Error: falta API Key o API URL.');
        }
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
        $lang_id = isset($args['lang_id']) ? (int) $args['lang_id'] : 1;
        if ($lang_id < 1) {
            $lang_id = 1;
        }
        $show_log = !empty($args['show_log']);

        $api = new WC_Exporter_API($api_key, $api_url);

        $posts = get_posts(array(
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => self::BATCH_SIZE,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => false,
        ));

        if (empty($posts)) {
            return array(
                'success'        => true,
                'message'        => 'No hay más posts para exportar.',
                'exported_count' => 0,
                'next_offset'    => null,
                'post_previews'  => array(),
            );
        }

        $log = array();
        $previews = array();

        foreach ($posts as $post) {
            $preview = array(
                'title'         => get_the_title($post),
                'status'        => 'info',
                'detail'        => '',
                'thumb_url'     => (string) get_the_post_thumbnail_url($post, 'thumbnail'),
                'request_json'  => null,
                'response_json' => null,
            );

            try {
                $payload = $this->build_post_payload($post, $lang_id);
                if ($show_log) {
                    $preview['request_json'] = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }

                $response = $api->create_post($payload);

                if (is_wp_error($response)) {
                    $preview['status'] = 'error';
                    $preview['detail'] = $response->get_error_message();
                    $log[] = '❌ Post ID ' . $post->ID . ': ' . $response->get_error_message();
                    WC_Exporter_Logger::log('Error subiendo post ID ' . $post->ID . ': ' . $response->get_error_message());
                } elseif (isset($response['error'])) {
                    $preview['status'] = 'error';
                    $preview['detail'] = (string) $response['error'];
                    $log[] = '❌ Post ID ' . $post->ID . ': ' . $response['error'];
                    WC_Exporter_Logger::log('Error API post ID ' . $post->ID . ': ' . $response['error']);
                } else {
                    $action = isset($response['action']) ? $response['action'] : 'unknown';
                    $remote_id = isset($response['post_id']) ? $response['post_id'] : 'N/A';
                    $preview['status'] = 'success';
                    $preview['detail'] = $action . ' · ID remoto: ' . $remote_id;
                    if (!empty($response['warning'])) {
                        $preview['detail'] .= ' · ' . $response['warning'];
                    }
                    $log[] = '✅ Post ID ' . $post->ID . ' - ' . $action . ' - remoto: ' . $remote_id;
                    if ($show_log) {
                        $preview['response_json'] = wp_json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }
                }
            } catch (Exception $e) {
                $preview['status'] = 'error';
                $preview['detail'] = $e->getMessage();
                $log[] = 'Error procesando post ID ' . $post->ID . ': ' . $e->getMessage();
                WC_Exporter_Logger::log('Excepción post ID ' . $post->ID . ': ' . $e->getMessage());
            }

            $previews[] = $preview;
        }

        $next_offset = count($posts) === self::BATCH_SIZE ? $offset + self::BATCH_SIZE : null;

        return array(
            'success'        => true,
            'message'        => implode('<br>', $log),
            'exported_count' => count($posts),
            'next_offset'    => $next_offset,
            'post_previews'  => $previews,
        );
    }

    /**
     * Renderiza el contenido del post a HTML limpio. WordPress guarda el contenido con
     * markup de bloques Gutenberg (comentarios `<!-- wp:... -->`) que NO deben viajar
     * crudos al ecommerce. Se corre el pipeline core (do_blocks → texturize → autop →
     * shortcodes) SIN aplicar `the_content` completo, para evitar que plugins de terceros
     * inyecten "posts relacionados", anuncios, etc. Al final se limpian comentarios de
     * bloque residuales por las dudas.
     */
    private function render_post_content($post) {
        $content = (string) $post->post_content;
        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }
        if (function_exists('wptexturize')) {
            $content = wptexturize($content);
        }
        if (function_exists('wpautop')) {
            $content = wpautop($content);
        }
        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }
        // Barrido defensivo de comentarios de bloque que hayan sobrevivido.
        $content = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $content);
        return trim((string) $content);
    }

    /**
     * Arma el payload para /api/upload-post desde un WP_Post.
     */
    private function build_post_payload($post, $lang_id) {
        // Categoría principal: primera categoría del post (el ecommerce la busca/crea por nombre).
        $category_name = '';
        $cats = get_the_category($post->ID);
        if (!empty($cats) && isset($cats[0]->name)) {
            $category_name = $cats[0]->name;
        }

        // Keywords: tags del post separados por coma.
        $keywords = '';
        $tags = get_the_tags($post->ID);
        if (!empty($tags) && is_array($tags)) {
            $names = array();
            foreach ($tags as $t) {
                $names[] = $t->name;
            }
            $keywords = implode(', ', $names);
        }

        // Imagen destacada en tamaño completo (el ecommerce la descarga y resizea).
        $image_url = (string) get_the_post_thumbnail_url($post, 'full');

        // Resumen: excerpt manual si hay; si no, se deja vacío (el ecommerce no lo exige).
        $summary = has_excerpt($post) ? get_the_excerpt($post) : '';

        return array(
            'external_source' => 'wordpress',
            'external_id'     => (string) $post->ID,
            'lang_id'         => (int) $lang_id,
            'title'           => (string) $post->post_title,
            'slug'            => (string) $post->post_name,
            'content'         => $this->render_post_content($post),
            'summary'         => (string) $summary,
            'keywords'        => (string) $keywords,
            'category_name'   => (string) $category_name,
            'image_url'       => $image_url,
            'created_at'      => (string) $post->post_date,
        );
    }

    /**
     * Pantalla de administración de la exportación de posts.
     */
    public function render_page() {
        $total = $this->get_total_posts();
        $ajax_url = admin_url('admin-post.php');
        $nonce = wp_create_nonce('wc_exporter_run_posts_batch');
        ?>
        <div class="wrap">
            <h1>Exportar Posts del blog · woo to nibiru</h1>
            <p class="description">
                Trae las entradas (posts) de WordPress al blog del ecommerce Nibiru. Usa la misma
                API Key y URL que la exportación de productos. Reejecutar es seguro: cada post se
                identifica por su ID de WordPress (no se duplica; se actualiza).
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="posts_api_key">API Key</label></th>
                    <td><input type="text" id="posts_api_key" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="posts_api_url">API URL</label></th>
                    <td>
                        <input type="text" id="posts_api_url" class="regular-text" placeholder="https://tu-tienda.com">
                        <p class="description">Sin barra final. Ej: <code>https://tu-tienda.com</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="posts_lang_id">Idioma (lang_id)</label></th>
                    <td>
                        <input type="number" id="posts_lang_id" class="small-text" value="1" min="1">
                        <p class="description">ID del idioma del blog en el ecommerce (por defecto 1).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Opciones</th>
                    <td>
                        <label><input type="checkbox" id="posts_show_log"> Mostrar datos enviados/recibidos (debug)</label>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary" id="posts_start">Exportar Posts</button>
                <span id="posts_progress_label" style="margin-left:10px;"></span>
            </p>
            <p class="description">Total de posts publicados: <strong><?php echo (int) $total; ?></strong></p>

            <div id="posts_progress_wrap" style="display:none;background:#e5e5e5;border-radius:4px;height:16px;width:100%;max-width:600px;overflow:hidden;margin:8px 0;">
                <div id="posts_progress_bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
            </div>

            <div id="posts_status" style="margin-top:14px;"></div>
        </div>

        <script>
        (function () {
            var TOTAL = <?php echo (int) $total; ?>;
            var AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
            var NONCE = <?php echo wp_json_encode($nonce); ?>;
            var STORE_KEY = 'wc_exporter_posts_cfg';

            function $(id) { return document.getElementById(id); }

            // Persistencia local de la config (misma idea que la pantalla de productos).
            function loadCfg() {
                try {
                    var raw = localStorage.getItem(STORE_KEY);
                    if (!raw) { return; }
                    var o = JSON.parse(raw);
                    if (o.api_key) { $('posts_api_key').value = o.api_key; }
                    if (o.api_url) { $('posts_api_url').value = o.api_url; }
                    if (o.lang_id) { $('posts_lang_id').value = o.lang_id; }
                    $('posts_show_log').checked = !!o.show_log;
                } catch (e) {}
            }
            function saveCfg() {
                try {
                    localStorage.setItem(STORE_KEY, JSON.stringify({
                        api_key: $('posts_api_key').value.trim(),
                        api_url: $('posts_api_url').value.trim(),
                        lang_id: $('posts_lang_id').value,
                        show_log: $('posts_show_log').checked
                    }));
                } catch (e) {}
            }
            ['posts_api_key', 'posts_api_url', 'posts_lang_id', 'posts_show_log'].forEach(function (id) {
                var el = $(id);
                if (el) { el.addEventListener('change', saveCfg); }
            });
            loadCfg();

            function esc(s) {
                var d = document.createElement('div');
                d.textContent = (s === null || s === undefined) ? '' : String(s);
                return d.innerHTML;
            }

            function renderPreview(p) {
                var color = p.status === 'success' ? '#e6f4ea' : (p.status === 'error' ? '#fce8e6' : '#f1f3f4');
                var html = '<div style="border:1px solid #ddd;border-left:4px solid ' + (p.status === 'error' ? '#d93025' : '#188038') + ';background:' + color + ';padding:8px 10px;margin:6px 0;border-radius:3px;">';
                if (p.thumb_url) {
                    html += '<img src="' + esc(p.thumb_url) + '" style="width:40px;height:40px;object-fit:cover;float:left;margin-right:10px;border-radius:3px;">';
                }
                html += '<strong>' + esc(p.title) + '</strong><br><span style="font-size:12px;color:#555;">' + esc(p.detail) + '</span>';
                if (p.request_json) {
                    html += '<details style="margin-top:4px;"><summary style="cursor:pointer;font-size:11px;">Enviado</summary><pre style="max-height:200px;overflow:auto;font-size:11px;">' + esc(p.request_json) + '</pre></details>';
                }
                if (p.response_json) {
                    html += '<details style="margin-top:4px;"><summary style="cursor:pointer;font-size:11px;">Respuesta</summary><pre style="max-height:200px;overflow:auto;font-size:11px;">' + esc(p.response_json) + '</pre></details>';
                }
                html += '<div style="clear:both;"></div></div>';
                return html;
            }

            function setProgress(done) {
                var pct = TOTAL > 0 ? Math.min(100, Math.round((done / TOTAL) * 100)) : 0;
                $('posts_progress_bar').style.width = pct + '%';
                $('posts_progress_label').textContent = done + ' / ' + TOTAL + ' (' + pct + '%)';
            }

            function runBatch(offset, btn) {
                var body = new FormData();
                body.append('action', 'wc_exporter_run_posts_batch');
                body.append('_wpnonce', NONCE);
                body.append('offset', offset);
                body.append('api_key', $('posts_api_key').value.trim());
                body.append('api_url', $('posts_api_url').value.trim());
                body.append('lang_id', $('posts_lang_id').value);
                body.append('show_log', $('posts_show_log').checked ? '1' : '0');

                fetch(AJAX_URL, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || data.success === false) {
                            $('posts_status').insertAdjacentHTML('afterbegin',
                                '<div class="notice notice-error"><p>' + esc(data && data.message ? data.message : 'Error desconocido') + '</p></div>');
                            btn.disabled = false;
                            return;
                        }
                        (data.post_previews || []).forEach(function (p) {
                            $('posts_status').insertAdjacentHTML('beforeend', renderPreview(p));
                        });
                        setProgress(offset + (data.exported_count || 0));

                        if (data.next_offset !== null && typeof data.next_offset !== 'undefined') {
                            runBatch(data.next_offset, btn);
                        } else {
                            $('posts_status').insertAdjacentHTML('beforeend',
                                '<div class="notice notice-success"><p>✅ Exportación de posts finalizada.</p></div>');
                            btn.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        $('posts_status').insertAdjacentHTML('afterbegin',
                            '<div class="notice notice-error"><p>Error de red: ' + esc(err && err.message) + '</p></div>');
                        btn.disabled = false;
                    });
            }

            $('posts_start').addEventListener('click', function () {
                if (!$('posts_api_key').value.trim() || !$('posts_api_url').value.trim()) {
                    alert('Ingresá API Key y API URL.');
                    return;
                }
                saveCfg();
                this.disabled = true;
                $('posts_status').innerHTML = '';
                $('posts_progress_wrap').style.display = 'block';
                setProgress(0);
                runBatch(0, this);
            });
        })();
        </script>
        <?php
    }
}
