<?php
/**
 * Motor de Escaneo Automático de Cookies, Scripts y Servicios de Terceros
 * Analiza plugins activos, opciones de base de datos, scripts encolados y el HTML real del frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Scanner
{
    public static function init()
    {
        add_action('wp_ajax_rpc_run_scan', [__CLASS__, 'ajax_run_scan']);
        add_action('wp_ajax_rpc_save_cookie_item', [__CLASS__, 'ajax_save_cookie_item']);
        add_action('wp_ajax_rpc_delete_cookie_item', [__CLASS__, 'ajax_delete_cookie_item']);
    }

    /**
     * Base de conocimiento de cookies, scripts y proveedores conocidos (incluyendo ecosistema chileno y e-commerce)
     */
    public static function get_signatures_catalog()
    {
        return [
            'wordpress_core' => [
                'service' => 'WordPress Core',
                'provider' => 'Propio (Este sitio web)',
                'category' => 'necessary',
                'patterns' => ['wp-includes', 'wp-emoji'],
                'cookies' => [
                    [
                        'name' => 'wordpress_test_cookie',
                        'duration' => 'Sesión',
                        'purpose' => 'Verifica si el navegador del visitante admite cookies técnicas esenciales.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => 'wp-settings-*',
                        'duration' => '1 año',
                        'purpose' => 'Personaliza la interfaz y preferencias técnicas de usuarios autenticados.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'rotar_cmp' => [
                'service' => 'Rotar Privacidad & Consentimiento (Ley 21.719)',
                'provider' => 'Propio (Este sitio web)',
                'category' => 'necessary',
                'always' => true,
                'patterns' => ['rotar-privacidad'],
                'cookies' => [
                    [
                        'name' => 'rpc_consent_ley21719',
                        'duration' => '6 meses',
                        'purpose' => 'Almacena el identificador único (UUID) y el estado de las preferencias de privacidad otorgadas por el usuario conforme a la Ley N° 21.719.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'woocommerce' => [
                'service' => 'WooCommerce (Tienda Online)',
                'provider' => 'Propio / Automattic',
                'category' => 'necessary',
                'plugin_slug' => 'woocommerce/',
                'patterns' => ['woocommerce', 'wc-cart-fragments'],
                'cookies' => [
                    [
                        'name' => 'woocommerce_cart_hash',
                        'duration' => 'Sesión',
                        'purpose' => 'Ayuda a WooCommerce a determinar cuándo cambia el contenido o los datos del carro de compras.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => 'woocommerce_items_in_cart',
                        'duration' => 'Sesión',
                        'purpose' => 'Registra los productos agregados al carrito durante la sesión de compra.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => 'wp_woocommerce_session_*',
                        'duration' => '2 días',
                        'purpose' => 'Contiene un código único para cada cliente que permite vincular su carrito en la base de datos.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'transbank_webpay' => [
                'service' => 'Transbank Webpay Plus',
                'provider' => 'Transbank S.A. (Chile)',
                'category' => 'necessary',
                'plugin_slug' => 'transbank-webpay-plus-rest/',
                'patterns' => ['webpay', 'transbank'],
                'cookies' => [
                    [
                        'name' => 'tbk_session / webpay_token',
                        'duration' => 'Sesión',
                        'purpose' => 'Garantiza la seguridad transaccional y validación del pago electrónico mediante Webpay Plus.',
                        'type' => 'HTTP / Token',
                    ],
                ],
            ],
            'cloudflare_turnstile' => [
                'service' => 'Cloudflare Turnstile (Seguridad Anti-Bots)',
                'provider' => 'Cloudflare, Inc.',
                'category' => 'necessary',
                'plugin_slug' => 'simple-cloudflare-turnstile/',
                'patterns' => ['challenges.cloudflare.com/turnstile', 'cfturnstile'],
                'cookies' => [
                    [
                        'name' => 'cf_clearance / __cf_bm',
                        'duration' => '30 minutos',
                        'purpose' => 'Protege formularios e inicios de sesión contra ataques automatizados y spam sin rastreo publicitario.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'wordfence' => [
                'service' => 'Wordfence Security Firewall',
                'provider' => 'Defiant, Inc.',
                'category' => 'necessary',
                'plugin_slug' => 'wordfence/',
                'patterns' => ['wordfence'],
                'cookies' => [
                    [
                        'name' => 'wfwaf-authcookie-*',
                        'duration' => '12 horas',
                        'purpose' => 'Verifica permisos de seguridad del firewall de aplicaciones web (WAF) para prevenir accesos maliciosos.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'litespeed_cache' => [
                'service' => 'LiteSpeed Cache',
                'provider' => 'Propio (Servidor Web)',
                'category' => 'necessary',
                'plugin_slug' => 'litespeed-cache/',
                'patterns' => ['litespeed'],
                'cookies' => [
                    [
                        'name' => '_lscache_vary',
                        'duration' => '2 días',
                        'purpose' => 'Gestiona la entrega optimizada de páginas en caché diferenciando sesiones de compra.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'elementor' => [
                'service' => 'Elementor Page Builder',
                'provider' => 'Propio (Este sitio web)',
                'category' => 'preferences',
                'plugin_slug' => 'elementor/',
                'patterns' => ['elementor-frontend', 'elementor/assets'],
                'cookies' => [
                    [
                        'name' => 'elementor',
                        'duration' => 'Persistente',
                        'purpose' => 'Almacena preferencias de visualización de popups y elementos interactivos en el navegador.',
                        'type' => 'LocalStorage',
                    ],
                ],
            ],
            'google_tag_manager' => [
                'service' => 'Google Tag Manager (GTM)',
                'provider' => 'Google Ireland Limited / Google LLC',
                'category' => 'analytics',
                'plugin_slug' => 'duracelltomi-google-tag-manager/',
                'patterns' => ['googletagmanager.com/gtm.js', 'gtm4wp'],
                'cookies' => [
                    [
                        'name' => '_dc_gtm_*',
                        'duration' => '1 minuto',
                        'purpose' => 'Controla la carga de etiquetas de medición y scripts gestionados a través de Google Tag Manager.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'google_analytics' => [
                'service' => 'Google Analytics 4 (GA4)',
                'provider' => 'Google LLC',
                'category' => 'analytics',
                'patterns' => ['googletagmanager.com/gtag/js', 'google-analytics.com', 'G-'],
                'cookies' => [
                    [
                        'name' => '_ga',
                        'duration' => '2 años',
                        'purpose' => 'Registra un ID único para generar datos estadísticos agregados sobre cómo utiliza el visitante el sitio web.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => '_ga_*',
                        'duration' => '2 años',
                        'purpose' => 'Utilizada por Google Analytics 4 para conservar y contabilizar el estado de la sesión y vistas de página.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'google_ads' => [
                'service' => 'Google Ads & Conversion Tracking',
                'provider' => 'Google LLC',
                'category' => 'marketing',
                'patterns' => ['googleadservices.com', 'googlesyndication.com', 'doubleclick.net', 'AW-'],
                'cookies' => [
                    [
                        'name' => '_gcl_au',
                        'duration' => '3 meses',
                        'purpose' => 'Utilizada por Google AdSense/Ads para medir la eficiencia publicitaria y atribuciones de conversión en la web.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => 'IDE / test_cookie',
                        'duration' => '1 año',
                        'purpose' => 'Registra e informa las acciones del usuario tras ver o hacer clic en anuncios de Google con fines de remarketing.',
                        'type' => 'HTTP Cookie (3rd Party)',
                    ],
                ],
            ],
            'meta_pixel' => [
                'service' => 'Meta Pixel (Facebook & Instagram Ads)',
                'provider' => 'Meta Platforms, Inc.',
                'category' => 'marketing',
                'plugin_slug' => 'facebook-for-woocommerce/',
                'alt_slug' => 'pixelyoursite/',
                'patterns' => ['connect.facebook.net', 'fbevents.js', 'fbq('],
                'cookies' => [
                    [
                        'name' => '_fbp',
                        'duration' => '3 meses',
                        'purpose' => 'Utilizada por Meta (Facebook/Instagram) para ofrecer productos publicitarios en tiempo real y medir conversiones.',
                        'type' => 'HTTP Cookie',
                    ],
                    [
                        'name' => '_fbc',
                        'duration' => '3 meses',
                        'purpose' => 'Almacena el identificador de clic (fbclid) cuando un usuario llega desde un anuncio en redes de Meta.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'clientify_livechat' => [
                'service' => 'Clientify LiveChat (Atención al Cliente en Vivo)',
                'provider' => 'Clientify S.L.',
                'category' => 'necessary',
                'patterns' => ['apps.clientify.net/livechat', 'livechat.js'],
                'cookies' => [
                    [
                        'name' => 'clientify_livechat_session',
                        'duration' => 'Sesión',
                        'purpose' => 'Permite el funcionamiento técnico del chat de soporte y atención comercial en tiempo real solicitado por el usuario.',
                        'type' => 'LocalStorage / Session',
                    ],
                ],
            ],
            'clientify_crm' => [
                'service' => 'Clientify CRM & Marketing Attribution',
                'provider' => 'Clientify S.L.',
                'category' => 'marketing',
                'plugin_slug' => 'clientify-addons-wc/',
                'patterns' => ['analytics.clientify', 'analyticsplusdev.clientify', 'wa-attribution.js', 'vk_'],
                'cookies' => [
                    [
                        'name' => 'vk_* / clientify_session',
                        'duration' => '1 año',
                        'purpose' => 'Realiza seguimiento comercial de leads, carritos y atribución de campañas conectadas a Clientify CRM.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'rotar_whatsapp_analytics' => [
                'service' => 'Rotar WhatsApp Analytics & Attribution',
                'provider' => 'Propio (Este sitio web)',
                'category' => 'analytics',
                'plugin_slug' => 'rotar-whatsapp-button/',
                'patterns' => ['rotar-whatsapp', 'rotar_wa'],
                'cookies' => [
                    [
                        'name' => 'rotar_wa_utm / session',
                        'duration' => '30 días',
                        'purpose' => 'Mide el origen de tráfico (UTM/campaña) al iniciar una consulta comercial por WhatsApp.',
                        'type' => 'LocalStorage / Session',
                    ],
                ],
            ],
            'youtube_embed' => [
                'service' => 'YouTube Video Embeds',
                'provider' => 'Google LLC (YouTube)',
                'category' => 'marketing',
                'patterns' => ['youtube.com/embed', 'youtu.be', 'youtube-nocookie.com'],
                'cookies' => [
                    [
                        'name' => 'VISITOR_INFO1_LIVE / YSC',
                        'duration' => '6 meses / Sesión',
                        'purpose' => 'Estima el ancho de banda del usuario y registra interacciones en videos incrustados de YouTube.',
                        'type' => 'HTTP Cookie (3rd Party)',
                    ],
                ],
            ],
            'google_maps' => [
                'service' => 'Google Maps Embed / API',
                'provider' => 'Google LLC',
                'category' => 'preferences',
                'patterns' => ['maps.googleapis.com', 'google.com/maps/embed'],
                'cookies' => [
                    [
                        'name' => 'NID',
                        'duration' => '6 meses',
                        'purpose' => 'Carga mapas interactivos de ubicación de sucursales y guarda preferencias de zoom e idioma.',
                        'type' => 'HTTP Cookie (3rd Party)',
                    ],
                ],
            ],
            'google_reviews' => [
                'service' => 'Widget de Reseñas de Google (Trustindex)',
                'provider' => 'Trustindex / Google',
                'category' => 'preferences',
                'plugin_slug' => 'wp-reviews-plugin-for-google/',
                'patterns' => ['trustindex.io', 'wp-reviews-plugin-for-google'],
                'cookies' => [
                    [
                        'name' => 'ti_widget_prefs',
                        'duration' => 'Sesión',
                        'purpose' => 'Permite visualizar reseñas verificadas de clientes de Google en el sitio web.',
                        'type' => 'LocalStorage',
                    ],
                ],
            ],
            'tiktok_pixel' => [
                'service' => 'TikTok Pixel',
                'provider' => 'TikTok Inc.',
                'category' => 'marketing',
                'patterns' => ['analytics.tiktok.com', 'ttq.load'],
                'cookies' => [
                    [
                        'name' => '_ttp',
                        'duration' => '13 meses',
                        'purpose' => 'Mide y optimiza el rendimiento de campañas publicitarias en TikTok.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
            'clarity_hotjar' => [
                'service' => 'Mapas de Calor (Microsoft Clarity / Hotjar)',
                'provider' => 'Microsoft Corp. / Hotjar Ltd.',
                'category' => 'analytics',
                'patterns' => ['clarity.ms', 'static.hotjar.com'],
                'cookies' => [
                    [
                        'name' => '_clck / _hjSessionUser_*',
                        'duration' => '1 año',
                        'purpose' => 'Analiza el comportamiento de navegación mediante mapas de calor anónimos para mejorar la usabilidad.',
                        'type' => 'HTTP Cookie',
                    ],
                ],
            ],
        ];
    }

    /**
     * Ejecuta un escaneo integral: Plugins activos + Opciones de BD + Contenido de páginas/plantillas Elementor + Petición HTTP al Frontend
     */
    public static function run_full_scan()
    {
        global $wpdb;
        $catalog = self::get_signatures_catalog();
        $active_plugins = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $network_plugins = array_keys((array) get_site_option('active_sitewide_plugins', []));
            $active_plugins = array_merge($active_plugins, $network_plugins);
        }
        $active_plugins_str = strtolower(implode(' | ', $active_plugins));

        // Recopilar fragmentos de HTML/opciones para búsqueda profunda (Elementor, Custom CSS/JS, Widgets, Rotar WA)
        $corpus = '';

        // 1. Petición HTTP interna a la portada y catálogo para capturar scripts reales e incluso cabeceras Set-Cookie
        $urls_to_probe = [home_url('/')];
        $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : '';
        if (!empty($shop_url) && $shop_url !== home_url('/')) {
            $urls_to_probe[] = $shop_url;
        }

        $http_cookies_found = [];
        foreach ($urls_to_probe as $probe_url) {
            $resp = wp_remote_get(add_query_arg('rpc_scanner_bypass', '1', $probe_url), [
                'timeout' => 5,
                'sslverify' => false,
                'headers' => ['User-Agent' => 'RotarPrivacyScanner/1.0'],
            ]);
            if (!is_wp_error($resp)) {
                $corpus .= "\n" . wp_remote_retrieve_body($resp);
                $cookies_header = wp_remote_retrieve_cookies($resp);
                if (!empty($cookies_header)) {
                    foreach ($cookies_header as $c) {
                        if (!empty($c->name)) {
                            $http_cookies_found[$c->name] = $c;
                        }
                    }
                }
            }
        }

        // 2. Inspeccionar opciones clave de rastreo (GTM4WP, PixelYourSite, Rotar WA, Custom CSS/JS)
        $rotar_wa = get_option('rotar_wa_settings', []);
        if (is_array($rotar_wa)) {
            $corpus .= ' ' . wp_json_encode($rotar_wa);
        }
        $gtm_opts = get_option('gtm4wp-options', []);
        if (is_array($gtm_opts)) {
            $corpus .= ' ' . wp_json_encode($gtm_opts);
        }
        $pys_opts = get_option('pys_core', []);
        if (is_array($pys_opts)) {
            $corpus .= ' ' . wp_json_encode($pys_opts);
        }

        // 3. Inspeccionar últimos posts/páginas y custom-css-js publicados (para detectar iframes de YouTube, Maps, scripts inline)
        $posts_sample = $wpdb->get_col("SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','custom-css-js') LIMIT 40");
        if (!empty($posts_sample)) {
            $corpus .= "\n" . implode("\n", $posts_sample);
        }
        $elementor_sample = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' LIMIT 25");
        if (!empty($elementor_sample)) {
            $corpus .= "\n" . implode("\n", $elementor_sample);
        }

        $corpus_lower = strtolower($corpus);

        // Conservar cookies agregadas manualmente por el administrador
        $existing = self::get_detected_cookies();
        $manual_items = [];
        foreach ($existing['items'] ?? [] as $item) {
            if (!empty($item['is_manual'])) {
                $manual_items[$item['id']] = $item;
            }
        }

        $detected_items = [];
        $detected_services = [];

        // Siempre incluir WordPress Core y el propio CMP
        foreach ($catalog as $key => $sig) {
            $matched = !empty($sig['always']) || $key === 'wordpress_core';
            $source_reason = 'Núcleo del sistema';

            if (!$matched && !empty($sig['plugin_slug']) && strpos($active_plugins_str, strtolower($sig['plugin_slug'])) !== false) {
                $matched = true;
                $source_reason = 'Plugin activo detectado';
            }
            if (!$matched && !empty($sig['alt_slug']) && strpos($active_plugins_str, strtolower($sig['alt_slug'])) !== false) {
                $matched = true;
                $source_reason = 'Plugin activo detectado';
            }
            if (!$matched && !empty($sig['patterns'])) {
                foreach ($sig['patterns'] as $pat) {
                    if ($pat !== '' && strpos($corpus_lower, strtolower($pat)) !== false) {
                        $matched = true;
                        $source_reason = 'Script / HTML detectado (' . $pat . ')';
                        break;
                    }
                }
            }

            if ($matched) {
                $detected_services[] = [
                    'key' => $key,
                    'service' => $sig['service'],
                    'provider' => $sig['provider'],
                    'category' => $sig['category'],
                    'source' => $source_reason,
                ];

                foreach ($sig['cookies'] as $cookie) {
                    $id = sanitize_key($key . '_' . $cookie['name']);
                    $detected_items[$id] = [
                        'id' => $id,
                        'name' => $cookie['name'],
                        'service' => $sig['service'],
                        'provider' => $sig['provider'],
                        'category' => $sig['category'],
                        'duration' => $cookie['duration'],
                        'purpose' => $cookie['purpose'],
                        'type' => $cookie['type'],
                        'source' => $source_reason,
                        'is_manual' => false,
                    ];
                }
            }
        }

        // Agregar cualquier cookie HTTP en vivo capturada en Set-Cookie que no esté ya mapeada
        foreach ($http_cookies_found as $c_name => $cookie_obj) {
            $already = false;
            foreach ($detected_items as $item) {
                $pattern_clean = str_replace('*', '', $item['name']);
                if ($item['name'] === $c_name || ($pattern_clean !== '' && strpos($c_name, $pattern_clean) === 0)) {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $id = 'live_' . sanitize_key($c_name);
                $detected_items[$id] = [
                    'id' => $id,
                    'name' => sanitize_text_field($c_name),
                    'service' => 'Cookie detectada en servidor',
                    'provider' => parse_url(home_url(), PHP_URL_HOST) ?: 'Propio',
                    'category' => 'necessary',
                    'duration' => !empty($cookie_obj->expires) ? 'Persistente' : 'Sesión',
                    'purpose' => 'Cookie técnica enviada en la respuesta HTTP del sitio web.',
                    'type' => 'HTTP Cookie',
                    'source' => 'Cabecera HTTP Set-Cookie en vivo',
                    'is_manual' => false,
                ];
            }
        }

        // Reintegrar cookies manuales personalizadas
        foreach ($manual_items as $id => $m_item) {
            $detected_items[$id] = $m_item;
        }

        $scan_result = [
            'last_scan' => current_time('mysql'),
            'total_cookies' => count($detected_items),
            'total_services' => count($detected_services),
            'services' => array_values($detected_services),
            'items' => array_values($detected_items),
        ];

        update_option(RPC_OPT_COOKIES, $scan_result, false);
        return $scan_result;
    }

    public static function run_automated_scan()
    {
        return self::run_full_scan();
    }

    /**
     * Obtiene el inventario actual de cookies (ejecuta escaneo inicial si está vacío)
     */
    public static function get_detected_cookies()
    {
        $data = get_option(RPC_OPT_COOKIES, []);
        if (empty($data) || empty($data['items'])) {
            return [
                'last_scan' => 'Pendiente de primer escaneo',
                'total_cookies' => 0,
                'total_services' => 0,
                'services' => [],
                'items' => [],
            ];
        }
        return $data;
    }

    /**
     * Agrupa cookies por categoría ('necessary', 'preferences', 'analytics', 'marketing')
     */
    public static function get_cookies_by_category()
    {
        $scan = self::get_detected_cookies();
        if (empty($scan['items'])) {
            $scan = self::run_full_scan();
        }

        $grouped = [
            'necessary' => [],
            'preferences' => [],
            'analytics' => [],
            'marketing' => [],
        ];

        foreach ($scan['items'] as $item) {
            $cat = $item['category'] ?? 'necessary';
            if (!isset($grouped[$cat])) {
                $cat = 'necessary';
            }
            $grouped[$cat][] = $item;
        }

        return $grouped;
    }

    public static function ajax_run_scan()
    {
        check_ajax_referer('rpc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
        }

        $result = self::run_full_scan();
        wp_send_json_success($result);
    }

    public static function ajax_save_cookie_item()
    {
        check_ajax_referer('rpc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
        }

        $scan = self::get_detected_cookies();
        $items = $scan['items'] ?? [];

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $service = sanitize_text_field(wp_unslash($_POST['service'] ?? ''));
        $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'Propio'));
        $category = sanitize_key($_POST['category'] ?? 'necessary');
        $duration = sanitize_text_field(wp_unslash($_POST['duration'] ?? 'Sesión'));
        $purpose = sanitize_textarea_field(wp_unslash($_POST['purpose'] ?? ''));

        if ($name === '') {
            wp_send_json_error(['message' => 'El nombre de la cookie es obligatorio.']);
        }

        $id = !empty($_POST['id']) ? sanitize_key($_POST['id']) : 'manual_' . sanitize_key($name) . '_' . wp_rand(100, 999);
        $new_item = [
            'id' => $id,
            'name' => $name,
            'service' => $service ?: 'Servicio Personalizado',
            'provider' => $provider,
            'category' => in_array($category, ['necessary', 'preferences', 'analytics', 'marketing'], true) ? $category : 'necessary',
            'duration' => $duration,
            'purpose' => $purpose,
            'type' => 'HTTP Cookie',
            'source' => 'Agregada manualmente',
            'is_manual' => true,
        ];

        $replaced = false;
        foreach ($items as $idx => $existing) {
            if ($existing['id'] === $id) {
                $items[$idx] = $new_item;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $items[] = $new_item;
        }

        $scan['items'] = array_values($items);
        $scan['total_cookies'] = count($scan['items']);
        update_option(RPC_OPT_COOKIES, $scan, false);

        wp_send_json_success($scan);
    }

    public static function ajax_delete_cookie_item()
    {
        check_ajax_referer('rpc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
        }

        $id = sanitize_key($_POST['id'] ?? '');
        $scan = self::get_detected_cookies();
        $items = array_filter($scan['items'] ?? [], function ($item) use ($id) {
            return ($item['id'] ?? '') !== $id;
        });

        $scan['items'] = array_values($items);
        $scan['total_cookies'] = count($scan['items']);
        update_option(RPC_OPT_COOKIES, $scan, false);

        wp_send_json_success($scan);
    }
}

