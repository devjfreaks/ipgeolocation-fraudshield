<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_Installer {

    public static function activate() {
        self::create_table();
        self::set_defaults();
        self::schedule_cleanup();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        self::unschedule_cleanup();
        flush_rewrite_rules();
    }

    private static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'fraudshield_logs';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        BIGINT(20) UNSIGNED NOT NULL,
            ip_address      VARCHAR(45)  NOT NULL DEFAULT '',
            billing_country VARCHAR(3)   NOT NULL DEFAULT '',
            ip_country      VARCHAR(3)   NOT NULL DEFAULT '',
            risk_score      TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
            risk_tier       VARCHAR(10)  NOT NULL DEFAULT 'low',
            signals         TEXT,
            raw_response    LONGTEXT,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id  (order_id),
            KEY risk_tier (risk_tier),
            KEY created_at (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'ipgeofs_db_version', IPGEOFS_VERSION );
    }

    private static function set_defaults() {
        $defaults = [
            'ipgeofs_api_key'           => '',
            'ipgeofs_enabled'           => 'yes',
            'ipgeofs_auto_hold_score'   => 71,
            'ipgeofs_email_alert_score' => 41,
            'ipgeofs_block_vpn'         => 'yes',
            'ipgeofs_block_tor'         => 'yes',
            'ipgeofs_block_proxy'       => 'yes',
            'ipgeofs_block_bot'         => 'yes',
            'ipgeofs_block_residential_proxy' => 'yes',
            'ipgeofs_block_known_attacker'    => 'yes',
            'ipgeofs_alert_email'       => get_option( 'admin_email' ),
            'ipgeofs_high_risk_action'  => 'hold',
            'ipgeofs_log_all'           => 'yes',
            'ipgeofs_test_mode'         => 'no',
            'ipgeofs_test_ip'           => '185.220.101.1',
            'ipgeofs_log_retention_days' => 30,
            'ipgeofs_block_country_mismatch'         => 'yes',
            'ipgeofs_block_spam'         => 'yes',
            'ipgeofs_block_cloud_provider'         => 'yes',
            'ipgeofs_weight_country_mismatch'    => 25,
            'ipgeofs_weight_vpn'                 => 30,
            'ipgeofs_weight_tor'                 => 40,
            'ipgeofs_weight_proxy'               => 30,
            'ipgeofs_weight_residential_proxy'   => 20,
            'ipgeofs_weight_known_attacker'      => 45,
            'ipgeofs_weight_bot'                 => 35,
            'ipgeofs_weight_spam'                => 20,
            'ipgeofs_weight_cloud_provider'      => 15,
            'ipgeofs_force_country_mismatch'  => 'no',
            'ipgeofs_force_vpn'               => 'no',
            'ipgeofs_force_tor'               => 'no',
            'ipgeofs_force_proxy'             => 'no',
            'ipgeofs_force_residential_proxy' => 'no',
            'ipgeofs_force_known_attacker'    => 'no',
            'ipgeofs_force_bot'               => 'no',
            'ipgeofs_force_spam'              => 'no',
            'ipgeofs_force_cloud_provider'    => 'no',
        ];
        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) update_option( $key, $value );
        }
    }
    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'ipgeofs_cleanup_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'ipgeofs_cleanup_logs' );
        }
    }

    public static function run_cleanup() {
        global $wpdb;
        $days  = (int) get_option( 'ipgeofs_log_retention_days', 30 );
        $table = $wpdb->prefix . 'fraudshield_logs';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }

    public static function unschedule_cleanup() {
        $timestamp = wp_next_scheduled( 'ipgeofs_cleanup_logs' );
        if ( $timestamp ) wp_unschedule_event( $timestamp, 'ipgeofs_cleanup_logs' );
    }
}
