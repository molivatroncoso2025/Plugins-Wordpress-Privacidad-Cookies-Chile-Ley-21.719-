<?php
/**
 * Módulo de Geolocalización por IP y Determinación de Normativa Aplicable
 * Adapta automáticamente el aviso y el régimen de consentimiento (Opt-in vs Opt-out) según el país del visitante.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_GeoIP
{
    // Países EEE / Unión Europea + Reino Unido y Suiza (RGPD / UK GDPR)
    const GDPR_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE',
        'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO', 'GB', 'CH'
    ];

    public static function init()
    {
        add_action('wp_ajax_rpc_detect_geo', [__CLASS__, 'ajax_detect_geo']);
        add_action('wp_ajax_nopriv_rpc_detect_geo', [__CLASS__, 'ajax_detect_geo']);
    }

    /**
     * Obtiene la IP real del visitante respetando cabeceras de proxy / Cloudflare / LiteSpeed
     */
    public static function get_client_ip()
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $ip = trim($ip_list[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Detecta el código ISO-3166-1 alpha-2 del visitante sin romper cachés de página completa
     */
    public static function detect_country_code($allow_remote_api = true)
    {
        // 1. Cabeceras nativas de CDN / Servidor (Cloudflare, Hostinger, LiteSpeed, AWS, Vercel)
        $cdn_headers = [
            'HTTP_CF_IPCOUNTRY',
            'GEOIP_COUNTRY_CODE',
            'HTTP_X_COUNTRY_CODE',
            'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
        ];

        foreach ($cdn_headers as $h) {
            if (!empty($_SERVER[$h])) {
                $code = strtoupper(trim(sanitize_text_field(wp_unslash($_SERVER[$h]))));
                if (strlen($code) === 2 && $code !== 'XX' && $code !== 'T1') {
                    return $code;
                }
            }
        }

        // 2. Geolocalización integrada de WooCommerce (MaxMind / GeoIP nativa si está activa)
        if (class_exists('WC_Geolocation')) {
            $wc_geo = WC_Geolocation::geolocate_ip('', true, false);
            if (!empty($wc_geo['country']) && strlen($wc_geo['country']) === 2) {
                return strtoupper($wc_geo['country']);
            }
        }

        $ip = self::get_client_ip();

        // Entorno local (localhost / IPs privadas) -> por defecto Chile ('CL') dado que el sitio opera en Chile
        if ($ip === '127.0.0.1' || $ip === '::1' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'CL';
        }

        // 3. Caché en Transient por hash de IP para evitar consultas repetidas
        $cache_key = 'rpc_geo_' . substr(md5($ip), 0, 16);
        $cached = get_transient($cache_key);
        if (!empty($cached) && strlen($cached) === 2) {
            return $cached;
        }

        // 4. Fallback opcional vía API ligera con timeout corto
        if ($allow_remote_api && Rotar_Privacidad_Chile::get_setting('geo_api_fallback', 1)) {
            $response = wp_remote_get('https://ipapi.co/' . rawurlencode($ip) . '/country/', [
                'timeout'   => 1.8,
                'sslverify' => true,
                'headers'   => ['User-Agent' => 'WordPress-RPC-CMP/1.0'],
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = strtoupper(trim(wp_remote_retrieve_body($response)));
                if (preg_match('/^[A-Z]{2}$/', $body)) {
                    set_transient($cache_key, $body, DAY_IN_SECONDS * 7);
                    return $body;
                }
            }
        }

        // Fallback seguro: aplicar estándar chileno Ley 21.719
        return 'CL';
    }

    /**
     * Determina el régimen jurídico aplicable según el código de país y la configuración del sitio
     */
    public static function get_legal_regime($country_code = null)
    {
        if (!$country_code) {
            $country_code = self::detect_country_code(false);
        }
        $country_code = strtoupper($country_code);
        $geo_mode = Rotar_Privacidad_Chile::get_setting('geo_mode', 'worldwide_strict');

        // Chile: Ley N° 21.719 (Opt-in previo estricto)
        if ($country_code === 'CL') {
            return [
                'country_code'   => 'CL',
                'law_code'       => 'CHL_21719',
                'law_label'      => 'Ley N° 21.719 (Chile)',
                'regime'         => 'opt_in', // Requiere consentimiento previo y expreso
                'show_banner'    => true,
                'prior_blocking' => true,
            ];
        }

        // Espacio Económico Europeo / Reino Unido / Suiza: RGPD
        if (in_array($country_code, self::GDPR_COUNTRIES, true)) {
            return [
                'country_code'   => $country_code,
                'law_code'       => 'EU_GDPR',
                'law_label'      => 'RGPD / GDPR (Europa)',
                'regime'         => 'opt_in',
                'show_banner'    => true,
                'prior_blocking' => true,
            ];
        }

        // Brasil: LGPD (Opt-in estricto)
        if ($country_code === 'BR') {
            return [
                'country_code'   => 'BR',
                'law_code'       => 'BR_LGPD',
                'law_label'      => 'LGPD (Brasil)',
                'regime'         => 'opt_in',
                'show_banner'    => true,
                'prior_blocking' => true,
            ];
        }

        // Estados Unidos: CCPA / CPRA (Opt-out de venta/compartición de datos)
        if ($country_code === 'US') {
            $strict_everywhere = ($geo_mode === 'worldwide_strict');
            return [
                'country_code'   => 'US',
                'law_code'       => 'US_CCPA',
                'law_label'      => 'CCPA / CPRA (EE.UU.)',
                'regime'         => $strict_everywhere ? 'opt_in' : 'opt_out',
                'show_banner'    => ($geo_mode !== 'chile_gdpr_only'),
                'prior_blocking' => $strict_everywhere,
            ];
        }

        // Otros países
        if ($geo_mode === 'chile_gdpr_only') {
            return [
                'country_code'   => $country_code,
                'law_code'       => 'GENERAL',
                'law_label'      => 'Estándar Internacional',
                'regime'         => 'opt_out',
                'show_banner'    => false,
                'prior_blocking' => false,
            ];
        }

        if ($geo_mode === 'auto_law_by_ip') {
            return [
                'country_code'   => $country_code,
                'law_code'       => 'INTL_OPTOUT',
                'law_label'      => 'Privacidad Internacional',
                'regime'         => 'opt_out',
                'show_banner'    => true,
                'prior_blocking' => false,
            ];
        }

        // Por defecto 'worldwide_strict': como empresa establecida en Chile, Ley 21.719 aplica a todo tratamiento efectuado por responsable en territorio chileno (Art. 2 Ley 21.719)
        return [
            'country_code'   => $country_code,
            'law_code'       => 'CHL_21719_EXT',
            'law_label'      => 'Ley N° 21.719 (Chile)',
            'regime'         => 'opt_in',
            'show_banner'    => true,
            'prior_blocking' => true,
        ];
    }

    /**
     * Endpoint AJAX ligero compatible con cachés (LiteSpeed / Redis) para resolver GeoIP en tiempo real
     */
    public static function ajax_detect_geo()
    {
        $country = self::detect_country_code(true);
        $regime  = self::get_legal_regime($country);
        wp_send_json_success($regime);
    }
}

