<?php
/**
 * Integraciones Nativas con Herramientas de Marketing, Analítica, E-Commerce y Formularios
 * Compatible con:
 *  - Google Consent Mode v2 (Advanced & Basic: ad_storage, ad_user_data, ad_personalization, analytics_storage)
 *  - Google Tag Manager (dataLayer events: rpc_consent_default, rpc_consent_update)
 *  - WooCommerce (trazabilidad probatoria del consentimiento en pedidos HPOS y clásicos)
 *  - Elementor Pro Forms (casilla de aceptación legal y registro en envíos)
 *  - Contact Form 7 (etiqueta [rpc_privacy_consent] de consentimiento informado)
 *  - WP Consent Level API (estándar oficial WordPress para plugins terceros como PixelYourSite / GTM4WP)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Integrations
{
    public static function init()
    {
        $settings = Rotar_Privacidad_Chile::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        // 1. Google Consent Mode v2: DEBE inyectarse en la parte más alta de <head> (prioridad -9999) antes que GTM o gtag
        if (!empty($settings['gcm_v2_enabled'])) {
            add_action('wp_head', [__CLASS__, 'print_google_consent_mode_v2'], -9999);
        }

        // 2. Integración con WooCommerce Checkout (guarda evidencia de privacidad en el pedido)
        if (!empty($settings['wc_order_consent_meta'])) {
            add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_wc_order_consent_classic'], 10, 2);
            add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'save_wc_order_consent_blocks'], 10, 1);
            add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_wc_order_consent_admin']);
        }

        // 3. Integración con Contact Form 7
        if (!empty($settings['cf7_consent_tag'])) {
            add_action('wpcf7_init', [__CLASS__, 'register_cf7_consent_tag']);
        }

        // 4. Integración con Elementor Forms
        if (!empty($settings['elementor_forms_consent'])) {
            add_action('elementor_pro/forms/new_record', [__CLASS__, 'attach_elementor_form_consent'], 10, 2);
        }

        // 5. Compatibilidad con WP Consent API estándar
        if (!empty($settings['wp_consent_api_compat'])) {
            add_filter('wp_get_consent_type', [__CLASS__, 'filter_wp_consent_type']);
        }
    }

    /**
     * Imprime la inicialización oficial de Google Consent Mode v2 antes de cualquier etiqueta de Google Ads / GA4 / GTM
     */
    public static function print_google_consent_mode_v2()
    {
        if (is_admin()) {
            return;
        }

        $settings = Rotar_Privacidad_Chile::get_settings();
        $saved_cookie = RPC_Blocker::get_visitor_consent_cookie();

        // Por defecto, bajo Ley 21.719 (Opt-in previo), todo almacenamiento no técnico parte en 'denied'
        $analytics_state   = ($saved_cookie && !empty($saved_cookie['analytics'])) ? 'granted' : 'denied';
        $marketing_state   = ($saved_cookie && !empty($saved_cookie['marketing'])) ? 'granted' : 'denied';
        $preferences_state = ($saved_cookie && !empty($saved_cookie['preferences'])) ? 'granted' : 'denied';

        $wait_ms       = max(0, (int) ($settings['gcm_wait_for_update'] ?? 500));
        $passthrough   = !empty($settings['gcm_url_passthrough']) ? 'true' : 'false';
        $ads_redaction = !empty($settings['gcm_ads_data_redaction']) ? 'true' : 'false';

        ?>
<!-- Rotar Privacidad & Cookies (Ley N° 21.719 Chile) — Google Consent Mode v2 (Cache-Safe) -->
<script data-cfasync="false" data-no-optimize="1" data-pagespeed-no-defer>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
(function(){
    var m = 'denied', a = 'denied', p = 'denied';
    try {
        var match = document.cookie.match(/(?:^|;\s*)rpc_consent_ley21719=([^;]+)/);
        if (match) {
            var c = JSON.parse(decodeURIComponent(match[1]));
            if (c && String(c.version) === '<?php echo esc_js((string) ($settings['consent_version'] ?? '1.0')); ?>') {
                m = c.marketing ? 'granted' : 'denied';
                a = c.analytics ? 'granted' : 'denied';
                p = c.preferences ? 'granted' : 'denied';
            }
        }
    } catch(e) {}
    gtag('consent', 'default', {
        'ad_storage': m,
        'ad_user_data': m,
        'ad_personalization': m,
        'analytics_storage': a,
        'functionality_storage': p,
        'personalization_storage': p,
        'security_storage': 'granted',
        'wait_for_update': <?php echo (int) $wait_ms; ?>
    });
    gtag('set', 'url_passthrough', <?php echo $passthrough; ?>);
    gtag('set', 'ads_data_redaction', <?php echo $ads_redaction; ?>);
    window.dataLayer.push({
        'event': 'rpc_consent_init',
        'rpc_law': 'CHL_21719',
        'rpc_analytics': a,
        'rpc_marketing': m,
        'rpc_preferences': p
    });
})();
</script>
<!-- /Rotar Privacidad & Cookies -->
        <?php
    }

    /**
     * Adjunta metadatos de consentimiento al pedido WooCommerce (Checkout Clásico y HPOS)
     */
    public static function save_wc_order_consent_classic($order_id, $data = [])
    {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        self::attach_consent_to_order_object($order);
    }

    public static function save_wc_order_consent_blocks($order)
    {
        if (is_object($order)) {
            self::attach_consent_to_order_object($order);
        }
    }

    private static function attach_consent_to_order_object($order)
    {
        if (empty($_COOKIE['rpc_consent_ley21719'])) {
            $order->update_meta_data('_rpc_privacy_status', 'Sin elección explícita (Solo esenciales)');
            $order->save();
            return;
        }

        $raw = sanitize_text_field(wp_unslash($_COOKIE['rpc_consent_ley21719']));
        $decoded = json_decode(rawurldecode($raw), true);
        if (!is_array($decoded)) {
            $decoded = json_decode($raw, true);
        }

        if (is_array($decoded)) {
            $order->update_meta_data('_rpc_consent_uuid', sanitize_text_field($decoded['uuid'] ?? ''));
            $order->update_meta_data('_rpc_privacy_status', sanitize_text_field($decoded['status'] ?? 'custom'));
            $order->update_meta_data('_rpc_consent_timestamp', current_time('mysql'));
            $order->save();
        }
    }

    public static function display_wc_order_consent_admin($order)
    {
        $uuid = $order->get_meta('_rpc_consent_uuid');
        $status = $order->get_meta('_rpc_privacy_status');
        if (!$uuid && !$status) {
            return;
        }

        echo '<div style="margin-top:12px;padding:10px 12px;background:#f8fafc;border-left:3px solid #00AEEF;border-radius:4px;">';
        echo '<strong>🛡️ Cumplimiento Ley N° 21.719 (Cookies):</strong><br>';
        if ($status) {
            echo '<span>Estado: <code>' . esc_html($status) . '</code></span><br>';
        }
        if ($uuid) {
            echo '<span>ID Auditoría: <code>' . esc_html($uuid) . '</code></span>';
        }
        echo '</div>';
    }

    /**
     * Etiqueta personalizada [rpc_privacy_consent] para Contact Form 7
     */
    public static function register_cf7_consent_tag()
    {
        if (function_exists('wpcf7_add_form_tag')) {
            wpcf7_add_form_tag('rpc_privacy_consent', [__CLASS__, 'render_cf7_consent_tag']);
        }
    }

    public static function render_cf7_consent_tag($tag)
    {
        $privacy_id = (int) Rotar_Privacidad_Chile::get_setting('privacy_policy_page_id', 0);
        $privacy_url = $privacy_id ? get_permalink($privacy_id) : home_url('/politica-de-privacidad/');
        $company = Rotar_Privacidad_Chile::get_setting('company_name', get_bloginfo('name'));

        return '<label class="rpc-cf7-consent" style="display:flex;gap:8px;align-items:flex-start;font-size:13px;margin:10px 0;">'
            . '<input type="checkbox" name="rpc_ley21719_privacy_accepted" value="1" required style="margin-top:3px;">'
            . '<span>He leído y acepto el tratamiento de mis datos personales por <strong>' . esc_html($company) . '</strong> conforme a la <a href="' . esc_url($privacy_url) . '" target="_blank" rel="noopener">Declaración de Privacidad (Ley N° 21.719)</a>.</span>'
            . '</label>';
    }

    /**
     * Adjunta evidencia de consentimiento en registros de formularios de Elementor Pro
     */
    public static function attach_elementor_form_consent($record, $handler)
    {
        if (!empty($_COOKIE['rpc_consent_ley21719']) && method_exists($record, 'add_field')) {
            $raw = sanitize_text_field(wp_unslash($_COOKIE['rpc_consent_ley21719']));
            $decoded = json_decode(rawurldecode($raw), true);
            if (is_array($decoded) && !empty($decoded['uuid'])) {
                $record->add_field([
                    'id'    => 'rpc_consent_uuid',
                    'title' => 'ID Consentimiento Ley 21.719',
                    'value' => sanitize_text_field($decoded['uuid']),
                ]);
            }
        }
    }

    /**
     * Declara tipo de consentimiento 'optin' ante el ecosistema WP Consent API (usado por GTM4WP, PixelYourSite, Site Kit)
     */
    public static function filter_wp_consent_type($type)
    {
        $regime = RPC_GeoIP::get_legal_regime();
        return ($regime['regime'] === 'opt_out') ? 'optout' : 'optin';
    }
}

