<?php
/**
 * Panel de Administración Integral — Rotar Privacidad & Cookies (Ley N° 21.719 Chile)
 * Pestañas:
 *  1. Resumen & Cumplimiento Ley 21.719
 *  2. Escáner Automático de Cookies y Scripts
 *  3. Diseño del Banner, Textos & Geolocalización IP
 *  4. Asistente Paso a Paso de Textos Legales
 *  5. Integraciones (Google Consent Mode v2, GTM, WooCommerce, Elementor, CF7)
 *  6. Registro Auditable de Consentimientos & Solicitudes ARSOP+ (con exportación CSV)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_Admin
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'handle_form_actions']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('wp_ajax_rpc_update_request_status', [__CLASS__, 'ajax_update_request_status']);
    }

    public static function register_menu()
    {
        add_menu_page(
            'Privacidad & Cookies (Ley 21.719)',
            'Privacidad Chile',
            'manage_options',
            'rotar-privacidad-chile',
            [__CLASS__, 'render_admin_page'],
            'dashicons-shield-alt',
            58
        );
    }

    public static function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'rotar-privacidad-chile') === false) {
            return;
        }

        wp_enqueue_style(
            'rpc-admin-css',
            RPC_URL . 'assets/css/rpc-admin.css',
            [],
            RPC_VERSION
        );

        wp_enqueue_script(
            'rpc-admin-js',
            RPC_URL . 'assets/js/rpc-admin.js',
            [],
            RPC_VERSION,
            true
        );

        wp_localize_script('rpc-admin-js', 'rpcAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('rpc_admin_nonce'),
        ]);
    }

    /**
     * Guarda ajustes generales y procesa exportación CSV de auditoría
     */
    public static function handle_form_actions()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Exportación CSV de auditoría probatoria
        if (!empty($_GET['rpc_export_csv']) && $_GET['rpc_export_csv'] === '1') {
            check_admin_referer('rpc_export_logs_nonce');
            self::export_logs_csv();
        }

        // Guardado de ajustes del plugin
        if (isset($_POST['rpc_save_settings']) && check_admin_referer('rpc_save_settings_action', 'rpc_settings_nonce')) {
            $current = Rotar_Privacidad_Chile::get_settings();

            $checkboxes = [
                'enabled',
                'prior_blocking',
                'block_iframes',
                'geo_api_fallback',
                'show_law_badge',
                'floating_reopen_btn',
                'symmetric_buttons',
                'gcm_v2_enabled',
                'gcm_url_passthrough',
                'gcm_ads_data_redaction',
                'gtm_events_enabled',
                'wc_order_consent_meta',
                'cf7_consent_tag',
                'elementor_forms_consent',
                'wp_consent_api_compat',
                'anonymize_ip_in_logs',
            ];

            // Solo actualizar los checkboxes enviados en la pestaña correspondiente (usando rpc_form_section)
            $section = sanitize_key($_POST['rpc_form_section'] ?? 'all');

            $section_map = [
                'banner' => [
                    'checkboxes' => ['enabled', 'prior_blocking', 'block_iframes', 'geo_api_fallback', 'show_law_badge', 'floating_reopen_btn', 'symmetric_buttons'],
                    'fields'     => ['consent_version', 'consent_expiry_days', 'geo_mode', 'banner_layout', 'floating_position', 'floating_label', 'primary_color', 'accent_color', 'bg_color', 'text_color', 'btn_radius', 'banner_title', 'banner_message', 'banner_message_gdpr', 'btn_accept_text', 'btn_reject_text', 'btn_settings_text', 'btn_save_prefs_text', 'cookie_policy_page_id', 'privacy_policy_page_id', 'optout_page_id'],
                ],
                'integrations' => [
                    'checkboxes' => ['gcm_v2_enabled', 'gcm_url_passthrough', 'gcm_ads_data_redaction', 'gtm_events_enabled', 'wc_order_consent_meta', 'cf7_consent_tag', 'elementor_forms_consent', 'wp_consent_api_compat', 'anonymize_ip_in_logs'],
                    'fields'     => ['gcm_wait_for_update', 'log_retention_months'],
                ],
            ];

            if (isset($section_map[$section])) {
                foreach ($section_map[$section]['checkboxes'] as $chk) {
                    $current[$chk] = !empty($_POST[$chk]) ? 1 : 0;
                }
                foreach ($section_map[$section]['fields'] as $field) {
                    if (isset($_POST[$field])) {
                        $val = wp_unslash($_POST[$field]);
                        if (in_array($field, ['banner_message', 'banner_message_gdpr'], true)) {
                            $current[$field] = sanitize_textarea_field($val);
                        } elseif (strpos($field, '_color') !== false) {
                            $current[$field] = sanitize_hex_color($val) ?: $current[$field];
                        } elseif (strpos($field, '_id') !== false || in_array($field, ['consent_expiry_days', 'btn_radius', 'gcm_wait_for_update', 'log_retention_months'], true)) {
                            $current[$field] = (int) $val;
                        } else {
                            $current[$field] = sanitize_text_field($val);
                        }
                    }
                }
            }

            update_option(RPC_OPT_SETTINGS, $current);

            $redirect_tab = sanitize_key($_POST['rpc_return_tab'] ?? 'dashboard');
            wp_safe_redirect(admin_url('admin.php?page=rotar-privacidad-chile&tab=' . $redirect_tab . '&saved=1'));
            exit;
        }
    }

    private static function export_logs_csv()
    {
        global $wpdb;
        $table = RPC_DB::table_consents();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 10000", ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="auditoria-consentimientos-ley21719-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 para Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, [
            'ID',
            'UUID Consentimiento',
            'Estado',
            'Necesarias',
            'Preferencias',
            'Analitica',
            'Marketing',
            'Pais (GeoIP)',
            'Normativa Aplicada',
            'Version Politica',
            'IP Enmascarada',
            'Firma Criptografica HMAC-SHA256',
            'URL Origen',
            'Fecha y Hora',
        ]);

        foreach ($rows as $r) {
            $safe_row = array_map([__CLASS__, 'sanitize_csv_cell'], [
                $r['id'],
                $r['consent_uuid'],
                $r['status'],
                $r['cat_necessary'] ? 'SI' : 'NO',
                $r['cat_preferences'] ? 'SI' : 'NO',
                $r['cat_analytics'] ? 'SI' : 'NO',
                $r['cat_marketing'] ? 'SI' : 'NO',
                $r['country_code'],
                $r['applicable_law'],
                $r['consent_version'],
                $r['ip_masked'],
                $r['proof_hash'],
                $r['page_url'],
                $r['created_at'],
            ]);
            fputcsv($out, $safe_row);
        }

        fclose($out);
        exit;
    }

    /**
     * Previene ataques de CSV Formula Injection en Excel / LibreOffice
     */
    private static function sanitize_csv_cell($value)
    {
        $str = (string) $value;
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }

    public static function ajax_update_request_status()
    {
        check_ajax_referer('rpc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
        }

        global $wpdb;
        $id = (int) ($_POST['id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? 'resuelta');

        if ($id > 0) {
            $wpdb->update(
                RPC_DB::table_requests(),
                [
                    'status'      => $status,
                    'resolved_at' => ($status === 'resuelta') ? current_time('mysql') : null,
                ],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );
        }

        wp_send_json_success();
    }

    public static function render_admin_page()
    {
        $settings = Rotar_Privacidad_Chile::get_settings();
        $stats    = RPC_DB::get_stats();
        $scan     = RPC_Scanner::get_detected_cookies();
        $wizard   = RPC_Legal_Generator::get_wizard_answers();
        $tab      = sanitize_key($_GET['tab'] ?? 'dashboard');

        $tabs = [
            'dashboard'    => '📊 Resumen & Ley 21.719',
            'scanner'      => '🔍 Escáner de Cookies (' . (int) ($scan['total_cookies'] ?? 0) . ')',
            'banner'       => '🎨 Banner & Geolocalización',
            'wizard'       => '⚖️ Generador de Textos Legales',
            'integrations' => '🔌 Consent Mode v2 & Integraciones',
            'logs'         => '🛡️ Registro de Consentimientos',
            'requests'     => '📩 Solicitudes ARSOP+ / Opt-Out' . ($stats['pending_req'] > 0 ? ' (' . $stats['pending_req'] . ')' : ''),
        ];
        ?>
        <div class="wrap rpc-admin-wrap">
            <!-- Ancla oculta de avisos WP para evitar que plugins externos rompan el encabezado corporativo -->
            <h2 class="rpc-wp-notices-anchor" style="display:none;"></h2>

            <div class="rpc-admin-hero">
                <div>
                    <span class="rpc-admin-eyebrow">PLATAFORMA DE GESTIÓN DE CONSENTIMIENTO (CMP) · CHILE & INTERNACIONAL</span>
                    <h1>Rotar Privacidad & Cookies — Cumplimiento Ley N° 21.719</h1>
                    <p>Escaneo automático de cookies, bloqueo previo de rastreadores, Google Consent Mode v2, textos legales automatizados y registro auditable en servidor propio.</p>
                </div>
                <div class="rpc-hero-status">
                    <span class="rpc-status-pill <?php echo !empty($settings['enabled']) ? 'is-active' : 'is-off'; ?>">
                        <?php echo !empty($settings['enabled']) ? '● Protección Activa' : '○ Banner Pausado'; ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($_GET['saved'])) : ?>
                <div class="rpc-notice-success">✅ La configuración se ha guardado y aplicado correctamente.</div>
            <?php endif; ?>

            <nav class="rpc-tabs-nav">
                <?php foreach ($tabs as $t_key => $t_label) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rotar-privacidad-chile&tab=' . $t_key)); ?>"
                       class="rpc-tab-link <?php echo $tab === $t_key ? 'is-active' : ''; ?>">
                        <?php echo esc_html($t_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="rpc-tab-panel">
                <?php
                switch ($tab) {
                    case 'scanner':
                        self::render_tab_scanner($scan);
                        break;
                    case 'banner':
                        self::render_tab_banner($settings);
                        break;
                    case 'wizard':
                        self::render_tab_wizard($wizard, $settings);
                        break;
                    case 'integrations':
                        self::render_tab_integrations($settings);
                        break;
                    case 'logs':
                        self::render_tab_logs($stats);
                        break;
                    case 'requests':
                        self::render_tab_requests();
                        break;
                    default:
                        self::render_tab_dashboard($settings, $stats, $scan);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private static function render_tab_dashboard($settings, $stats, $scan)
    {
        $checklist = [
            [
                'ok'    => !empty($settings['enabled']) && !empty($settings['prior_blocking']),
                'title' => 'Bloqueo Previo de Cookies No Esenciales (Art. 12 Ley N° 21.719)',
                'desc'  => 'Impide cargar píxeles publicitarios y scripts de rastreo antes del acto afirmativo del visitante.',
            ],
            [
                'ok'    => !empty($settings['symmetric_buttons']),
                'title' => 'Simetría Visual Estricta Aceptar / Rechazar (Sin Dark Patterns)',
                'desc'  => 'Ambos botones poseen igual jerarquía, tamaño y visibilidad en la primera capa del banner.',
            ],
            [
                'ok'    => !empty($settings['gcm_v2_enabled']),
                'title' => 'Google Consent Mode v2 Activo',
                'desc'  => 'Envía señales ad_storage, ad_user_data, ad_personalization y analytics_storage compatibles con Google Ads y GA4.',
            ],
            [
                'ok'    => ($scan['total_cookies'] ?? 0) > 0,
                'title' => 'Inventario Técnico de Cookies Escaneado (' . (int) ($scan['total_cookies'] ?? 0) . ' detectadas)',
                'desc'  => 'Último análisis automático del sitio: ' . esc_html($scan['last_scan'] ?? 'Pendiente'),
            ],
            [
                'ok'    => !empty($settings['cookie_policy_page_id']) && get_post_status((int) $settings['cookie_policy_page_id']) === 'publish'
                           && !empty($settings['privacy_policy_page_id']) && get_post_status((int) $settings['privacy_policy_page_id']) === 'publish'
                           && !empty($settings['optout_page_id']) && get_post_status((int) $settings['optout_page_id']) === 'publish',
                'title' => 'Páginas Legales Publicadas (Cookies, Privacidad y Opt-out / ARSOP+)',
                'desc'  => (!empty($settings['cookie_policy_page_id']) && !empty($settings['privacy_policy_page_id']) && !empty($settings['optout_page_id']))
                    ? 'Las 3 páginas legales obligatorias están publicadas y enlazadas activamente en el Banner de Cookies. Puedes personalizarlas en "Generador de Textos Legales".'
                    : 'Genera o actualiza las 3 páginas legales con un clic desde la pestaña "Generador de Textos Legales".',
            ],
        ];
        ?>
        <div class="rpc-kpi-grid">
            <div class="rpc-kpi-card">
                <span class="rpc-kpi-label">Consentimientos Registrados</span>
                <strong class="rpc-kpi-value"><?php echo number_format_i18n($stats['total']); ?></strong>
                <span class="rpc-kpi-sub">Con firma HMAC-SHA256 en servidor</span>
            </div>
            <div class="rpc-kpi-card">
                <span class="rpc-kpi-label">Tasa de Aceptación (Total/Parcial)</span>
                <strong class="rpc-kpi-value"><?php echo esc_html($stats['accept_rate']); ?>%</strong>
                <span class="rpc-kpi-sub"><?php echo number_format_i18n($stats['accept_all']); ?> completas · <?php echo number_format_i18n($stats['custom']); ?> granulares</span>
            </div>
            <div class="rpc-kpi-card">
                <span class="rpc-kpi-label">Cookies & Scripts Detectados</span>
                <strong class="rpc-kpi-value"><?php echo (int) ($scan['total_cookies'] ?? 0); ?></strong>
                <span class="rpc-kpi-sub">En <?php echo (int) ($scan['total_services'] ?? 0); ?> servicios activos</span>
            </div>
            <div class="rpc-kpi-card">
                <span class="rpc-kpi-label">Solicitudes ARSOP+ Pendientes</span>
                <strong class="rpc-kpi-value"><?php echo (int) $stats['pending_req']; ?></strong>
                <span class="rpc-kpi-sub">Derechos del titular y Opt-out</span>
            </div>
        </div>

        <div class="rpc-two-cols">
            <div class="rpc-card">
                <h2>Lista de Verificación de Cumplimiento — Ley N° 21.719 (Chile)</h2>
                <ul class="rpc-checklist">
                    <?php foreach ($checklist as $item) : ?>
                        <li>
                            <span class="rpc-check-icon <?php echo $item['ok'] ? 'is-ok' : 'is-warn'; ?>">
                                <?php echo $item['ok'] ? '✔' : '!'; ?>
                            </span>
                            <div>
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <p><?php echo esc_html($item['desc']); ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="rpc-card">
                <h2>Shortcodes Disponibles para tus Páginas</h2>
                <p>Puedes insertar estos shortcodes en Elementor, Gutenberg o cualquier plantilla (el asistente legal los inserta automáticamente):</p>
                <div class="rpc-shortcode-list">
                    <div>
                        <code>[rotar_tabla_cookies]</code>
                        <span>Muestra la tabla categorizada en vivo con todas las cookies detectadas por el escáner.</span>
                    </div>
                    <div>
                        <code>[rotar_preferencias_cookies_btn]</code>
                        <span>Inserta un botón para reabrir el Centro de Preferencias y revocar el consentimiento.</span>
                    </div>
                    <div>
                        <code>[rotar_formulario_optout_arsop]</code>
                        <span>Inserta el botón de exclusión voluntaria inmediata (Opt-out) y el formulario oficial ARSOP+.</span>
                    </div>
                    <div>
                        <code>[rpc_privacy_consent]</code>
                        <span>Etiqueta para formularios de <strong>Contact Form 7</strong> con casilla obligatoria de aceptación de privacidad.</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_tab_scanner($scan)
    {
        $items = $scan['items'] ?? [];
        $cat_names = [
            'necessary'   => 'Necesaria (Técnica)',
            'preferences' => 'Preferencias / Funcional',
            'analytics'   => 'Analítica / Estadística',
            'marketing'   => 'Marketing / Publicidad',
        ];
        ?>
        <div class="rpc-card">
            <div class="rpc-card-header-flex">
                <div>
                    <h2>Escáner Automático de Cookies, Scripts y Servicios de Terceros</h2>
                    <p>Analiza plugins activos, cabeceras HTTP, plantillas de Elementor y scripts encolados (Google Analytics, GTM, Meta Pixel, YouTube, WooCommerce, Transbank, Clientify, etc.). Último escaneo: <strong id="rpc-last-scan-date"><?php echo esc_html($scan['last_scan'] ?? 'Nunca'); ?></strong></p>
                </div>
                <button type="button" id="rpc-btn-run-scan" class="rpc-admin-btn rpc-admin-btn-primary">
                    🔍 Escanear todo el sitio ahora
                </button>
            </div>

            <div id="rpc-scan-progress" class="rpc-scan-progress" hidden>
                Analizando páginas del sitio web, cabeceras HTTP, plugins activos y scripts de terceros...
            </div>

            <table class="rpc-admin-table">
                <thead>
                    <tr>
                        <th>Cookie / Almacenamiento</th>
                        <th>Servicio Detectado</th>
                        <th>Proveedor</th>
                        <th>Categoría</th>
                        <th>Duración</th>
                        <th>Origen de Detección</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="rpc-scanner-tbody">
                    <?php if (empty($items)) : ?>
                        <tr><td colspan="7">No hay cookies registradas aún. Haz clic en "Escanear todo el sitio ahora".</td></tr>
                    <?php else : ?>
                        <?php foreach ($items as $ck) : ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html($ck['name']); ?></code>
                                    <div class="rpc-row-sub"><?php echo esc_html($ck['purpose']); ?></div>
                                </td>
                                <td><strong><?php echo esc_html($ck['service']); ?></strong></td>
                                <td><?php echo esc_html($ck['provider']); ?></td>
                                <td><span class="rpc-cat-pill cat-<?php echo esc_attr($ck['category']); ?>"><?php echo esc_html($cat_names[$ck['category']] ?? $ck['category']); ?></span></td>
                                <td><?php echo esc_html($ck['duration']); ?></td>
                                <td><small><?php echo esc_html($ck['source'] ?? 'Escáner'); ?></small></td>
                                <td>
                                    <button type="button" class="button-link rpc-delete-cookie" data-id="<?php echo esc_attr($ck['id']); ?>" style="color:#b91c1c;">Eliminar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="rpc-card" style="margin-top:20px;">
            <h3>Declarar Cookie o Script Personalizado Manualmente</h3>
            <form id="rpc-add-cookie-form" class="rpc-grid-form">
                <div>
                    <label>Nombre de la Cookie *</label>
                    <input type="text" name="name" required placeholder="Ej: _custom_session">
                </div>
                <div>
                    <label>Servicio</label>
                    <input type="text" name="service" placeholder="Ej: ERP / Chat Corporativo">
                </div>
                <div>
                    <label>Proveedor</label>
                    <input type="text" name="provider" placeholder="Ej: Propio o Proveedor S.A.">
                </div>
                <div>
                    <label>Categoría</label>
                    <select name="category">
                        <option value="necessary">Necesaria (Siempre activa)</option>
                        <option value="preferences">Preferencias / Funcional</option>
                        <option value="analytics">Analítica / Estadística</option>
                        <option value="marketing">Marketing / Publicidad</option>
                    </select>
                </div>
                <div>
                    <label>Duración</label>
                    <input type="text" name="duration" placeholder="Ej: 1 año / Sesión">
                </div>
                <div>
                    <label>Finalidad informada al usuario</label>
                    <input type="text" name="purpose" required placeholder="Describe para qué se utiliza esta cookie...">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="rpc-admin-btn">Agregar al inventario oficial</button>
                </div>
            </form>
        </div>
        <?php
    }

    private static function render_tab_banner($settings)
    {
        $pages = get_pages(['post_status' => ['publish', 'draft']]);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('rpc_save_settings_action', 'rpc_settings_nonce'); ?>
            <input type="hidden" name="rpc_form_section" value="banner">
            <input type="hidden" name="rpc_return_tab" value="banner">

            <div class="rpc-two-cols">
                <div class="rpc-card">
                    <h2>Estado General, Bloqueo Previo y Geolocalización IP</h2>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                        <span><strong>Activar Banner y Centro de Preferencias en el sitio web</strong></span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="prior_blocking" value="1" <?php checked(!empty($settings['prior_blocking'])); ?>>
                        <span><strong>Activar Bloqueo Previo Automático de Scripts (Requisito Ley N° 21.719 y RGPD)</strong> — Retiene píxeles y analítica hasta que el visitante acepte.</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="block_iframes" value="1" <?php checked(!empty($settings['block_iframes'])); ?>>
                        <span><strong>Bloquear iframes externos (YouTube / Google Maps) con aviso de desbloqueo en 1 clic</strong></span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="symmetric_buttons" value="1" <?php checked(!empty($settings['symmetric_buttons'])); ?>>
                        <span><strong>Simetría estricta en botones Aceptar / Rechazar</strong> (Recomendado para evitar sanciones por patrones oscuros).</span>
                    </label>

                    <div class="rpc-field-group">
                        <label>Modo de Geolocalización por IP y Ley Aplicable</label>
                        <select name="geo_mode">
                            <option value="worldwide_strict" <?php selected($settings['geo_mode'], 'worldwide_strict'); ?>>Estándar Chileno Ley 21.719 Global (Recomendado para empresas con domicilio en Chile — Art. 2)</option>
                            <option value="auto_law_by_ip" <?php selected($settings['geo_mode'], 'auto_law_by_ip'); ?>>Adaptación Dinámica por IP (Opt-in en Chile/UE/Brasil · Opt-out en EE.UU./Resto)</option>
                            <option value="chile_gdpr_only" <?php selected($settings['geo_mode'], 'chile_gdpr_only'); ?>>Mostrar Banner solo a visitantes de Chile, Unión Europea/UK y Brasil</option>
                        </select>
                    </div>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="geo_api_fallback" value="1" <?php checked(!empty($settings['geo_api_fallback'])); ?>>
                        <span>Usar resolución GeoIP externa de respaldo cuando no existan cabeceras Cloudflare / WooCommerce MaxMind.</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="show_law_badge" value="1" <?php checked(!empty($settings['show_law_badge'])); ?>>
                        <span>Mostrar insignia dinámica de la ley detectada (ej. <code>Ley N° 21.719 (Chile)</code> o <code>RGPD (Europa)</code>).</span>
                    </label>

                    <div class="rpc-inline-fields">
                        <div class="rpc-field-group">
                            <label>Versión de Política (cambiar fuerza re-consentimiento)</label>
                            <input type="text" name="consent_version" value="<?php echo esc_attr($settings['consent_version']); ?>">
                        </div>
                        <div class="rpc-field-group">
                            <label>Validez del consentimiento (días)</label>
                            <input type="number" name="consent_expiry_days" min="30" max="365" value="<?php echo (int) $settings['consent_expiry_days']; ?>">
                        </div>
                    </div>
                </div>

                <div class="rpc-card">
                    <h2>Apariencia Visual Adaptada a tu Web</h2>

                    <div class="rpc-inline-fields">
                        <div class="rpc-field-group">
                            <label>Diseño / Posición del Banner</label>
                            <select name="banner_layout">
                                <option value="bar_bottom" <?php selected($settings['banner_layout'], 'bar_bottom'); ?>>Barra inferior de ancho completo</option>
                                <option value="box_left" <?php selected($settings['banner_layout'], 'box_left'); ?>>Tarjeta flotante inferior izquierda</option>
                                <option value="box_right" <?php selected($settings['banner_layout'], 'box_right'); ?>>Tarjeta flotante inferior derecha</option>
                                <option value="modal_center" <?php selected($settings['banner_layout'], 'modal_center'); ?>>Cuadro central destacado</option>
                            </select>
                        </div>
                        <div class="rpc-field-group">
                            <label>Posición del botón flotante de reapertura</label>
                            <select name="floating_position">
                                <option value="left" <?php selected($settings['floating_position'], 'left'); ?>>Inferior Izquierda (evita botón WhatsApp)</option>
                                <option value="right" <?php selected($settings['floating_position'], 'right'); ?>>Inferior Derecha</option>
                            </select>
                        </div>
                    </div>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="floating_reopen_btn" value="1" <?php checked(!empty($settings['floating_reopen_btn'])); ?>>
                        <span>Mostrar botón flotante en esquina tras responder (Desmarcado recomendado: al aceptar o rechazar desaparece por completo; el usuario puede cambiar preferencias desde el enlace en la Política de Cookies o pie de página).</span>
                    </label>

                    <div class="rpc-color-grid">
                        <div>
                            <label>Color Primario</label>
                            <input type="color" name="primary_color" value="<?php echo esc_attr($settings['primary_color']); ?>">
                        </div>
                        <div>
                            <label>Color Acento</label>
                            <input type="color" name="accent_color" value="<?php echo esc_attr($settings['accent_color']); ?>">
                        </div>
                        <div>
                            <label>Fondo Banner</label>
                            <input type="color" name="bg_color" value="<?php echo esc_attr($settings['bg_color']); ?>">
                        </div>
                        <div>
                            <label>Color Texto</label>
                            <input type="color" name="text_color" value="<?php echo esc_attr($settings['text_color']); ?>">
                        </div>
                        <div>
                            <label>Radio Bordes (px)</label>
                            <input type="number" name="btn_radius" min="0" max="30" value="<?php echo (int) $settings['btn_radius']; ?>">
                        </div>
                    </div>

                    <div class="rpc-field-group">
                        <label>Título del Banner</label>
                        <input type="text" name="banner_title" value="<?php echo esc_attr($settings['banner_title']); ?>">
                    </div>

                    <div class="rpc-field-group">
                        <label>Mensaje Principal (Chile / Ley N° 21.719)</label>
                        <textarea name="banner_message" rows="3"><?php echo esc_textarea($settings['banner_message']); ?></textarea>
                    </div>

                    <div class="rpc-field-group">
                        <label>Mensaje Alternativo Automático para Visitantes de Europa (RGPD)</label>
                        <textarea name="banner_message_gdpr" rows="2"><?php echo esc_textarea($settings['banner_message_gdpr']); ?></textarea>
                    </div>

                    <div class="rpc-inline-fields">
                        <div class="rpc-field-group">
                            <label>Texto Botón Aceptar</label>
                            <input type="text" name="btn_accept_text" value="<?php echo esc_attr($settings['btn_accept_text']); ?>">
                        </div>
                        <div class="rpc-field-group">
                            <label>Texto Botón Rechazar</label>
                            <input type="text" name="btn_reject_text" value="<?php echo esc_attr($settings['btn_reject_text']); ?>">
                        </div>
                        <div class="rpc-field-group">
                            <label>Texto Botón Configurar</label>
                            <input type="text" name="btn_settings_text" value="<?php echo esc_attr($settings['btn_settings_text']); ?>">
                        </div>
                    </div>

                    <input type="hidden" name="btn_save_prefs_text" value="<?php echo esc_attr($settings['btn_save_prefs_text']); ?>">
                    <input type="hidden" name="floating_label" value="<?php echo esc_attr($settings['floating_label']); ?>">

                    <hr style="margin:16px 0;border:0;border-top:1px solid #e2e8f0;">
                    <h3>Páginas Legales Enlazadas en el Banner</h3>
                    <div class="rpc-inline-fields">
                        <div class="rpc-field-group">
                            <label>Página Política de Cookies</label>
                            <select name="cookie_policy_page_id">
                                <option value="0">— Sin asignar —</option>
                                <?php foreach ($pages as $p) : ?>
                                    <option value="<?php echo (int) $p->ID; ?>" <?php selected((int) $settings['cookie_policy_page_id'], (int) $p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rpc-field-group">
                            <label>Página Declaración de Privacidad</label>
                            <select name="privacy_policy_page_id">
                                <option value="0">— Sin asignar —</option>
                                <?php foreach ($pages as $p) : ?>
                                    <option value="<?php echo (int) $p->ID; ?>" <?php selected((int) $settings['privacy_policy_page_id'], (int) $p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rpc-field-group">
                            <label>Página Opt-out / Derechos ARSOP+</label>
                            <select name="optout_page_id">
                                <option value="0">— Sin asignar —</option>
                                <?php foreach ($pages as $p) : ?>
                                    <option value="<?php echo (int) $p->ID; ?>" <?php selected((int) $settings['optout_page_id'], (int) $p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:18px;">
                <button type="submit" name="rpc_save_settings" value="1" class="rpc-admin-btn rpc-admin-btn-primary">
                    💾 Guardar Diseño y Reglas del Banner
                </button>
            </div>
        </form>
        <?php
    }

    private static function render_tab_wizard($wizard, $settings)
    {
        ?>
        <div class="rpc-card">
            <h2>Asistente Paso a Paso: Generador Automático de Textos Legales (Ley N° 21.719)</h2>
            <p>Responde las preguntas sobre tu empresa y operación digital. El asistente redactará, publicará/actualizará y vinculará automáticamente tu <strong>Política de Cookies</strong>, tu <strong>Declaración de Privacidad</strong> y tu página de <strong>Derechos ARSOP+ / Exclusión Voluntaria (Opt-out)</strong>.</p>

            <div class="rpc-wizard-steps-indicator">
                <span class="rpc-wstep is-current" data-step-ind="1">1. Identidad del Responsable</span>
                <span class="rpc-wstep" data-step-ind="2">2. Actividades y E-Commerce</span>
                <span class="rpc-wstep" data-step-ind="3">3. Documentos a Generar</span>
            </div>

            <form id="rpc-legal-wizard-form">
                <!-- PASO 1 -->
                <div class="rpc-wizard-step" data-step="1">
                    <h3>Paso 1: Datos Legales del Responsable del Tratamiento en Chile</h3>
                    <div class="rpc-grid-form">
                        <div>
                            <label>Razón Social o Nombre Legal del Responsable *</label>
                            <input type="text" name="company_legal_name" required value="<?php echo esc_attr($wizard['company_legal_name']); ?>">
                        </div>
                        <div>
                            <label>Nombre Comercial / Marca</label>
                            <input type="text" name="company_trade_name" value="<?php echo esc_attr($wizard['company_trade_name']); ?>">
                        </div>
                        <div>
                            <label>RUT de la Empresa *</label>
                            <input type="text" name="company_rut" required value="<?php echo esc_attr($wizard['company_rut']); ?>" placeholder="Ej: 76.123.456-7">
                        </div>
                        <div>
                            <label>Domicilio Legal en Chile *</label>
                            <input type="text" name="company_address" required value="<?php echo esc_attr($wizard['company_address']); ?>">
                        </div>
                        <div>
                            <label>Cargo o Nombre del Encargado / Contacto de Datos (DPO)</label>
                            <input type="text" name="dpo_name" value="<?php echo esc_attr($wizard['dpo_name']); ?>">
                        </div>
                        <div>
                            <label>Correo Oficial para Ejercicio de Derechos ARSOP+ *</label>
                            <input type="email" name="dpo_email" required value="<?php echo esc_attr($wizard['dpo_email']); ?>">
                        </div>
                        <div>
                            <label>Teléfono de Contacto</label>
                            <input type="text" name="dpo_phone" value="<?php echo esc_attr($wizard['dpo_phone']); ?>">
                        </div>
                    </div>
                    <div class="rpc-wizard-nav">
                        <button type="button" class="rpc-admin-btn rpc-admin-btn-primary rpc-next-step" data-next="2">Siguiente paso &rarr;</button>
                    </div>
                </div>

                <!-- PASO 2 -->
                <div class="rpc-wizard-step" data-step="2" hidden>
                    <h3>Paso 2: Características y Tratamientos de Datos en tu Web</h3>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="has_ecommerce" value="1" <?php checked(!empty($wizard['has_ecommerce'])); ?>>
                        <span><strong>Tienda Online / WooCommerce activa</strong> (procesamiento de compras, despacho y facturación electrónica SII).</span>
                    </label>
                    <div class="rpc-grid-form" style="margin: 10px 0 16px;">
                        <div>
                            <label>Medios de pago utilizados</label>
                            <input type="text" name="payment_gateways" value="<?php echo esc_attr($wizard['payment_gateways']); ?>">
                        </div>
                        <div>
                            <label>Operadores logísticos / despacho</label>
                            <input type="text" name="shipping_partners" value="<?php echo esc_attr($wizard['shipping_partners']); ?>">
                        </div>
                    </div>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="has_contact_forms" value="1" <?php checked(!empty($wizard['has_contact_forms'])); ?>>
                        <span>Formularios de contacto o solicitud de cotizaciones técnicas (Elementor / Contact Form 7).</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="has_whatsapp_chat" value="1" <?php checked(!empty($wizard['has_whatsapp_chat'])); ?>>
                        <span>Atención comercial mediante botón flotante de WhatsApp.</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="has_newsletter_crm" value="1" <?php checked(!empty($wizard['has_newsletter_crm'])); ?>>
                        <span>Uso de CRM (ej. Clientify) o envío de comunicaciones comerciales con opción de baja.</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="has_analytics_ads" value="1" <?php checked(!empty($wizard['has_analytics_ads'])); ?>>
                        <span>Herramientas de analítica y publicidad sujetas a consentimiento (Google Analytics 4, Google Ads, Meta Pixel).</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="international_transfer" value="1" <?php checked(!empty($wizard['international_transfer'])); ?>>
                        <span>Infraestructura cloud o proveedores tecnológicos con servidores fuera de Chile (Art. 27 Ley N° 21.719).</span>
                    </label>

                    <div class="rpc-field-group" style="max-width:320px;margin-top:12px;">
                        <label>Años de retención tributaria SII (por defecto 6 años)</label>
                        <input type="number" name="retention_years_tax" min="1" max="15" value="<?php echo (int) $wizard['retention_years_tax']; ?>">
                    </div>

                    <div class="rpc-wizard-nav">
                        <button type="button" class="rpc-admin-btn rpc-prev-step" data-prev="1">&larr; Anterior</button>
                        <button type="button" class="rpc-admin-btn rpc-admin-btn-primary rpc-next-step" data-next="3">Siguiente paso &rarr;</button>
                    </div>
                </div>

                <!-- PASO 3 -->
                <div class="rpc-wizard-step" data-step="3" hidden>
                    <h3>Paso 3: Selecciona las Páginas Legales a Crear o Actualizar</h3>
                    <p>Si las páginas ya existen, su contenido será actualizado con las cláusulas vigentes y la tabla automática de cookies.</p>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="gen_cookie_policy" value="1" checked>
                        <span><strong>Política de Cookies</strong> (incluye inventario dinámico del escáner y botón de revocación).</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="gen_privacy_policy" value="1" checked>
                        <span><strong>Declaración de Privacidad y Protección de Datos Personales</strong> (adaptada a la Ley N° 21.719 de Chile).</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="gen_optout_page" value="1" checked>
                        <span><strong>Página de Derechos ARSOP+ y Aviso de Exclusión Voluntaria (Opt-Out)</strong> (con formulario auditable integrado).</span>
                    </label>
                    <label class="rpc-check-row">
                        <input type="checkbox" name="publish_immediately" value="1" <?php checked(!empty($wizard['publish_immediately'])); ?>>
                        <span>Publicar páginas inmediatamente y enlazarlas automáticamente en el Banner de Cookies.</span>
                    </label>

                    <div class="rpc-wizard-nav">
                        <button type="button" class="rpc-admin-btn rpc-prev-step" data-prev="2">&larr; Anterior</button>
                        <button type="submit" id="rpc-btn-generate-legal" class="rpc-admin-btn rpc-admin-btn-primary">
                            ⚖️ Redactar y Publicar Páginas Legales Ahora
                        </button>
                    </div>
                </div>
            </form>

            <div id="rpc-wizard-result" class="rpc-wizard-result" hidden></div>
        </div>
        <?php
    }

    private static function render_tab_integrations($settings)
    {
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('rpc_save_settings_action', 'rpc_settings_nonce'); ?>
            <input type="hidden" name="rpc_form_section" value="integrations">
            <input type="hidden" name="rpc_return_tab" value="integrations">

            <div class="rpc-two-cols">
                <div class="rpc-card">
                    <h2>Google Consent Mode v2 & Google Tag Manager</h2>
                    <p>Obligatorio para mantener medición de conversiones y audiencias en <strong>Google Ads</strong> y <strong>Google Analytics 4</strong> respetando la elección del visitante.</p>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="gcm_v2_enabled" value="1" <?php checked(!empty($settings['gcm_v2_enabled'])); ?>>
                        <span><strong>Activar Google Consent Mode v2 Nativo</strong> (<code>ad_storage</code>, <code>ad_user_data</code>, <code>ad_personalization</code>, <code>analytics_storage</code>).</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="gcm_url_passthrough" value="1" <?php checked(!empty($settings['gcm_url_passthrough'])); ?>>
                        <span><strong>Activar URL Passthrough</strong> (conserva atribución de clics <code>gclid</code> en URL sin escribir cookies cuando el usuario rechaza).</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="gcm_ads_data_redaction" value="1" <?php checked(!empty($settings['gcm_ads_data_redaction'])); ?>>
                        <span><strong>Activar Ads Data Redaction</strong> (elimina identificadores publicitarios cuando <code>ad_storage</code> es denegado).</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="gtm_events_enabled" value="1" <?php checked(!empty($settings['gtm_events_enabled'])); ?>>
                        <span><strong>Enviar eventos automáticos a Google Tag Manager (<code>dataLayer</code>)</strong>: emite <code>rpc_consent_init</code> y <code>rpc_consent_update</code>.</span>
                    </label>

                    <div class="rpc-field-group" style="max-width:280px;">
                        <label>Tiempo de espera GTM <code>wait_for_update</code> (ms)</label>
                        <input type="number" name="gcm_wait_for_update" min="0" max="5000" value="<?php echo (int) $settings['gcm_wait_for_update']; ?>">
                    </div>
                </div>

                <div class="rpc-card">
                    <h2>Ecosistema WordPress, WooCommerce, Elementor y Auditoría</h2>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="wc_order_consent_meta" value="1" <?php checked(!empty($settings['wc_order_consent_meta'])); ?>>
                        <span><strong>WooCommerce (HPOS & Bloques):</strong> Adjuntar automáticamente el UUID de consentimiento en la ficha de cada pedido.</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="elementor_forms_consent" value="1" <?php checked(!empty($settings['elementor_forms_consent'])); ?>>
                        <span><strong>Elementor Forms:</strong> Registrar el ID de consentimiento del visitante en los envíos de formularios.</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="cf7_consent_tag" value="1" <?php checked(!empty($settings['cf7_consent_tag'])); ?>>
                        <span><strong>Contact Form 7:</strong> Habilitar la etiqueta <code>[rpc_privacy_consent]</code> para casillas legales.</span>
                    </label>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="wp_consent_api_compat" value="1" <?php checked(!empty($settings['wp_consent_api_compat'])); ?>>
                        <span><strong>WP Consent Level API:</strong> Sincronizar permisos con PixelYourSite, GTM4WP, Site Kit y plugins compatibles.</span>
                    </label>

                    <hr style="margin:16px 0;border:0;border-top:1px solid #e2e8f0;">
                    <h3>Privacidad de los Registros del Servidor</h3>

                    <label class="rpc-check-row">
                        <input type="checkbox" name="anonymize_ip_in_logs" value="1" <?php checked(!empty($settings['anonymize_ip_in_logs'])); ?>>
                        <span><strong>Anonimizar/Enmascarar último octeto de la IP</strong> en la base de datos (minimización de datos Art. 3 Ley 21.719).</span>
                    </label>

                    <div class="rpc-field-group" style="max-width:280px;">
                        <label>Retención del historial de consentimientos (meses)</label>
                        <input type="number" name="log_retention_months" min="6" max="120" value="<?php echo (int) $settings['log_retention_months']; ?>">
                    </div>
                </div>
            </div>

            <div style="margin-top:18px;">
                <button type="submit" name="rpc_save_settings" value="1" class="rpc-admin-btn rpc-admin-btn-primary">
                    💾 Guardar Integraciones
                </button>
            </div>
        </form>
        <?php
    }

    private static function render_tab_logs($stats)
    {
        $search = sanitize_text_field(wp_unslash($_GET['s_uuid'] ?? ''));
        $paged  = max(1, (int) ($_GET['paged'] ?? 1));
        $limit  = 30;
        $offset = ($paged - 1) * $limit;
        $logs   = RPC_DB::get_logs($limit, $offset, $search);

        $export_url = wp_nonce_url(
            admin_url('admin.php?page=rotar-privacidad-chile&tab=logs&rpc_export_csv=1'),
            'rpc_export_logs_nonce'
        );
        ?>
        <div class="rpc-card">
            <div class="rpc-card-header-flex">
                <div>
                    <h2>Historial Probatorio de Consentimientos en Servidor Propio</h2>
                    <p>Cada decisión queda respaldada con identificador UUID, versión legal, país y firma criptográfica HMAC-SHA256 ante eventuales fiscalizaciones de la Agencia de Protección de Datos Personales.</p>
                </div>
                <a href="<?php echo esc_url($export_url); ?>" class="rpc-admin-btn rpc-admin-btn-primary">
                    📥 Exportar Auditoría CSV
                </a>
            </div>

            <form method="get" style="margin-bottom:16px;display:flex;gap:8px;max-width:460px;">
                <input type="hidden" name="page" value="rotar-privacidad-chile">
                <input type="hidden" name="tab" value="logs">
                <input type="search" name="s_uuid" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por UUID, IP o país..." style="flex:1;">
                <button type="submit" class="button">Buscar</button>
            </form>

            <table class="rpc-admin-table">
                <thead>
                    <tr>
                        <th>Fecha / Hora</th>
                        <th>UUID Consentimiento</th>
                        <th>Decisión</th>
                        <th>Categorías Autorizadas</th>
                        <th>País / Ley</th>
                        <th>IP Enmascarada</th>
                        <th>Firma HMAC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr><td colspan="7">Aún no se registran interacciones de visitantes con el banner.</td></tr>
                    <?php else : ?>
                        <?php foreach ($logs as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row['created_at']); ?></td>
                                <td><code><?php echo esc_html($row['consent_uuid']); ?></code></td>
                                <td><strong><?php echo esc_html($row['status']); ?></strong></td>
                                <td>
                                    <span class="rpc-mini-tag is-on">Necesarias</span>
                                    <span class="rpc-mini-tag <?php echo $row['cat_preferences'] ? 'is-on' : 'is-off'; ?>">Preferencias</span>
                                    <span class="rpc-mini-tag <?php echo $row['cat_analytics'] ? 'is-on' : 'is-off'; ?>">Analítica</span>
                                    <span class="rpc-mini-tag <?php echo $row['cat_marketing'] ? 'is-on' : 'is-off'; ?>">Marketing</span>
                                </td>
                                <td><?php echo esc_html($row['country_code'] . ' · ' . $row['applicable_law']); ?> (v<?php echo esc_html($row['consent_version']); ?>)</td>
                                <td><code><?php echo esc_html($row['ip_masked']); ?></code></td>
                                <td><code title="<?php echo esc_attr($row['proof_hash']); ?>"><?php echo esc_html(substr($row['proof_hash'], 0, 14)); ?>…</code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_tab_requests()
    {
        global $wpdb;
        $table = RPC_DB::table_requests();
        $rows = [];
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        }
        ?>
        <div class="rpc-card">
            <h2>Gestión de Solicitudes de Derechos ARSOP+ y Exclusión Voluntaria (Opt-Out)</h2>
            <p>Solicitudes enviadas por titulares a través del formulario generado con <code>[rotar_formulario_optout_arsop]</code>.</p>

            <table class="rpc-admin-table">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Derecho Solicitado</th>
                        <th>Titular / RUT</th>
                        <th>Email</th>
                        <th>Detalle</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="7">No hay solicitudes ARSOP+ registradas por el momento.</td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $r) : ?>
                            <tr>
                                <td><code><?php echo esc_html($r['request_code']); ?></code></td>
                                <td><?php echo esc_html($r['created_at']); ?></td>
                                <td><strong><?php echo esc_html(strtoupper($r['request_type'])); ?></strong></td>
                                <td><?php echo esc_html($r['requester_name']); ?><?php echo $r['requester_rut'] ? '<br><small>' . esc_html($r['requester_rut']) . '</small>' : ''; ?></td>
                                <td><a href="mailto:<?php echo esc_attr($r['requester_email']); ?>"><?php echo esc_html($r['requester_email']); ?></a></td>
                                <td><?php echo esc_html($r['details']); ?></td>
                                <td>
                                    <select class="rpc-req-status-select" data-id="<?php echo (int) $r['id']; ?>">
                                        <option value="pendiente" <?php selected($r['status'], 'pendiente'); ?>>⏳ Pendiente</option>
                                        <option value="en_proceso" <?php selected($r['status'], 'en_proceso'); ?>>🔄 En revisión</option>
                                        <option value="resuelta" <?php selected($r['status'], 'resuelta'); ?>>✅ Resuelta</option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

