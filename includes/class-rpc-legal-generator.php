<?php
/**
 * Asistente Generador Automático de Textos y Páginas Legales (Ley N° 21.719 de Chile, Ley N° 19.628 y RGPD)
 * Redacta, crea o actualiza automáticamente en WordPress:
 *   1. Política de Cookies (con tabla dinámica en vivo del escáner)
 *   2. Declaración de Privacidad y Protección de Datos Personales
 *   3. Aviso de Exclusión Voluntaria (Opt-Out) y Ejercicio de Derechos ARSOP+
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Legal_Generator
{
    public static function init()
    {
        add_action('wp_ajax_rpc_generate_legal_pages', [__CLASS__, 'ajax_generate_legal_pages']);
    }

    /**
     * Actualiza una sola vez el HTML de las páginas legales existentes al formato editorial limpio y justificado v1.1.1
     */
    public static function maybe_refresh_existing_legal_pages_format()
    {
        if (get_option('rpc_legal_pages_formatted_v111')) {
            return;
        }
        $settings = Rotar_Privacidad_Chile::get_settings();
        if (!empty($settings['cookie_policy_page_id']) || !empty($settings['privacy_policy_page_id']) || !empty($settings['optout_page_id'])) {
            self::ensure_legal_pages_published(true);
            update_option('rpc_legal_pages_formatted_v111', 1);
        }
    }

    /**
     * Garantiza que las 3 páginas legales existan, estén publicadas y queden vinculadas en los ajustes del plugin
     */
    public static function maybe_auto_provision_legal_pages()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = Rotar_Privacidad_Chile::get_settings();
        $c_id = (int) ($settings['cookie_policy_page_id'] ?? 0);
        $p_id = (int) ($settings['privacy_policy_page_id'] ?? 0);
        $o_id = (int) ($settings['optout_page_id'] ?? 0);

        $missing = (
            $c_id <= 0 || get_post_status($c_id) !== 'publish' ||
            $p_id <= 0 || get_post_status($p_id) !== 'publish' ||
            $o_id <= 0 || get_post_status($o_id) !== 'publish'
        );

        // Si las páginas aún no existen, no están publicadas o requieren migración de formato limpio v1.1.1
        $needs_refresh = !get_option('rpc_legal_pages_formatted_v111');

        if ($missing || $needs_refresh) {
            self::ensure_legal_pages_published($needs_refresh);
            update_option('rpc_legal_pages_formatted_v111', 1);
        }
    }

    public static function ensure_legal_pages_published($force_refresh = false)
    {
        $wizard   = self::get_wizard_answers();
        $settings = Rotar_Privacidad_Chile::get_settings();

        $c_id = (int) ($settings['cookie_policy_page_id'] ?? 0);
        if ($force_refresh || $c_id <= 0 || get_post_status($c_id) !== 'publish') {
            $c_id = self::upsert_legal_page(
                $c_id,
                'politica-de-cookies',
                'Política de Cookies',
                self::build_cookie_policy_html($wizard),
                true
            );
            $settings['cookie_policy_page_id'] = $c_id;
        }

        $p_id = (int) ($settings['privacy_policy_page_id'] ?? 0);
        if ($force_refresh || $p_id <= 0 || get_post_status($p_id) !== 'publish') {
            $p_id = self::upsert_legal_page(
                $p_id,
                'declaracion-de-privacidad',
                'Declaración de Privacidad y Protección de Datos (Ley N° 21.719)',
                self::build_privacy_policy_html($wizard),
                true
            );
            $settings['privacy_policy_page_id'] = $p_id;
            if ($p_id > 0) {
                update_option('wp_page_for_privacy_policy', $p_id);
            }
        }

        $o_id = (int) ($settings['optout_page_id'] ?? 0);
        if ($force_refresh || $o_id <= 0 || get_post_status($o_id) !== 'publish') {
            $o_id = self::upsert_legal_page(
                $o_id,
                'derechos-privacidad-opt-out',
                'Derechos ARSOP+ y Exclusión Voluntaria (Opt-Out)',
                self::build_optout_page_html($wizard),
                true
            );
            $settings['optout_page_id'] = $o_id;
        }

        update_option(RPC_OPT_SETTINGS, $settings);
    }

    public static function get_wizard_defaults()
    {
        $settings = Rotar_Privacidad_Chile::get_settings();
        return [
            'company_legal_name'     => $settings['company_name'] ?: get_bloginfo('name'),
            'company_trade_name'     => get_bloginfo('name'),
            'company_rut'            => $settings['company_rut'] ?: '76.XXX.XXX-X',
            'company_address'        => $settings['company_address'] ?: 'Santiago, Región Metropolitana, Chile',
            'dpo_name'               => 'Encargado de Prevención y Protección de Datos',
            'dpo_email'              => $settings['dpo_email'] ?: get_option('admin_email'),
            'dpo_phone'              => $settings['dpo_phone'] ?: '+56 9 4254 2774',
            'has_ecommerce'          => class_exists('WooCommerce') ? 1 : 0,
            'payment_gateways'       => 'Transbank Webpay Plus / Transferencia Bancaria',
            'shipping_partners'      => 'Despacho propio y operadores logísticos en Chile (ej. Shipit / Starken / Chilexpress)',
            'has_contact_forms'      => 1,
            'has_whatsapp_chat'      => 1,
            'has_newsletter_crm'     => 1,
            'has_analytics_ads'      => 1,
            'international_transfer' => 1, // Servidores cloud / Google / Meta / Cloudflare
            'retention_years_tax'    => 6, // SII Chile (Código Tributario)
            'publish_immediately'    => 1,
        ];
    }

    public static function get_wizard_answers()
    {
        $saved = get_option(RPC_OPT_LEGAL_WIZARD, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return wp_parse_args($saved, self::get_wizard_defaults());
    }

    /**
     * Genera el HTML completo de la Política de Cookies adaptada a la Ley N° 21.719
     */
    public static function build_cookie_policy_html(array $w)
    {
        $company   = esc_html($w['company_legal_name']);
        $rut       = esc_html($w['company_rut']);
        $address   = esc_html($w['company_address']);
        $email     = esc_html($w['dpo_email']);
        $site_url  = esc_url(home_url('/'));
        $updated   = wp_date('d \d\e F \d\e Y');

        return <<<HTML
<div class="rpc-legal-document">
<p class="rpc-legal-updated"><strong>Última actualización automática:</strong> {$updated}</p>

<p>En <strong>{$company}</strong> (RUT: <strong>{$rut}</strong>), con domicilio en {$address}, titular del sitio web <a href="{$site_url}">{$site_url}</a>, valoramos la transparencia y el respeto a la autodeterminación informativa de nuestros usuarios y clientes en conformidad con la <strong>Ley N° 19.628 sobre Protección de la Vida Privada</strong>, modernizada integralmente por la <strong>Ley N° 21.719 sobre Protección de Datos Personales de la República de Chile</strong>, así como los estándares internacionales del Reglamento General de Protección de Datos (RGPD).</p>

<h2>1. ¿Qué son las cookies y tecnologías similares?</h2>

<p>Las cookies, píxeles y almacenamientos locales (<em>LocalStorage / SessionStorage</em>) son pequeños archivos de datos que el navegador descarga en su dispositivo cuando visita nuestro sitio web. Permiten garantizar la operatividad técnica del comercio electrónico, recordar sus preferencias y —únicamente cuando usted otorga su <strong>consentimiento previo, libre, específico, informado e inequívoco</strong>— medir estadísticas agregadas de uso o evaluar campañas publicitarias.</p>

<h2>2. Base de licitud y bloqueo previo (Art. 12 y 13 Ley N° 21.719)</h2>

<ul class="rpc-legal-list">
<li><strong>Cookies estrictamente necesarias (técnicas):</strong> Se instalan sobre la base de la ejecución precontractual y contractual solicitada por el usuario (por ejemplo, mantener su carrito de compras, procesar pagos seguros o proteger el sitio contra fraudes) y no requieren consentimiento previo.</li>
<li><strong>Cookies funcionales, analíticas y de marketing:</strong> Permanecen <strong>bloqueadas por defecto</strong> al ingresar al sitio y solo se activan mediante un acto afirmativo claro del titular en nuestro Banner o Centro de Preferencias. La simple navegación o el cierre del aviso nunca se interpretan como aceptación.</li>
</ul>

<h2>3. Gestión y revocación inmediata de su consentimiento</h2>

<p>Usted puede revisar, modificar por categoría o revocar su consentimiento en cualquier momento, con la misma facilidad con la que lo otorgó, utilizando el siguiente botón interactivo:</p>

[rotar_preferencias_cookies_btn texto="Abrir Centro de Preferencias y Revocar / Configurar Cookies"]

<h2>4. Inventario actualizado de cookies y servicios detectados en este sitio</h2>

<p>La siguiente tabla se genera y sincroniza mediante nuestro escáner periódico de auditoría técnica:</p>

[rotar_tabla_cookies]

<h2>5. Google Consent Mode v2</h2>

<p>Este sitio web implementa <strong>Google Consent Mode v2</strong>. Cuando usted rechaza las cookies analíticas o publicitarias, las señales técnicas (<code>ad_storage</code>, <code>ad_user_data</code>, <code>ad_personalization</code> y <code>analytics_storage</code>) se transmiten automáticamente en estado <code>denied</code> (denegado), impidiendo que Google almacene o lea cookies identificatorias en su navegador.</p>

<h2>6. Cómo deshabilitar cookies desde su navegador</h2>

<p>Adicionalmente a nuestro panel de control, puede eliminar o bloquear cookies directamente en los ajustes de Google Chrome, Mozilla Firefox, Safari o Microsoft Edge. Tenga presente que bloquear cookies técnicas esenciales puede impedir finalizar compras en línea.</p>

<h2>7. Contacto de Privacidad</h2>

<p>Para cualquier consulta sobre esta Política de Cookies o el tratamiento de sus datos personales, escríbanos a <a href="mailto:{$email}">{$email}</a>.</p>
</div>
HTML;
    }

    /**
     * Genera el HTML completo de la Declaración de Privacidad conforme a la Ley N° 21.719 (Chile)
     */
    public static function build_privacy_policy_html(array $w)
    {
        $company   = esc_html($w['company_legal_name']);
        $trade     = esc_html($w['company_trade_name']);
        $rut       = esc_html($w['company_rut']);
        $address   = esc_html($w['company_address']);
        $dpo_name  = esc_html($w['dpo_name']);
        $email     = esc_html($w['dpo_email']);
        $phone     = esc_html($w['dpo_phone']);
        $site_url  = esc_url(home_url('/'));
        $tax_years = max(1, (int) ($w['retention_years_tax'] ?? 6));
        $updated   = wp_date('d \d\e F \d\e Y');

        $purposes_list = "<li><strong>Atención de consultas y cotizaciones técnicas:</strong> Responder solicitudes enviadas mediante formularios de contacto, correo electrónico o canales de atención comercial (Base legal: consentimiento y medidas precontractuales, Art. 12 y 13 Ley N° 21.719).</li>";

        if (!empty($w['has_ecommerce'])) {
            $gateways = esc_html($w['payment_gateways']);
            $shipping = esc_html($w['shipping_partners']);
            $purposes_list .= "\n<li><strong>Ejecución de compraventa electrónica y facturación tributaria:</strong> Procesar pedidos de productos, emitir boletas o facturas electrónicas ante el Servicio de Impuestos Internos (SII), gestionar pagos seguros mediante <em>{$gateways}</em> y coordinar despachos a través de <em>{$shipping}</em> (Base legal: ejecución de contrato y obligación legal tributaria/consumidor Ley N° 19.496).</li>";
        }
        if (!empty($w['has_whatsapp_chat'])) {
            $purposes_list .= "\n<li><strong>Asesoría por WhatsApp corporativo:</strong> Atender consultas técnicas y comerciales iniciadas voluntariamente por el usuario a través de nuestro botón de WhatsApp.</li>";
        }
        if (!empty($w['has_newsletter_crm'])) {
            $purposes_list .= "\n<li><strong>Comunicaciones comerciales y seguimiento CRM:</strong> Enviar información técnica de productos, catálogos o promociones únicamente cuando exista consentimiento expreso o relación comercial vigente, garantizando siempre un mecanismo simple y gratuito de baja o exclusión voluntaria (<em>Opt-out</em>).</li>";
        }
        if (!empty($w['has_analytics_ads'])) {
            $purposes_list .= "\n<li><strong>Analítica web y medición publicitaria:</strong> Analizar métricas agregadas de navegación y rendimiento de campañas exclusivamente bajo consentimiento previo otorgado en el Banner de Cookies.</li>";
        }

        $intl_section = '';
        if (!empty($w['international_transfer'])) {
            $intl_section = <<<HTML
<h2>6. Transferencias Internacionales de Datos (Art. 27 y ss. Ley N° 21.719)</h2>

<p>Para la operación tecnológica segura del sitio web (alojamiento en la nube, redes de distribución de contenido/seguridad como Cloudflare y herramientas analíticas o de gestión autorizadas como Google o Meta), ciertos datos técnicos pueden procesarse en servidores ubicados fuera de Chile. <strong>{$company}</strong> verifica que dichos encargados cuenten con niveles adecuados de protección de datos, cláusulas contractuales estándar y certificaciones internacionales de seguridad de la información.</p>
HTML;
        }

        return <<<HTML
<div class="rpc-legal-document">
<p class="rpc-legal-updated"><strong>Fecha de entrada en vigor y última actualización:</strong> {$updated}</p>

<p>La presente <strong>Declaración de Privacidad y Protección de Datos Personales</strong> regula el tratamiento de los datos personales recolectados a través del sitio web <a href="{$site_url}">{$site_url}</a>, operado por <strong>{$company}</strong> ({$trade}), en estricto cumplimiento de la <strong>Ley N° 19.628</strong> y las disposiciones de la <strong>Ley N° 21.719 sobre Protección de Datos Personales de Chile</strong> (que crea la Agencia de Protección de Datos Personales).</p>

<h2>1. Identificación del Responsable del Tratamiento</h2>

<ul class="rpc-legal-list">
<li><strong>Razón Social / Responsable:</strong> {$company}</li>
<li><strong>RUT:</strong> {$rut}</li>
<li><strong>Domicilio:</strong> {$address}</li>
<li><strong>Contacto de Protección de Datos / DPO:</strong> {$dpo_name} — <a href="mailto:{$email}">{$email}</a> {$phone}</li>
</ul>

<h2>2. Categorías de datos personales que recolectamos</h2>

<p>Aplicando el principio de minimización y proporcionalidad, recolectamos únicamente los datos estrictamente necesarios según su interacción con el sitio:</p>

<ul class="rpc-legal-list">
<li><strong>Datos identificatorios y de contacto:</strong> Nombre, apellidos, RUT (para facturación electrónica en Chile), razón social, correo electrónico, teléfono y dirección de despacho o facturación.</li>
<li><strong>Datos transaccionales:</strong> Historial de cotizaciones, compras, productos adquiridos y comprobantes de pago (nunca almacenamos números completos de tarjetas bancarias ni códigos CVV, los cuales son procesados de forma cifrada directamente por la pasarela de pago certificada).</li>
<li><strong>Datos técnicos y de consentimiento:</strong> Dirección IP anonimizada/enmascarada, navegador, identificador único de auditoría de consentimiento (UUID) y preferencias de cookies.</li>
</ul>

<h2>3. Finalidades y Bases de Licitud del Tratamiento</h2>

<ul class="rpc-legal-list">
{$purposes_list}
</ul>

<h2>4. Destinatarios y Encargados del Tratamiento</h2>

<p><strong>{$company}</strong> no vende, arrienda ni cede bases de datos personales a terceros. Solo compartimos información indispensable con proveedores que actúan como mandatarios o encargados de tratamiento bajo deber de confidencialidad (pasarelas de pago, empresas de transporte para entrega de pedidos, facturación electrónica ante el SII y servicios de infraestructura tecnológica).</p>

<h2>5. Plazos de Conservación y Supresión</h2>

<p>Los datos personales se conservarán únicamente durante el tiempo necesario para cumplir la finalidad para la cual fueron recopilados. Los antecedentes tributarios y contables asociados a ventas se conservarán por el plazo legal de <strong>{$tax_years} años</strong> exigido por la normativa tributaria chilena. Los registros probatorios de consentimiento de privacidad se resguardan con fines de auditoría ante la autoridad competente.</p>

{$intl_section}

<h2>7. Derechos del Titular de los Datos (Derechos ARSOP+)</h2>

<p>De acuerdo con los artículos 4° a 11° de la Ley N° 21.719, usted puede ejercer en cualquier momento y de forma gratuita los siguientes derechos:</p>

<ul class="rpc-legal-list">
<li><strong>Derecho de Acceso:</strong> Solicitar confirmación sobre si tratamos sus datos personales, conocer su origen, finalidad y obtener copia de ellos.</li>
<li><strong>Derecho de Rectificación:</strong> Exigir la modificación de datos inexactos, desactualizados o incompletos.</li>
<li><strong>Derecho de Supresión (Cancelación):</strong> Solicitar la eliminación de sus datos cuando ya no sean necesarios para los fines autorizados o revoque su consentimiento (salvo deber legal de conservación).</li>
<li><strong>Derecho de Oposición y Exclusión Voluntaria (Opt-out):</strong> Oponerse al tratamiento para fines de marketing directo, perfilamiento o prospección comercial.</li>
<li><strong>Derecho a la Portabilidad:</strong> Solicitar copia de sus datos personales en un formato electrónico estructurado y de uso común cuando el tratamiento se efectúe de forma automatizada con base en su consentimiento o en un contrato.</li>
<li><strong>Derecho a no ser objeto de decisiones individuales automatizadas</strong> y derecho de bloqueo temporal mientras se resuelve una solicitud.</li>
</ul>

<p>Puede ejercer estos derechos escribiendo directamente a <a href="mailto:{$email}">{$email}</a> o completando nuestro formulario electrónico con seguimiento auditable en la página de Ejercicio de Derechos ARSOP+ y Exclusión Voluntaria. Si considera vulnerados sus derechos tras nuestra respuesta dentro del plazo legal, le asiste el derecho de reclamar ante la <strong>Agencia de Protección de Datos Personales de Chile</strong>.</p>

<h2>8. Medidas de Seguridad</h2>

<p>Implementamos medidas técnicas y organizativas apropiadas (cifrado TLS/SSL, firewall de aplicaciones web, control de accesos por roles, registro criptográfico de consentimientos y copias de respaldo seguras) para prevenir accesos no autorizados, pérdida, alteración o filtración de datos personales.</p>
</div>
HTML;
    }

    /**
     * Genera el HTML completo de la página de Exclusión Voluntaria (Opt-out) y Derechos ARSOP+
     */
    public static function build_optout_page_html(array $w)
    {
        $company = esc_html($w['company_legal_name']);
        $email   = esc_html($w['dpo_email']);
        $updated = wp_date('d \d\e F \d\e Y');

        return <<<HTML
<div class="rpc-legal-document">
<p class="rpc-legal-updated"><strong>Última actualización:</strong> {$updated}</p>

<p>En cumplimiento de la <strong>Ley N° 21.719 sobre Protección de Datos Personales de Chile</strong> (derechos de oposición a comunicaciones comerciales, revocación del consentimiento y ejercicio de derechos ARSOP+) y normativas internacionales de exclusión voluntaria (<em>Opt-out</em> / CCPA), <strong>{$company}</strong> pone a su disposición herramientas directas y gratuitas para controlar sus datos personales.</p>

[rotar_formulario_optout_arsop]

<p>También puede dirigir cualquier requerimiento formal de privacidad directamente a nuestro correo oficial de protección de datos: <a href="mailto:{$email}">{$email}</a>.</p>
</div>
HTML;
    }

    /**
     * Crea o actualiza una página de WordPress y devuelve su ID y URL
     */
    private static function upsert_legal_page($existing_id, $slug, $title, $html_content, $publish = true)
    {
        $status = $publish ? 'publish' : 'draft';
        $post_id = (int) $existing_id;

        if ($post_id > 0 && get_post($post_id)) {
            wp_update_post([
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $html_content,
                'post_status'  => $status,
            ]);
            return $post_id;
        }

        // Buscar por slug existente para no duplicar páginas
        $found = get_page_by_path($slug, OBJECT, 'page');
        if ($found && !empty($found->ID)) {
            wp_update_post([
                'ID'           => $found->ID,
                'post_title'   => $title,
                'post_content' => $html_content,
                'post_status'  => $status,
            ]);
            return (int) $found->ID;
        }

        $new_id = wp_insert_post([
            'post_type'      => 'page',
            'post_name'      => $slug,
            'post_title'     => $title,
            'post_content'   => $html_content,
            'post_status'    => $status,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);

        return is_wp_error($new_id) ? 0 : (int) $new_id;
    }

    /**
     * Procesa el asistente legal paso a paso vía AJAX y publica/actualiza las 3 páginas legales
     */
    public static function ajax_generate_legal_pages()
    {
        check_ajax_referer('rpc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes.'], 403);
        }

        $wizard = [
            'company_legal_name'     => sanitize_text_field(wp_unslash($_POST['company_legal_name'] ?? '')),
            'company_trade_name'     => sanitize_text_field(wp_unslash($_POST['company_trade_name'] ?? '')),
            'company_rut'            => sanitize_text_field(wp_unslash($_POST['company_rut'] ?? '')),
            'company_address'        => sanitize_text_field(wp_unslash($_POST['company_address'] ?? '')),
            'dpo_name'               => sanitize_text_field(wp_unslash($_POST['dpo_name'] ?? '')),
            'dpo_email'              => sanitize_email(wp_unslash($_POST['dpo_email'] ?? '')),
            'dpo_phone'              => sanitize_text_field(wp_unslash($_POST['dpo_phone'] ?? '')),
            'has_ecommerce'          => !empty($_POST['has_ecommerce']) ? 1 : 0,
            'payment_gateways'       => sanitize_text_field(wp_unslash($_POST['payment_gateways'] ?? '')),
            'shipping_partners'      => sanitize_text_field(wp_unslash($_POST['shipping_partners'] ?? '')),
            'has_contact_forms'      => !empty($_POST['has_contact_forms']) ? 1 : 0,
            'has_whatsapp_chat'      => !empty($_POST['has_whatsapp_chat']) ? 1 : 0,
            'has_newsletter_crm'     => !empty($_POST['has_newsletter_crm']) ? 1 : 0,
            'has_analytics_ads'      => !empty($_POST['has_analytics_ads']) ? 1 : 0,
            'international_transfer' => !empty($_POST['international_transfer']) ? 1 : 0,
            'retention_years_tax'    => max(1, (int) ($_POST['retention_years_tax'] ?? 6)),
            'publish_immediately'    => !empty($_POST['publish_immediately']) ? 1 : 0,
        ];

        update_option(RPC_OPT_LEGAL_WIZARD, $wizard);

        $settings = Rotar_Privacidad_Chile::get_settings();
        $publish  = !empty($wizard['publish_immediately']);

        $gen_cookies = !empty($_POST['gen_cookie_policy']);
        $gen_privacy = !empty($_POST['gen_privacy_policy']);
        $gen_optout  = !empty($_POST['gen_optout_page']);

        $created_pages = [];

        if ($gen_cookies) {
            $cookie_id = self::upsert_legal_page(
                $settings['cookie_policy_page_id'],
                'politica-de-cookies',
                'Política de Cookies',
                self::build_cookie_policy_html($wizard),
                $publish
            );
            $settings['cookie_policy_page_id'] = $cookie_id;
            $created_pages[] = [
                'title' => 'Política de Cookies',
                'id'    => $cookie_id,
                'url'   => get_permalink($cookie_id),
                'edit'  => get_edit_post_link($cookie_id, 'raw'),
            ];
        }

        if ($gen_privacy) {
            $privacy_id = self::upsert_legal_page(
                $settings['privacy_policy_page_id'],
                'declaracion-de-privacidad',
                'Declaración de Privacidad y Protección de Datos (Ley N° 21.719)',
                self::build_privacy_policy_html($wizard),
                $publish
            );
            $settings['privacy_policy_page_id'] = $privacy_id;
            if ($publish && $privacy_id > 0) {
                update_option('wp_page_for_privacy_policy', $privacy_id);
            }
            $created_pages[] = [
                'title' => 'Declaración de Privacidad (Ley 21.719)',
                'id'    => $privacy_id,
                'url'   => get_permalink($privacy_id),
                'edit'  => get_edit_post_link($privacy_id, 'raw'),
            ];
        }

        if ($gen_optout) {
            $optout_id = self::upsert_legal_page(
                $settings['optout_page_id'],
                'derechos-privacidad-opt-out',
                'Derechos ARSOP+ y Exclusión Voluntaria (Opt-Out)',
                self::build_optout_page_html($wizard),
                $publish
            );
            $settings['optout_page_id'] = $optout_id;
            $created_pages[] = [
                'title' => 'Derechos ARSOP+ y Exclusión Voluntaria (Opt-Out)',
                'id'    => $optout_id,
                'url'   => get_permalink($optout_id),
                'edit'  => get_edit_post_link($optout_id, 'raw'),
            ];
        }

        // Sincronizar datos corporativos con la configuración general
        $settings['company_name']    = $wizard['company_legal_name'];
        $settings['company_rut']     = $wizard['company_rut'];
        $settings['company_address'] = $wizard['company_address'];
        $settings['dpo_email']       = $wizard['dpo_email'];
        $settings['dpo_phone']       = $wizard['dpo_phone'];
        update_option(RPC_OPT_SETTINGS, $settings);

        wp_send_json_success([
            'message' => 'Documentos legales generados y sincronizados exitosamente con el Banner de Cookies.',
            'pages'   => $created_pages,
        ]);
    }
}

