<?php
/**
 * Motor de Bloqueo Previo de Scripts e Iframes de Terceros (Prior Consent Blocking)
 * Impide la ejecución de rastreadores analíticos y de marketing antes del consentimiento expreso (Art. 12 Ley N° 21.719 y RGPD)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Blocker
{
    public static function init()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST')) {
            return;
        }

        // No bloquear durante el escaneo interno del propio plugin ni en el editor visual de Elementor
        if (!empty($_GET['rpc_scanner_bypass']) || !empty($_GET['elementor-preview'])) {
            return;
        }

        $settings = Rotar_Privacidad_Chile::get_settings();
        if (empty($settings['enabled']) || empty($settings['prior_blocking'])) {
            return;
        }

        // 1. Filtrar etiquetas <script> encoladas vía wp_enqueue_script
        add_filter('script_loader_tag', [__CLASS__, 'filter_enqueued_script_tag'], 999, 3);

        // 2. Excluir nuestros propios scripts de minificación/combinación agresiva de LiteSpeed Cache / Autoptimize / Rocket
        add_filter('litespeed_optimize_js_excludes', [__CLASS__, 'exclude_cmp_from_optimizers']);
        add_filter('rocket_exclude_js', [__CLASS__, 'exclude_cmp_from_optimizers']);
        add_filter(' rocket_delay_js_exclusions', [__CLASS__, 'exclude_cmp_from_optimizers']);

        // 3. Buffer de salida HTML para interceptar scripts inline (fbq, clarity, tikTok, PixelYourSite) e iframes (YouTube, Google Maps)
        add_action('template_redirect', [__CLASS__, 'start_html_buffer'], 1);
    }

    public static function exclude_cmp_from_optimizers($excludes)
    {
        if (!is_array($excludes)) {
            $excludes = [];
        }
        $excludes[] = 'rpc-frontend.js';
        $excludes[] = 'rpcConfig';
        $excludes[] = 'rpc_consent_init';
        return $excludes;
    }

    /**
     * Detecta si estamos en pasarela de pago / retorno Transbank Webpay para no alterar jamás el flujo transaccional
     */
    private static function is_transactional_payment_endpoint()
    {
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
            return true;
        }
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, 'wc-api=') !== false || strpos($uri, 'webpay') !== false || strpos($uri, 'transbank') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Lee la cookie de consentimiento actual del visitante en PHP (si ya consintió previamente)
     */
    public static function get_visitor_consent_cookie()
    {
        if (empty($_COOKIE['rpc_consent_ley21719'])) {
            return null;
        }

        $raw = sanitize_text_field(wp_unslash($_COOKIE['rpc_consent_ley21719']));
        $decoded = json_decode(rawurldecode($raw), true);
        if (!is_array($decoded)) {
            $decoded = json_decode($raw, true);
        }

        if (!is_array($decoded) || empty($decoded['version'])) {
            return null;
        }

        $current_ver = (string) Rotar_Privacidad_Chile::get_setting('consent_version', '1.0');
        if ((string) $decoded['version'] !== $current_ver) {
            return null;
        }

        return [
            'necessary' => true,
            'preferences' => !empty($decoded['preferences']),
            'analytics' => !empty($decoded['analytics']),
            'marketing' => !empty($decoded['marketing']),
        ];
    }

    /**
     * Reglas de coincidencia de scripts de terceros y categoría requerida
     * Nota: Cuando Google Consent Mode v2 está activo, gtag/js y gtm.js pueden cargar en modo restringido ('denied')
     * para modelado sin cookies si se desea, o bloquearse. Aquí bloqueamos estrictamente todos los píxeles invasivos
     * y gestionamos scripts analíticos/publicitarios con desbloqueo instantáneo al aceptar.
     */
    public static function get_script_blocking_rules()
    {
        $gcm_enabled = (bool) Rotar_Privacidad_Chile::get_setting('gcm_v2_enabled', 1);

        $rules = [
            // Marketing / Publicidad Comportamental
            'connect.facebook.net' => 'marketing',
            'fbevents.js' => 'marketing',
            'fbq(' => 'marketing',
            'facebook-for-woocommerce' => 'marketing',
            'pixelyoursite' => 'marketing',
            'analytics.tiktok.com' => 'marketing',
            'ttq.load' => 'marketing',
            'snap.licdn.com' => 'marketing',
            'ads-twitter.com' => 'marketing',
            'static.ads-twitter.com' => 'marketing',
            'bat.bing.com' => 'marketing',
            'analytics.clientify' => 'marketing',
            'analyticsplusdev.clientify' => 'marketing',
            'plus.clientify.com/api/whatsapp-attribution' => 'marketing',
            'wa-attribution.js' => 'marketing',
            'googleadservices.com' => 'marketing',
            'doubleclick.net' => 'marketing',

            // Analítica / Mapas de calor
            'google-analytics.com/analytics.js' => 'analytics',
            'clarity.ms' => 'analytics',
            'static.hotjar.com' => 'analytics',
            'plausible.io' => 'analytics',
            'matomo.js' => 'analytics',
        ];

        // Si Google Consent Mode v2 está desactivado, bloquear también gtag.js y gtm.js hasta el opt-in.
        // Si está activado, Google Tag Manager y gtag respetan nativamente la señal 'denied' enviada en <head> prioridad 0.
        if (!$gcm_enabled) {
            $rules['googletagmanager.com/gtag/js'] = 'analytics';
            $rules['googletagmanager.com/gtm.js'] = 'analytics';
        }

        return apply_filters('rpc_ley21719_script_blocking_rules', $rules);
    }

    /**
     * Determina si un fragmento o URL de script debe ser bloqueado según la categoría aún no consentida
     */
    public static function detect_blocked_category($script_string, $consent_state)
    {
        // Nunca bloquear scripts propios del CMP, Chat de Atención en Vivo (Clientify LiveChat / WhatsApp), jQuery, WooCommerce checkout o seguridad (Turnstile/Wordfence)
        $whitelist = [
            'rotar-privacidad-cookies-chile',
            'rpc-consent',
            'rpc-frontend',
            'apps.clientify.net/livechat',
            'livechat2/app/livechat.js',
            'rotar-whatsapp',
            'jquery',
            'wc-checkout',
            'wc-cart',
            'woocommerce',
            'transbank',
            'webpay',
            'challenges.cloudflare.com/turnstile',
            'wordfence',
        ];

        $lower = strtolower($script_string);
        foreach ($whitelist as $safe) {
            if (strpos($lower, $safe) !== false) {
                return null;
            }
        }

        $rules = self::get_script_blocking_rules();
        foreach ($rules as $needle => $category) {
            if (strpos($lower, strtolower($needle)) !== false) {
                // Si el visitante ya otorgó consentimiento para esta categoría, no bloquear
                if (is_array($consent_state) && !empty($consent_state[$category])) {
                    return null;
                }
                return $category;
            }
        }

        return null;
    }

    public static function filter_enqueued_script_tag($tag, $handle, $src)
    {
        // Proteger script propio del CMP frente a optimizadores JS de caché
        if ($handle === 'rpc-frontend-js') {
            if (stripos($tag, 'data-cfasync') === false) {
                $tag = preg_replace('/^<script\b/i', '<script data-cfasync="false" data-no-optimize="1" data-pagespeed-no-defer', $tag, 1);
            }
            return $tag;
        }

        if (self::is_transactional_payment_endpoint()) {
            return $tag;
        }

        // En sitios con caché de página completa (LiteSpeed / Cloudflare), transformamos de forma consistente en HTML
        // y rpc-frontend.js los activa instantáneamente en el cliente si el usuario ya consintió, evitando fugas de caché entre usuarios.
        $category = self::detect_blocked_category($handle . ' ' . $src . ' ' . $tag, null);

        if (!$category) {
            return $tag;
        }

        return self::transform_script_tag($tag, $category);
    }

    public static function start_html_buffer()
    {
        if (self::is_transactional_payment_endpoint()) {
            return;
        }
        ob_start([__CLASS__, 'process_html_buffer']);
    }

    public static function process_html_buffer($html)
    {
        if (empty($html) || stripos($html, '<html') === false) {
            return $html;
        }

        // 1. Interceptar etiquetas <script> inline o directas en plantilla (consistente para LiteSpeed Cache)
        $html = preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            function ($matches) {
                $attrs = $matches[1];
                $body = $matches[2];
                $full = $matches[0];

                // Si ya fue procesado o es JSON-LD / especulación / plantilla, ignorar
                if (
                    stripos($attrs, 'data-rpc-category') !== false ||
                    stripos($attrs, 'application/ld+json') !== false ||
                    stripos($attrs, 'speculationrules') !== false ||
                    stripos($attrs, 'text/template') !== false
                ) {
                    return $full;
                }

                $category = self::detect_blocked_category($attrs . ' ' . substr($body, 0, 1200), null);
                if (!$category) {
                    return $full;
                }

                return self::transform_script_tag($full, $category);
            },
            $html
        );

        // 2. Interceptar iframes de terceros (YouTube, Vimeo, Google Maps) y mostrar placeholder elegante con desbloqueo en 1 clic
        if (Rotar_Privacidad_Chile::get_setting('block_iframes', 1)) {
            $html = preg_replace_callback(
                '/<iframe\b([^>]*)>\s*<\/iframe>/is',
                function ($matches) {
                    $attrs = $matches[1];
                    $full = $matches[0];

                    if (stripos($attrs, 'data-rpc-iframe-src') !== false) {
                        return $full;
                    }

                    if (!preg_match('/\bsrc=(["\'])(.*?)\1/i', $attrs, $src_match)) {
                        return $full;
                    }

                    $src = $src_match[2];
                    $lower_src = strtolower($src);
                    $iframe_cat = null;
                    $provider_name = 'Contenido externo';

                    if (strpos($lower_src, 'youtube.com/embed') !== false || strpos($lower_src, 'youtu.be') !== false || strpos($lower_src, 'vimeo.com') !== false) {
                        $iframe_cat = 'marketing';
                        $provider_name = 'YouTube / Video externo';
                    } elseif (strpos($lower_src, 'google.com/maps') !== false || strpos($lower_src, 'maps.google') !== false) {
                        $iframe_cat = 'preferences';
                        $provider_name = 'Google Maps';
                    }

                    if (!$iframe_cat) {
                        return $full;
                    }

                    // Reemplazar src por about:blank y guardar src real en data-rpc-iframe-src
                    $modified_iframe = preg_replace(
                        '/\bsrc=(["\'])(.*?)\1/i',
                        'src="about:blank" data-rpc-iframe-src="$2" data-rpc-category="' . esc_attr($iframe_cat) . '"',
                        $full,
                        1
                    );

                    $cat_label = $iframe_cat === 'marketing' ? 'Marketing y Multimedia' : 'Preferencias y Funcionalidad';

                    $placeholder = '<div class="rpc-iframe-wrapper" data-rpc-wrapper-cat="' . esc_attr($iframe_cat) . '">'
                        . $modified_iframe
                        . '<div class="rpc-iframe-placeholder">'
                        . '<div class="rpc-iframe-card">'
                        . '<span class="rpc-iframe-icon">🔒</span>'
                        . '<strong>' . esc_html($provider_name) . ' bloqueado por privacidad</strong>'
                        . '<p>Este contenido externo puede instalar cookies de terceros (' . esc_html($cat_label) . '). Conforme a la Ley N° 21.719, solicitamos tu autorización antes de cargarlo.</p>'
                        . '<button type="button" class="rpc-btn-unlock-iframe" data-rpc-accept-cat="' . esc_attr($iframe_cat) . '">Permitir y cargar contenido</button>'
                        . '</div>'
                        . '</div>'
                        . '</div>';

                    return $placeholder;
                },
                $html
            );
        }

        return $html;
    }

    /**
     * Convierte <script ...> en <script type="text/plain" data-rpc-category="..."> para impedir ejecución previa
     */
    public static function transform_script_tag($tag, $category)
    {
        if (preg_match('/\btype=(["\'])(.*?)\1/i', $tag)) {
            $tag = preg_replace('/\btype=(["\'])(.*?)\1/i', 'type="text/plain" data-rpc-orig-type="$2"', $tag, 1);
        } else {
            $tag = preg_replace('/^<script\b/i', '<script type="text/plain"', $tag, 1);
        }

        // Si tiene atributo src, respaldarlo en data-rpc-src para evitar precarga (preload scanner del navegador)
        if (preg_match('/\bsrc=(["\'])(.*?)\1/i', $tag)) {
            $tag = preg_replace('/\bsrc=(["\'])(.*?)\1/i', 'data-rpc-src="$2"', $tag, 1);
        }

        $tag = preg_replace('/^<script\b/i', '<script data-rpc-category="' . esc_attr($category) . '"', $tag, 1);
        return $tag;
    }
}

