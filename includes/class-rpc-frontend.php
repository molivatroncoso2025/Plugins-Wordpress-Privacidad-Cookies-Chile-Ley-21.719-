<?php
/**
 * Renderizado Frontend: Banner de Consentimiento, Modal Granular por Categoría, Botón de Reapertura y AJAX de Auditoría
 * Cumple simetría visual estricta (sin dark patterns) y casillas desmarcadas por defecto según Ley N° 21.719 (Chile)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Frontend
{
    public static function init()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('body_class', [__CLASS__, 'add_legal_page_body_class']);
        add_action('wp_footer', [__CLASS__, 'render_banner_and_modal'], 99);

        // AJAX para guardar consentimiento en el registro auditable del servidor
        add_action('wp_ajax_rpc_log_consent', [__CLASS__, 'ajax_log_consent']);
        add_action('wp_ajax_nopriv_rpc_log_consent', [__CLASS__, 'ajax_log_consent']);

        // AJAX para formulario de exclusión voluntaria (Opt-Out) y Derechos ARSOP+
        add_action('wp_ajax_rpc_submit_privacy_request', [__CLASS__, 'ajax_submit_privacy_request']);
        add_action('wp_ajax_nopriv_rpc_submit_privacy_request', [__CLASS__, 'ajax_submit_privacy_request']);

        // Shortcodes públicos para insertar en páginas legales o cualquier sección
        add_shortcode('rotar_tabla_cookies', [__CLASS__, 'shortcode_cookie_table']);
        add_shortcode('rotar_preferencias_cookies_btn', [__CLASS__, 'shortcode_reopen_button']);
        add_shortcode('rotar_formulario_optout_arsop', [__CLASS__, 'shortcode_optout_form']);
    }

    public static function add_legal_page_body_class($classes)
    {
        if (is_singular('page')) {
            $settings = Rotar_Privacidad_Chile::get_settings();
            $current_id = get_queried_object_id();
            $legal_ids = array_filter([
                (int) ($settings['cookie_policy_page_id'] ?? 0),
                (int) ($settings['privacy_policy_page_id'] ?? 0),
                (int) ($settings['optout_page_id'] ?? 0),
            ]);
            if ($current_id > 0 && in_array($current_id, $legal_ids, true)) {
                $classes[] = 'rpc-fullwidth-legal-page';
            }
        }
        return $classes;
    }

    public static function enqueue_assets()
    {
        $settings = Rotar_Privacidad_Chile::get_settings();
        if (empty($settings['enabled']) || !empty($_GET['elementor-preview'])) {
            return;
        }

        wp_enqueue_style(
            'rpc-frontend-css',
            RPC_URL . 'assets/css/rpc-frontend.css',
            [],
            RPC_VERSION
        );

        // Variables CSS dinámicas sincronizadas con el Azul Corporativo Oficial del Logo ROTAR (#0B4268)
        $primary = sanitize_hex_color($settings['primary_color']) ?: '#0B4268';
        $accent  = sanitize_hex_color($settings['accent_color']) ?: '#0B4268';
        $bg      = sanitize_hex_color($settings['bg_color']) ?: '#ffffff';
        $text    = sanitize_hex_color($settings['text_color']) ?: '#1e293b';
        $radius  = max(0, min(30, (int) ($settings['btn_radius'] ?? 6)));

        if (in_array(strtolower($accent), ['#00aeef', '#e87400', '#41b9ea'], true)) {
            $accent  = '#0B4268';
            $primary = '#0B4268';
        }

        $custom_css = ":root {
            --rpc-primary: {$primary};
            --rpc-primary-hover: #072d47;
            --rpc-accent: {$accent};
            --rpc-accent-hover: #072d47;
            --rpc-bg: {$bg};
            --rpc-text: {$text};
            --rpc-radius: {$radius}px;
        }";
        wp_add_inline_style('rpc-frontend-css', $custom_css);

        wp_enqueue_script(
            'rpc-frontend-js',
            RPC_URL . 'assets/js/rpc-frontend.js',
            [],
            RPC_VERSION,
            true
        );

        $country = RPC_GeoIP::detect_country_code(false);
        $regime  = RPC_GeoIP::get_legal_regime($country);

        wp_localize_script('rpc-frontend-js', 'rpcConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('rpc_public_nonce'),
            'cookieName'     => 'rpc_consent_ley21719',
            'consentVersion' => (string) ($settings['consent_version'] ?? '1.0'),
            'expiryDays'     => max(30, (int) ($settings['consent_expiry_days'] ?? 180)),
            'geoMode'        => $settings['geo_mode'] ?? 'worldwide_strict',
            'initialRegime'  => $regime,
            'gcmEnabled'     => !empty($settings['gcm_v2_enabled']),
            'gtmEvents'      => !empty($settings['gtm_events_enabled']),
            'messages'       => [
                'chl'  => $settings['banner_message'],
                'gdpr' => $settings['banner_message_gdpr'],
            ],
        ]);
    }

    public static function render_banner_and_modal()
    {
        $settings = Rotar_Privacidad_Chile::get_settings();
        if (empty($settings['enabled']) || !empty($_GET['elementor-preview'])) {
            return;
        }

        $grouped_cookies = RPC_Scanner::get_cookies_by_category();
        $regime          = RPC_GeoIP::get_legal_regime();
        $layout_class    = 'rpc-layout-' . sanitize_html_class($settings['banner_layout'] ?? 'bar_bottom');
        $float_pos       = ($settings['floating_position'] ?? 'left') === 'right' ? 'rpc-float-right' : 'rpc-float-left';
        $symmetric_class = !empty($settings['symmetric_buttons']) ? 'rpc-symmetric-btns' : '';

        $cookie_page_url  = !empty($settings['cookie_policy_page_id']) ? get_permalink((int) $settings['cookie_policy_page_id']) : '';
        $privacy_page_url = !empty($settings['privacy_policy_page_id']) ? get_permalink((int) $settings['privacy_policy_page_id']) : get_privacy_policy_url();
        $optout_page_url  = !empty($settings['optout_page_id']) ? get_permalink((int) $settings['optout_page_id']) : '';

        $categories_meta = [
            'necessary' => [
                'title'       => 'Cookies Estrictamente Necesarias (Técnicas)',
                'badge'       => 'Siempre activas',
                'locked'      => true,
                'description' => 'Indispensables para el funcionamiento básico del sitio web, navegación segura, sesión de usuario, carrito de compras WooCommerce y pasarela de pagos (Webpay). No requieren consentimiento previo según la Ley N° 21.719.',
            ],
            'preferences' => [
                'title'       => 'Cookies Funcionales y de Preferencias',
                'badge'       => 'Opcionales',
                'locked'      => false,
                'description' => 'Permiten recordar tus preferencias de visualización, mapas interactivos de sucursales, idioma y widgets visuales mejorados.',
            ],
            'analytics' => [
                'title'       => 'Cookies Analíticas y de Medición Estadística',
                'badge'       => 'Opcionales',
                'locked'      => false,
                'description' => 'Nos ayudan a comprender cómo interactúan los visitantes con el sitio web (ej. Google Analytics 4, páginas más vistas y rendimiento técnico) para mejorar continuamente nuestros productos y servicios.',
            ],
            'marketing' => [
                'title'       => 'Cookies de Marketing, Publicidad y Multimedia',
                'badge'       => 'Opcionales',
                'locked'      => false,
                'description' => 'Utilizadas para medir la efectividad de campañas publicitarias (Google Ads, Meta Pixel, CRM) y reproducir videos externos incrustados (YouTube). Permanecen bloqueadas hasta tu autorización expresa.',
            ],
        ];
        ?>
        <!-- Banner de Consentimiento — Rotar Privacidad & Cookies (Ley N° 21.719 Chile) -->
        <aside id="rpc-cookie-banner" class="rpc-banner <?php echo esc_attr($layout_class . ' ' . $symmetric_class); ?>" role="dialog" aria-live="polite" aria-label="<?php echo esc_attr($settings['banner_title']); ?>" hidden>
            <div class="rpc-banner-inner">
                <div class="rpc-banner-content">
                    <div class="rpc-banner-header">
                        <span class="rpc-shield-icon" aria-hidden="true">🛡️</span>
                        <h2 class="rpc-banner-title"><?php echo esc_html($settings['banner_title']); ?></h2>
                        <?php if (!empty($settings['show_law_badge'])) : ?>
                            <span id="rpc-law-badge" class="rpc-law-badge"><?php echo esc_html($regime['law_label']); ?></span>
                        <?php endif; ?>
                    </div>
                    <p id="rpc-banner-desc" class="rpc-banner-text"><?php echo esc_html($settings['banner_message']); ?></p>
                    <div class="rpc-banner-links">
                        <?php if ($privacy_page_url) : ?>
                            <a href="<?php echo esc_url($privacy_page_url); ?>">Declaración de Privacidad</a>
                        <?php endif; ?>
                        <?php if ($cookie_page_url) : ?>
                            <a href="<?php echo esc_url($cookie_page_url); ?>">Política de Cookies</a>
                        <?php endif; ?>
                        <?php if ($optout_page_url) : ?>
                            <a href="<?php echo esc_url($optout_page_url); ?>">Derechos ARSOP+ / Opt-out</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rpc-banner-actions">
                    <button type="button" id="rpc-btn-accept-all" class="rpc-btn rpc-btn-primary">
                        <?php echo esc_html($settings['btn_accept_text']); ?>
                    </button>
                    <button type="button" id="rpc-btn-reject-all" class="rpc-btn rpc-btn-reject">
                        <?php echo esc_html($settings['btn_reject_text']); ?>
                    </button>
                    <button type="button" id="rpc-btn-open-prefs" class="rpc-btn rpc-btn-outline">
                        <?php echo esc_html($settings['btn_settings_text']); ?>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Modal Granular de Preferencias de Privacidad -->
        <div id="rpc-prefs-modal" class="rpc-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rpc-modal-title" hidden>
            <div class="rpc-modal-card">
                <div class="rpc-modal-header">
                    <div>
                        <h2 id="rpc-modal-title">Centro de Preferencias de Privacidad y Cookies</h2>
                        <p class="rpc-modal-subtitle">Configura de manera libre, específica e informada qué categorías deseas autorizar. Puedes modificar o revocar tu decisión en cualquier momento (Art. 12 Ley N° 21.719).</p>
                    </div>
                    <button type="button" id="rpc-modal-close" class="rpc-modal-close" aria-label="Cerrar panel" title="Cerrar">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                <div class="rpc-modal-body">
                    <?php foreach ($categories_meta as $cat_key => $meta) :
                        $cat_cookies = $grouped_cookies[$cat_key] ?? [];
                        ?>
                        <div class="rpc-category-block" data-cat-block="<?php echo esc_attr($cat_key); ?>">
                            <div class="rpc-category-top">
                                <div class="rpc-category-info">
                                    <div class="rpc-category-title-row">
                                        <h3><?php echo esc_html($meta['title']); ?></h3>
                                        <span class="rpc-cat-count"><?php echo count($cat_cookies); ?> detectadas</span>
                                    </div>
                                    <p><?php echo esc_html($meta['description']); ?></p>
                                </div>
                                <div class="rpc-category-control">
                                    <?php if ($meta['locked']) : ?>
                                        <span class="rpc-badge-always"><?php echo esc_html($meta['badge']); ?></span>
                                        <input type="checkbox" checked disabled aria-label="<?php echo esc_attr($meta['title']); ?>">
                                    <?php else : ?>
                                        <label class="rpc-switch">
                                            <input type="checkbox" class="rpc-cat-toggle" data-rpc-toggle="<?php echo esc_attr($cat_key); ?>">
                                            <span class="rpc-slider"></span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($cat_cookies)) : ?>
                                <details class="rpc-cookie-details">
                                    <summary>Ver detalle técnico de cookies y proveedores (<?php echo count($cat_cookies); ?>)</summary>
                                    <div class="rpc-cookie-mini-table-wrap">
                                        <table class="rpc-cookie-mini-table">
                                            <thead>
                                                <tr>
                                                    <th>Cookie / Clave</th>
                                                    <th>Servicio / Proveedor</th>
                                                    <th>Duración</th>
                                                    <th>Finalidad</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($cat_cookies as $ck) : ?>
                                                    <tr>
                                                        <td><code><?php echo esc_html($ck['name']); ?></code></td>
                                                        <td><?php echo esc_html($ck['service'] . ' (' . $ck['provider'] . ')'); ?></td>
                                                        <td><?php echo esc_html($ck['duration']); ?></td>
                                                        <td><?php echo esc_html($ck['purpose']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div id="rpc-audit-receipt" class="rpc-audit-receipt" hidden>
                        <strong>Comprobante de Consentimiento (Trazabilidad Auditable):</strong>
                        <span id="rpc-receipt-uuid"></span>
                    </div>
                </div>

                <div class="rpc-modal-footer <?php echo esc_attr($symmetric_class); ?>">
                    <button type="button" id="rpc-modal-reject-all" class="rpc-btn rpc-btn-reject">
                        <?php echo esc_html($settings['btn_reject_text']); ?>
                    </button>
                    <button type="button" id="rpc-modal-save-prefs" class="rpc-btn rpc-btn-outline">
                        <?php echo esc_html($settings['btn_save_prefs_text']); ?>
                    </button>
                    <button type="button" id="rpc-modal-accept-all" class="rpc-btn rpc-btn-primary">
                        <?php echo esc_html($settings['btn_accept_text']); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php if (!empty($settings['floating_reopen_btn'])) : ?>
            <!-- Botón Flotante Discreto para Revocar o Modificar Consentimiento en cualquier momento -->
            <button type="button" id="rpc-floating-trigger" class="rpc-floating-btn <?php echo esc_attr($float_pos); ?>" title="<?php echo esc_attr($settings['floating_label']); ?>" aria-label="<?php echo esc_attr($settings['floating_label']); ?>" hidden>
                <span class="rpc-floating-icon" aria-hidden="true">🍪</span>
                <span class="rpc-floating-text"><?php echo esc_html($settings['floating_label']); ?></span>
            </button>
        <?php endif; ?>
        <?php
    }

    /**
     * Endpoint AJAX para registrar el consentimiento en el servidor propio (compatible con páginas cacheadas > 24h en LiteSpeed)
     */
    public static function ajax_log_consent()
    {
        // Rate limiting anti-abuso por IP (máx. 30 cambios de consentimiento por minuto por IP)
        $ip = RPC_GeoIP::get_client_ip();
        $rl_key = 'rpc_rl_log_' . substr(md5($ip), 0, 16);
        $hits = (int) get_transient($rl_key);
        if ($hits > 30) {
            wp_send_json_error(['message' => 'Demasiadas solicitudes.'], 429);
        }
        set_transient($rl_key, $hits + 1, MINUTE_IN_SECONDS);

        $uuid = sanitize_text_field(wp_unslash($_POST['consent_uuid'] ?? ''));
        if (!preg_match('/^[a-f0-9\-]{16,64}$/i', $uuid)) {
            $uuid = wp_generate_uuid4();
        }

        $allowed_statuses = ['accepted_all', 'rejected_all', 'custom'];
        $status = sanitize_key($_POST['status'] ?? 'custom');
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'custom';
        }

        $payload = [
            'consent_uuid'   => $uuid,
            'status'         => $status,
            'preferences'    => !empty($_POST['preferences']) && $_POST['preferences'] !== 'false' && $_POST['preferences'] !== '0',
            'analytics'      => !empty($_POST['analytics']) && $_POST['analytics'] !== 'false' && $_POST['analytics'] !== '0',
            'marketing'      => !empty($_POST['marketing']) && $_POST['marketing'] !== 'false' && $_POST['marketing'] !== '0',
            'country_code'   => substr(preg_replace('/[^A-Za-z]/', '', sanitize_text_field(wp_unslash($_POST['country_code'] ?? 'CL'))), 0, 4) ?: 'CL',
            'applicable_law' => substr(sanitize_text_field(wp_unslash($_POST['applicable_law'] ?? 'CHL_21719')), 0, 35),
            'page_url'       => esc_url_raw(wp_unslash($_POST['page_url'] ?? home_url('/'))),
        ];

        $result = RPC_DB::log_consent($payload);
        wp_send_json_success($result);
    }

    /**
     * Endpoint AJAX público para recibir solicitudes ARSOP+ y Opt-Out (Exclusión Voluntaria)
     */
    public static function ajax_submit_privacy_request()
    {
        // Rate limiting anti-spam en formulario público (máx. 5 solicitudes cada 15 minutos por IP)
        $ip = RPC_GeoIP::get_client_ip();
        $rl_key = 'rpc_rl_req_' . substr(md5($ip), 0, 16);
        $hits = (int) get_transient($rl_key);
        if ($hits >= 5) {
            wp_send_json_error(['message' => 'Has alcanzado el límite temporal de envíos. Por favor intenta más tarde o escríbenos por correo.'], 429);
        }

        // Honeypot silencioso contra bots
        if (!empty($_POST['rpc_hp_website'])) {
            wp_send_json_success(['message' => 'Solicitud recibida.', 'request_code' => 'ARSOP-OK']);
        }

        $name  = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $rut   = sanitize_text_field(wp_unslash($_POST['rut'] ?? ''));
        $type  = sanitize_key($_POST['request_type'] ?? 'oposicion_optout');
        $uuid  = sanitize_text_field(wp_unslash($_POST['consent_uuid'] ?? ''));
        $msg   = sanitize_textarea_field(wp_unslash($_POST['details'] ?? ''));

        if ($name === '' || !is_email($email)) {
            wp_send_json_error(['message' => 'Por favor ingresa tu nombre completo y un correo electrónico válido.']);
        }

        set_transient($rl_key, $hits + 1, 15 * MINUTE_IN_SECONDS);

        $saved = RPC_DB::log_privacy_request([
            'request_type' => $type,
            'name'         => $name,
            'rut'          => $rut,
            'email'        => $email,
            'consent_uuid' => $uuid,
            'details'      => $msg,
        ]);

        // Notificar por correo al encargado de protección de datos (DPO)
        $dpo_email = Rotar_Privacidad_Chile::get_setting('dpo_email', get_option('admin_email'));
        if (is_email($dpo_email)) {
            $subject = '[' . get_bloginfo('name') . '] Nueva Solicitud Ley 21.719 (' . $saved['request_code'] . ')';
            $body = "Se ha recibido una solicitud de ejercicio de derechos (Ley N° 21.719):\n\n"
                . "Código de seguimiento: {$saved['request_code']}\n"
                . "Tipo de derecho: {$type}\n"
                . "Titular: {$name}\n"
                . "RUT / Documento: {$rut}\n"
                . "Email: {$email}\n"
                . "ID Consentimiento Cookie: {$uuid}\n"
                . "Detalle:\n{$msg}\n\n"
                . "Gestionar en el panel de administración: " . admin_url('admin.php?page=rotar-privacidad-chile&tab=requests');
            @wp_mail($dpo_email, $subject, $body);
        }

        wp_send_json_success([
            'message'      => 'Tu solicitud ha sido registrada exitosamente con el código de seguimiento ' . $saved['request_code'] . '. Responderemos dentro del plazo legal establecido por la Ley N° 21.719.',
            'request_code' => $saved['request_code'],
        ]);
    }

    /**
     * Shortcode [rotar_tabla_cookies]: Tabla dinámica siempre sincronizada con el último escaneo
     */
    public static function shortcode_cookie_table()
    {
        $grouped = RPC_Scanner::get_cookies_by_category();
        $labels = [
            'necessary'   => '1. Cookies Estrictamente Necesarias (Técnicas)',
            'preferences' => '2. Cookies Funcionales y de Preferencias',
            'analytics'   => '3. Cookies Analíticas y Estadísticas',
            'marketing'   => '4. Cookies de Marketing y Publicidad de Terceros',
        ];

        ob_start();
        echo '<div class="rpc-public-cookie-inventory">';
        foreach ($labels as $cat => $heading) {
            $items = $grouped[$cat] ?? [];
            echo '<h3 style="margin-top:24px;margin-bottom:10px;font-size:1.15rem;">' . esc_html($heading) . '</h3>';
            if (empty($items)) {
                echo '<p><em>Actualmente no se utilizan cookies activas en esta categoría.</em></p>';
                continue;
            }
            echo '<div style="overflow-x:auto;"><table class="rpc-legal-table" style="width:100%;border-collapse:collapse;margin-bottom:18px;font-size:14px;">';
            echo '<thead><tr style="background:#f1f5f9;text-align:left;"><th style="padding:10px;border:1px solid #cbd5e1;">Cookie</th><th style="padding:10px;border:1px solid #cbd5e1;">Servicio / Proveedor</th><th style="padding:10px;border:1px solid #cbd5e1;">Duración</th><th style="padding:10px;border:1px solid #cbd5e1;">Finalidad</th></tr></thead><tbody>';
            foreach ($items as $ck) {
                echo '<tr>';
                echo '<td style="padding:10px;border:1px solid #e2e8f0;"><code>' . esc_html($ck['name']) . '</code></td>';
                echo '<td style="padding:10px;border:1px solid #e2e8f0;">' . esc_html($ck['service'] . ' — ' . $ck['provider']) . '</td>';
                echo '<td style="padding:10px;border:1px solid #e2e8f0;">' . esc_html($ck['duration']) . '</td>';
                echo '<td style="padding:10px;border:1px solid #e2e8f0;">' . esc_html($ck['purpose']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Shortcode [rotar_preferencias_cookies_btn]: Botón para abrir el panel de cookies desde cualquier texto legal
     */
    public static function shortcode_reopen_button($atts)
    {
        $atts = shortcode_atts([
            'texto' => 'Abrir Centro de Preferencias y Configurar Cookies',
        ], $atts);

        return '<div class="rpc-reopen-btn-wrap">'
            . '<button type="button" class="rpc-inline-reopen-btn" onclick="if(window.RotarCMP){window.RotarCMP.openModal();}">'
            . '<span class="rpc-btn-cookie-emoji" aria-hidden="true">🍪</span>'
            . '<span class="rpc-btn-cookie-label">' . esc_html($atts['texto']) . '</span>'
            . '</button>'
            . '</div>';
    }

    /**
     * Shortcode [rotar_formulario_optout_arsop]: Formulario interactivo de Exclusión Voluntaria (Opt-Out) y Derechos ARSOP+
     */
    public static function shortcode_optout_form()
    {
        ob_start();
        ?>
        <div class="rpc-arsop-box">
            <div class="rpc-optout-instant">
                <h3>Exclusión Voluntaria Inmediata de Rastreo en este Navegador (Opt-Out)</h3>
                <p>Puedes desactivar de inmediato todas las cookies analíticas y publicitarias en tu navegador actual con un solo clic o revisar tu identificador de auditoría:</p>
                <div class="rpc-optout-instant-actions">
                    <button type="button" class="rpc-btn rpc-btn-reject" onclick="if(window.RotarCMP){window.RotarCMP.rejectAll();alert('Se han desactivado y bloqueado todas las cookies no esenciales en este navegador.');}">
                        Desactivar rastreo y cookies no esenciales ahora
                    </button>
                    <button type="button" class="rpc-btn rpc-btn-outline" onclick="if(window.RotarCMP){window.RotarCMP.openModal();}">
                        Abrir panel de preferencias
                    </button>
                </div>
            </div>

            <hr style="margin:24px 0;border:0;border-top:1px solid #e2e8f0;">

            <h3>Formulario Oficial de Ejercicio de Derechos ARSOP+ (Ley N° 21.719)</h3>
            <p>Como titular de datos personales, tienes derecho a solicitar gratuitamente el <strong>Acceso, Rectificación, Supresión (Cancelación), Oposición, Portabilidad y Bloqueo</strong> de tus datos personales, así como la revocación de tu consentimiento.</p>

            <form id="rpc-arsop-form" class="rpc-arsop-form">
                <div style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">
                    <input type="text" name="rpc_hp_website" tabindex="-1" autocomplete="off">
                </div>
                <div class="rpc-form-grid">
                    <div class="rpc-field">
                        <label for="rpc_req_name">Nombre completo del titular *</label>
                        <input type="text" id="rpc_req_name" name="name" required placeholder="Ej: Juan Pérez Soto">
                    </div>
                    <div class="rpc-field">
                        <label for="rpc_req_rut">RUT o Documento de Identidad (opcional)</label>
                        <input type="text" id="rpc_req_rut" name="rut" placeholder="Ej: 12.345.678-9">
                    </div>
                    <div class="rpc-field">
                        <label for="rpc_req_email">Correo electrónico de contacto *</label>
                        <input type="email" id="rpc_req_email" name="email" required placeholder="nombre@correo.cl">
                    </div>
                    <div class="rpc-field">
                        <label for="rpc_req_type">Derecho que desea ejercer *</label>
                        <select id="rpc_req_type" name="request_type" required>
                            <option value="oposicion_optout">Oposición / Exclusión Voluntaria Comercial (Opt-out)</option>
                            <option value="acceso">Acceso a mis datos personales almacenados</option>
                            <option value="rectificacion">Rectificación de datos inexactos o incompletos</option>
                            <option value="supresion">Supresión / Eliminación de datos personales</option>
                            <option value="portabilidad">Portabilidad de datos en formato estructurado</option>
                            <option value="revocacion">Revocación de consentimiento otorgado</option>
                        </select>
                    </div>
                </div>
                <div class="rpc-field" style="margin-top:14px;">
                    <label for="rpc_req_uuid">ID de Consentimiento de Cookies (autocompletado si existe en tu navegador)</label>
                    <input type="text" id="rpc_req_uuid" name="consent_uuid" readonly placeholder="Sin identificador activo">
                </div>
                <div class="rpc-field" style="margin-top:14px;">
                    <label for="rpc_req_details">Detalle de tu solicitud *</label>
                    <textarea id="rpc_req_details" name="details" rows="4" required placeholder="Indica brevemente el motivo o alcance de tu solicitud..."></textarea>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="rpc-btn rpc-btn-primary">Enviar solicitud formal ARSOP+</button>
                </div>
                <div id="rpc-arsop-feedback" class="rpc-arsop-feedback" hidden></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

