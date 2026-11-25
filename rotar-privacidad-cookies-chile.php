<?php
/**
 * Plugin Name: Rotar Privacidad & Cookies Chile (Ley 21.719)
 * Plugin URI:  https://rotar.cl
 * Description: Plataforma integral de Gestión de Consentimiento (CMP) para WordPress adaptada a la Ley N° 21.719 de Protección de Datos Personales de Chile y RGPD: escaneo automático de cookies/scripts, banner personalizable con simetría legal y geolocalización IP, bloqueo previo de scripts, Google Consent Mode v2, asistente generador de textos legales (Política de Cookies, Privacidad y Derechos ARSOP+ / Opt-out) y registro auditable de consentimientos en servidor propio.
 * Version:     1.1.5
 * Author:      ROTAR Ingeniería
 * Requires at least: 5.8
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.8
 * Text Domain: rotar-privacidad-ley21719
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RPC_VERSION', '1.1.5');
define('RPC_FILE', __FILE__);
define('RPC_PATH', plugin_dir_path(__FILE__));
define('RPC_URL', plugin_dir_url(__FILE__));
define('RPC_OPT_SETTINGS', 'rpc_ley21719_settings');
define('RPC_OPT_COOKIES', 'rpc_ley21719_detected_cookies');
define('RPC_OPT_LEGAL_WIZARD', 'rpc_ley21719_legal_wizard');

// Declarar compatibilidad total con WooCommerce HPOS y Bloques
add_action('before_woocommerce_init', function () {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Cargar módulos del plugin
require_once RPC_PATH . 'includes/class-rpc-db.php';
require_once RPC_PATH . 'includes/class-rpc-geoip.php';
require_once RPC_PATH . 'includes/class-rpc-scanner.php';
require_once RPC_PATH . 'includes/class-rpc-blocker.php';
require_once RPC_PATH . 'includes/class-rpc-integrations.php';
require_once RPC_PATH . 'includes/class-rpc-legal-generator.php';
require_once RPC_PATH . 'includes/class-rpc-frontend.php';
require_once RPC_PATH . 'includes/class-rpc-admin.php';

/**
 * Clase principal orquestadora del plugin
 */
final class Rotar_Privacidad_Chile
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ajustes predeterminados diseñados para cumplir con la Ley N° 21.719 (Chile) y RGPD
     */
    public static function get_defaults()
    {
        $site_name = function_exists('get_bloginfo') ? get_bloginfo('name') : 'ROTAR';
        $admin_email = function_exists('get_option') ? get_option('admin_email') : 'contacto@rotar.cl';

        return [
            // Estado general
            'enabled' => 1,
            'consent_version' => '1.0',
            'consent_expiry_days' => 180,
            'prior_blocking' => 1,
            'block_iframes' => 1,

            // Geolocalización y Reglas por País
            'geo_mode' => 'worldwide_strict', // 'worldwide_strict' | 'chile_gdpr_only' | 'auto_law_by_ip'
            'geo_api_fallback' => 1,
            'show_law_badge' => 1,

            // Diseño y Posición del Banner (Colores corporativos oficiales de ROTAR)
            'banner_layout' => 'bar_bottom', // 'bar_bottom' | 'box_left' | 'box_right' | 'modal_center'
            'floating_reopen_btn' => 0,            // Oculto por defecto tras aceptar/rechazar para no estorbar ni superponerse
            'floating_position' => 'left',
            'floating_label' => 'Privacidad y Cookies',
            'primary_color' => '#0B4268', // Azul Corporativo Oficial del Logo ROTAR
            'accent_color' => '#0B4268', // Botones en Azul Corporativo Oficial del Logo ROTAR
            'bg_color' => '#ffffff',
            'text_color' => '#1e293b',
            'btn_radius' => '6',       // Radio corporativo de botones ROTAR (6px)
            'symmetric_buttons' => 1, // Obligatorio Ley 21.719: igual jerarquía visual Aceptar / Rechazar

            // Textos del Banner
            'banner_title' => 'Valoramos tu privacidad y protegemos tus datos personales',
            'banner_message' => 'Utilizamos cookies propias y de terceros estrictamente necesarias para el funcionamiento del sitio y, con tu consentimiento previo y expreso, cookies funcionales, analíticas y de marketing para mejorar tu experiencia y medir campañas conforme a la Ley N° 21.719 de Protección de Datos Personales de Chile.',
            'banner_message_gdpr' => 'Utilizamos cookies propias y de terceros necesarias para el funcionamiento del sitio y, con su consentimiento expreso (Art. 6.1.a RGPD), tecnologías de análisis y publicidad personalizada.',
            'btn_accept_text' => 'Aceptar todas',
            'btn_reject_text' => 'Rechazar no esenciales',
            'btn_settings_text' => 'Configurar preferencias',
            'btn_save_prefs_text' => 'Guardar mis preferencias',

            // Integraciones y Google Consent Mode v2
            'gcm_v2_enabled' => 1,
            'gcm_url_passthrough' => 1,
            'gcm_ads_data_redaction' => 1,
            'gcm_wait_for_update' => 500,
            'gtm_events_enabled' => 1,
            'wc_order_consent_meta' => 1,
            'cf7_consent_tag' => 1,
            'elementor_forms_consent' => 1,
            'wp_consent_api_compat' => 1,

            // Páginas Legales Vinculadas
            'cookie_policy_page_id' => 0,
            'privacy_policy_page_id' => (int) get_option('wp_page_for_privacy_policy', 0),
            'optout_page_id' => 0,

            // Retención de registros
            'log_retention_months' => 36, // 3 años de respaldo ante auditoría de la Agencia de Protección de Datos
            'anonymize_ip_in_logs' => 1,

            // Datos base del Responsable (sincronizados con asistente legal)
            'company_name' => $site_name ?: 'ROTAR Ingeniería',
            'company_rut' => '',
            'company_address' => 'Santiago, Chile',
            'dpo_email' => $admin_email,
            'dpo_phone' => '',
        ];
    }

    public static function get_settings()
    {
        $saved = get_option(RPC_OPT_SETTINGS, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        // Migración automática v1.1.4: aplicar el Azul Corporativo exacto del logo ROTAR (#0B4268)
        if (!empty($saved) && !get_option('rpc_migrated_v114')) {
            $saved['primary_color'] = '#0B4268';
            $saved['accent_color'] = '#0B4268';
            $saved['btn_radius'] = '6';
            $saved['floating_reopen_btn'] = 0;
            update_option(RPC_OPT_SETTINGS, $saved);
            update_option('rpc_migrated_v114', 1);
        }

        return wp_parse_args($saved, self::get_defaults());
    }

    public static function get_setting($key, $default = null)
    {
        $settings = self::get_settings();
        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }
        return $default;
    }

    private function __construct()
    {
        register_activation_hook(RPC_FILE, [$this, 'on_activate']);
        register_deactivation_hook(RPC_FILE, [$this, 'on_deactivate']);

        add_action('admin_init', ['RPC_DB', 'maybe_install']);
        add_action('init', ['RPC_Legal_Generator', 'maybe_refresh_existing_legal_pages_format'], 25);
        add_action('admin_init', ['RPC_Legal_Generator', 'maybe_auto_provision_legal_pages'], 20);
        add_action('rpc_scheduled_cookie_scan', ['RPC_Scanner', 'run_automated_scan']);

        // Inicializar subsistemas
        RPC_GeoIP::init();
        RPC_Scanner::init();
        RPC_Integrations::init();
        RPC_Blocker::init();
        RPC_Legal_Generator::init();
        RPC_Frontend::init();
        RPC_Admin::init();

        // Enlace rápido de "Ajustes" en listado de plugins
        add_filter('plugin_action_links_' . plugin_basename(RPC_FILE), [$this, 'plugin_action_links']);
    }

    public function on_activate()
    {
        RPC_DB::install();

        if (!get_option(RPC_OPT_SETTINGS)) {
            update_option(RPC_OPT_SETTINGS, self::get_defaults());
        }

        // Ejecutar primer escaneo automático al activar para poblar inventario real del sitio
        if (!get_option(RPC_OPT_COOKIES)) {
            RPC_Scanner::run_full_scan();
        }

        // Crear y enlazar automáticamente las 3 páginas legales obligatorias si aún no están configuradas
        RPC_Legal_Generator::ensure_legal_pages_published();

        // Programar escaneo periódico semanal
        if (!wp_next_scheduled('rpc_scheduled_cookie_scan')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'rpc_scheduled_cookie_scan');
        }
    }

    public function on_deactivate()
    {
        $timestamp = wp_next_scheduled('rpc_scheduled_cookie_scan');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'rpc_scheduled_cookie_scan');
        }
    }

    public function plugin_action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=rotar-privacidad-chile')) . '" style="font-weight:700;color:#0B4268;">Configurar CMP</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

Rotar_Privacidad_Chile::get_instance();

