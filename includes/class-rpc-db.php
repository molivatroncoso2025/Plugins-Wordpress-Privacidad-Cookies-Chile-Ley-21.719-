<?php
/**
 * Capa de Base de Datos: Registro Auditable de Consentimientos y Solicitudes ARSOP+ / Opt-Out
 * Cumplimiento Art. 12 y Art. 14 ter Ley N° 21.719 (Chile) — Carga de la prueba del Responsable
 */

if (!defined('ABSPATH')) {
    exit;
}

class RPC_DB
{
    const DB_VERSION = '1.0.0';
    const DB_VERSION_KEY = 'rpc_ley21719_db_version';

    public static function table_consents()
    {
        global $wpdb;
        return $wpdb->prefix . 'rotar_privacy_consents';
    }

    public static function table_requests()
    {
        global $wpdb;
        return $wpdb->prefix . 'rotar_privacy_requests';
    }

    public static function maybe_install()
    {
        if (get_option(self::DB_VERSION_KEY) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $consents_table = self::table_consents();
        $requests_table = self::table_requests();

        $sql_consents = "CREATE TABLE {$consents_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            consent_uuid VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'custom',
            cat_necessary TINYINT(1) NOT NULL DEFAULT 1,
            cat_preferences TINYINT(1) NOT NULL DEFAULT 0,
            cat_analytics TINYINT(1) NOT NULL DEFAULT 0,
            cat_marketing TINYINT(1) NOT NULL DEFAULT 0,
            country_code VARCHAR(8) NOT NULL DEFAULT 'CL',
            applicable_law VARCHAR(40) NOT NULL DEFAULT 'CHL_21719',
            consent_version VARCHAR(20) NOT NULL DEFAULT '1.0',
            ip_masked VARCHAR(64) NOT NULL DEFAULT '',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            user_agent VARCHAR(300) NOT NULL DEFAULT '',
            page_url VARCHAR(500) NOT NULL DEFAULT '',
            proof_hash VARCHAR(64) NOT NULL DEFAULT '',
            gcm_state TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_consent_uuid (consent_uuid),
            KEY idx_created_at (created_at),
            KEY idx_status (status),
            KEY idx_country (country_code)
        ) {$charset_collate};";

        $sql_requests = "CREATE TABLE {$requests_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_code VARCHAR(32) NOT NULL,
            request_type VARCHAR(40) NOT NULL DEFAULT 'oposicion_optout',
            requester_name VARCHAR(150) NOT NULL DEFAULT '',
            requester_rut VARCHAR(30) NOT NULL DEFAULT '',
            requester_email VARCHAR(150) NOT NULL DEFAULT '',
            consent_uuid VARCHAR(64) NOT NULL DEFAULT '',
            details TEXT NULL,
            status VARCHAR(25) NOT NULL DEFAULT 'pendiente',
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            resolved_at DATETIME NULL DEFAULT NULL,
            admin_notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_request_code (request_code),
            KEY idx_status (status),
            KEY idx_email (requester_email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_consents);
        dbDelta($sql_requests);

        update_option(self::DB_VERSION_KEY, self::DB_VERSION);
    }

    /**
     * Enmascara una dirección IP para cumplir el principio de minimización de datos (Art. 3 Ley 21.719)
     */
    public static function mask_ip($ip)
    {
        if (empty($ip)) {
            return '0.0.0.0';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . '::0';
        }
        return '0.0.0.0';
    }

    /**
     * Guarda un registro auditable con firma HMAC-SHA256 de integridad probatoria
     */
    public static function log_consent(array $data)
    {
        global $wpdb;
        $settings = Rotar_Privacidad_Chile::get_settings();

        $raw_ip = RPC_GeoIP::get_client_ip();
        $ip_masked = !empty($settings['anonymize_ip_in_logs']) ? self::mask_ip($raw_ip) : sanitize_text_field($raw_ip);
        $ip_hash = hash_hmac('sha256', $raw_ip, wp_salt('auth'));

        $uuid = !empty($data['consent_uuid']) ? sanitize_text_field($data['consent_uuid']) : wp_generate_uuid4();
        $status = sanitize_key($data['status'] ?? 'custom');
        $cat_preferences = !empty($data['preferences']) ? 1 : 0;
        $cat_analytics   = !empty($data['analytics']) ? 1 : 0;
        $cat_marketing   = !empty($data['marketing']) ? 1 : 0;
        $country         = strtoupper(sanitize_text_field($data['country_code'] ?? 'CL'));
        $law             = sanitize_text_field($data['applicable_law'] ?? 'CHL_21719');
        $version         = sanitize_text_field($settings['consent_version'] ?? '1.0');
        $ua              = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 295);
        $page_url        = substr(esc_url_raw($data['page_url'] ?? home_url('/')), 0, 495);
        $created_at      = current_time('mysql');

        $gcm_state = wp_json_encode([
            'ad_storage'              => $cat_marketing ? 'granted' : 'denied',
            'ad_user_data'            => $cat_marketing ? 'granted' : 'denied',
            'ad_personalization'      => $cat_marketing ? 'granted' : 'denied',
            'analytics_storage'       => $cat_analytics ? 'granted' : 'denied',
            'functionality_storage'   => $cat_preferences ? 'granted' : 'denied',
            'personalization_storage' => $cat_preferences ? 'granted' : 'denied',
            'security_storage'        => 'granted',
        ]);

        // Hash criptográfico probatorio de auditoría (evidencia inalterable del acto afirmativo)
        $proof_payload = implode('|', [
            $uuid,
            $status,
            '1',
            $cat_preferences,
            $cat_analytics,
            $cat_marketing,
            $country,
            $law,
            $version,
            $ip_hash,
            $created_at
        ]);
        $proof_hash = hash_hmac('sha256', $proof_payload, wp_salt('secure_auth'));

        $inserted = $wpdb->insert(
            self::table_consents(),
            [
                'consent_uuid'    => $uuid,
                'user_id'         => get_current_user_id(),
                'status'          => $status,
                'cat_necessary'   => 1,
                'cat_preferences' => $cat_preferences,
                'cat_analytics'   => $cat_analytics,
                'cat_marketing'   => $cat_marketing,
                'country_code'    => $country,
                'applicable_law'  => $law,
                'consent_version' => $version,
                'ip_masked'       => $ip_masked,
                'ip_hash'         => $ip_hash,
                'user_agent'      => $ua,
                'page_url'        => $page_url,
                'proof_hash'      => $proof_hash,
                'gcm_state'       => $gcm_state,
                'created_at'      => $created_at,
            ],
            ['%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        // Purga automática según meses de retención configurados
        $retention_months = max(6, (int) ($settings['log_retention_months'] ?? 36));
        if (wp_rand(1, 50) === 1) {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retention_months} months"));
            $table = self::table_consents();
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
        }

        return [
            'saved'        => (bool) $inserted,
            'id'           => (int) $wpdb->insert_id,
            'consent_uuid' => $uuid,
            'proof_hash'   => $proof_hash,
            'created_at'   => $created_at,
        ];
    }

    /**
     * Obtiene KPIs y estadísticas resumidas para el Dashboard
     */
    public static function get_stats()
    {
        global $wpdb;
        $table = self::table_consents();
        $req_table = self::table_requests();

        // Verificar que la tabla exista
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return [
                'total'       => 0,
                'accept_all'  => 0,
                'reject_all'  => 0,
                'custom'      => 0,
                'accept_rate' => 0,
                'pending_req' => 0,
            ];
        }

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $accept_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'accepted_all'");
        $reject_all = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'rejected_all'");
        $custom     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'custom'");

        $pending_req = 0;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $req_table)) === $req_table) {
            $pending_req = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$req_table} WHERE status = 'pendiente'");
        }

        return [
            'total'       => $total,
            'accept_all'  => $accept_all,
            'reject_all'  => $reject_all,
            'custom'      => $custom,
            'accept_rate' => $total > 0 ? round((($accept_all + $custom) / $total) * 100, 1) : 0,
            'pending_req' => $pending_req,
        ];
    }

    /**
     * Consulta paginada de registros de consentimiento
     */
    public static function get_logs($limit = 50, $offset = 0, $search = '')
    {
        global $wpdb;
        $table = self::table_consents();

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE consent_uuid LIKE %s OR ip_masked LIKE %s OR country_code LIKE %s OR status LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d",
                    $like,
                    $like,
                    $like,
                    $like,
                    (int) $limit,
                    (int) $offset
                ),
                ARRAY_A
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $limit, (int) $offset),
            ARRAY_A
        );
    }

    /**
     * Registra una solicitud de Derechos ARSOP+ o Exclusión Voluntaria (Opt-Out)
     */
    public static function log_privacy_request(array $data)
    {
        global $wpdb;
        $raw_ip = RPC_GeoIP::get_client_ip();
        $ip_hash = hash_hmac('sha256', $raw_ip, wp_salt('auth'));
        $code = 'ARSOP-' . strtoupper( wp_generate_password(8, false, false) );

        $inserted = $wpdb->insert(
            self::table_requests(),
            [
                'request_code'    => $code,
                'request_type'    => sanitize_key($data['request_type'] ?? 'oposicion_optout'),
                'requester_name'  => sanitize_text_field($data['name'] ?? ''),
                'requester_rut'   => sanitize_text_field($data['rut'] ?? ''),
                'requester_email' => sanitize_email($data['email'] ?? ''),
                'consent_uuid'    => sanitize_text_field($data['consent_uuid'] ?? ''),
                'details'         => sanitize_textarea_field($data['details'] ?? ''),
                'status'          => 'pendiente',
                'ip_hash'         => $ip_hash,
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return [
            'saved'        => (bool) $inserted,
            'request_code' => $code,
        ];
    }
}

