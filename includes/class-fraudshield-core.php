<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_Core {

    private static ?IPGEOFS_Core $instance = null;

    public static function instance(): IPGEOFS_Core {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        $this->migrate_legacy_options();
        $this->init_hooks();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'ipgeolocation-fraudshield', false,
            dirname( plugin_basename( IPGEOFS_PLUGIN_FILE ) ) . '/languages' );
    }

    private function migrate_legacy_options() {
        $legacy_map = [
            'ipgeofs_db_version' => 'fraudshield_db_version',
            'ipgeofs_enabled' => 'fraudshield_enabled',
            'ipgeofs_auto_hold_score' => 'fraudshield_auto_hold_score',
            'ipgeofs_email_alert_score' => 'fraudshield_email_alert_score',
            'ipgeofs_block_vpn' => 'fraudshield_block_vpn',
            'ipgeofs_block_tor' => 'fraudshield_block_tor',
            'ipgeofs_block_proxy' => 'fraudshield_block_proxy',
            'ipgeofs_block_bot' => 'fraudshield_block_bot',
            'ipgeofs_alert_email' => 'fraudshield_alert_email',
            'ipgeofs_high_risk_action' => 'fraudshield_high_risk_action',
            'ipgeofs_log_all' => 'fraudshield_log_all',
            'ipgeofs_test_mode' => 'fraudshield_test_mode',
            'ipgeofs_test_ip' => 'fraudshield_test_ip',
            'ipgeofs_whitelist_countries' => 'fraudshield_whitelist_countries',
            'ipgeofs_notify_customer' => 'fraudshield_notify_customer',
            'ipgeofs_log_retention_days' => 'fraudshield_log_retention_days',
            'ipgeofs_block_residential_proxy' => 'fraudshield_block_residential_proxy',
            'ipgeofs_block_known_attacker' => 'fraudshield_block_known_attacker',
            'ipgeofs_block_country_mismatch' => 'fraudshield_block_country_mismatch',
            'ipgeofs_block_spam' => 'fraudshield_block_spam',
            'ipgeofs_block_cloud_provider' => 'fraudshield_block_cloud_provider',
            'ipgeofs_weight_country_mismatch' => 'fraudshield_weight_country_mismatch',
            'ipgeofs_weight_vpn' => 'fraudshield_weight_vpn',
            'ipgeofs_weight_tor' => 'fraudshield_weight_tor',
            'ipgeofs_weight_proxy' => 'fraudshield_weight_proxy',
            'ipgeofs_weight_residential_proxy' => 'fraudshield_weight_residential_proxy',
            'ipgeofs_weight_known_attacker' => 'fraudshield_weight_known_attacker',
            'ipgeofs_weight_bot' => 'fraudshield_weight_bot',
            'ipgeofs_weight_spam' => 'fraudshield_weight_spam',
            'ipgeofs_weight_cloud_provider' => 'fraudshield_weight_cloud_provider',
            'ipgeofs_force_country_mismatch' => 'fraudshield_force_country_mismatch',
            'ipgeofs_force_vpn' => 'fraudshield_force_vpn',
            'ipgeofs_force_tor' => 'fraudshield_force_tor',
            'ipgeofs_force_proxy' => 'fraudshield_force_proxy',
            'ipgeofs_force_residential_proxy' => 'fraudshield_force_residential_proxy',
            'ipgeofs_force_known_attacker' => 'fraudshield_force_known_attacker',
            'ipgeofs_force_bot' => 'fraudshield_force_bot',
            'ipgeofs_force_spam' => 'fraudshield_force_spam',
            'ipgeofs_force_cloud_provider' => 'fraudshield_force_cloud_provider',
            'ipgeofs_show_dashboard_widget' => 'fraudshield_show_dashboard_widget',
        ];

        foreach ( $legacy_map as $new => $old ) {
            if ( false === get_option( $new, false ) ) {
                $legacy_value = get_option( $old, false );
                if ( false !== $legacy_value ) {
                    update_option( $new, $legacy_value );
                }
            }
        }
    }

    private function init_hooks() {
        if ( is_admin() ) new IPGEOFS_Admin();

        if ( get_option( 'ipgeofs_enabled' ) === 'yes' && get_option( 'ipgeofs_api_key' ) ) {
            new IPGEOFS_Checker();
        }

        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

        add_filter( 'manage_woocommerce_page_wc-orders_columns',        [ $this, 'add_order_column' ] );
        add_filter( 'manage_edit-shop_order_columns',                   [ $this, 'add_order_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column',  [ $this, 'render_order_column' ], 10, 2 );
        add_action( 'manage_shop_order_posts_custom_column',            [ $this, 'render_order_column' ], 10, 2 );

        add_action( 'wp_ajax_ipgeofs_test_api',    [ $this, 'ajax_test_api' ] );
        add_action( 'wp_ajax_ipgeofs_get_stats',   [ $this, 'ajax_get_stats' ] );
    }


    public function register_meta_box() {
        $screens = [ 'shop_order' ];
        if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
            $screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        foreach ( $screens as $screen ) {
            add_meta_box( 'fraudshield_meta_box',
                __( 'FraudShield Risk Analysis', 'ipgeolocation-fraudshield' ),
                [ $this, 'render_meta_box' ], $screen, 'side', 'high' );
        }
    }

    public function render_meta_box( $post_or_order ) {
        $order_id = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order->get_id() : $post_or_order->ID;
        $score    = (int) get_post_meta( $order_id, '_fraudshield_score', true );
        $tier     = get_post_meta( $order_id, '_fraudshield_tier', true );
        $signals  = get_post_meta( $order_id, '_fraudshield_signals', true );
        $checked  = get_post_meta( $order_id, '_fraudshield_checked', true );
        $ip       = get_post_meta( $order_id, '_fraudshield_ip', true );

        if ( ! $checked ) {
            echo '<div style="padding:8px 0;">';
            echo '<p style="color:#888;font-size:12px;margin-bottom:10px;">' . esc_html__( 'No fraud check run for this order.', 'ipgeolocation-fraudshield' ) . '</p>';
            echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ipgeofs_recheck&order_id=' . $order_id ), 'ipgeofs_recheck_' . $order_id ) ) . '" class="button button-primary button-small">' . esc_html__( '▶ Run Check Now', 'ipgeolocation-fraudshield' ) . '</a>';
            echo '</div>';
            return;
        }

        $colors = [ 'low' => '#22d3a0', 'medium' => '#f59e0b', 'high' => '#f43f5e' ];
        $color  = $colors[ $tier ] ?? '#888';
        $pct    = $score . '%';

        echo '<div class="fs-metabox">';

        echo '<div style="text-align:center;padding:14px 0 10px;">';
        echo '<div style="font-size:40px;font-weight:800;color:' . esc_attr( $color ) . ';line-height:1;">' . esc_html( $score ) . '<span style="font-size:14px;font-weight:400;color:#999;">/100</span></div>';
        echo '<div style="margin:6px auto 0;background:' . esc_attr( $color ) . '22;border:1px solid ' . esc_attr( $color ) . '55;border-radius:20px;padding:3px 12px;display:inline-block;font-size:11px;font-weight:700;color:' . esc_attr( $color ) . ';text-transform:uppercase;letter-spacing:0.08em;">' . esc_html( $tier ) . ' RISK</div>';
        echo '</div>';

        echo '<div style="background:#f0f0f0;border-radius:4px;height:5px;margin:0 0 14px;overflow:hidden;">';
        echo '<div style="width:' . esc_attr( $pct ) . ';height:100%;background:' . esc_attr( $color ) . ';border-radius:4px;transition:width 0.6s ease;"></div>';
        echo '</div>';

        if ( $signals ) {
            $list = json_decode( $signals, true );
            if ( is_array( $list ) ) {
                $total_possible = 0;
                foreach ( $list as $sig ) {
                    if ( $sig['enabled'] ?? false ) {
                        $total_possible += (int) $sig['points'];
                    }
                }
                
                echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:#999;margin-bottom:8px;">' . esc_html__( 'Signals', 'ipgeolocation-fraudshield' ) . '</div>';
                echo '<ul style="margin:0;padding:0;list-style:none;">';
                foreach ( $list as $sig ) {
                    if ( empty( $sig['flagged'] ) ) continue;
                    $contribution = $total_possible > 0 ? round( ( (int) $sig['points'] / $total_possible ) * 100 ) : 0;
                    echo '<li style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:12px;">';
                    echo '<span style="display:flex;align-items:center;gap:5px;"><span style="color:#f43f5e;">⚑</span>' . esc_html( $sig['label'] ) . '</span>';
                    echo '<span style="background:#f43f5e18;color:#f43f5e;border-radius:3px;padding:1px 5px;font-size:10px;font-weight:700;">+' . (int) $contribution . '</span>';
                    echo '</li>';
                }
                echo '</ul>';
                $clean = array_filter( $list, fn( $s ) => empty( $s['flagged'] ) );
                if ( $clean ) {
                    echo '<details style="margin-top:8px;"><summary style="font-size:11px;color:#999;cursor:pointer;">' . esc_html__( 'Passed checks', 'ipgeolocation-fraudshield' ) . ' (' . count( $clean ) . ')</summary>';
                    echo '<ul style="margin:6px 0 0;padding:0;list-style:none;">';
                    foreach ( $clean as $sig ) {
                        echo '<li style="font-size:11px;color:#22d3a0;padding:3px 0;">' . esc_html( $sig['label'] ) . '</li>';
                    }
                    echo '</ul></details>';
                }
            }
        }

        echo '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #f0f0f0;">';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ipgeofs_recheck&order_id=' . $order_id ), 'ipgeofs_recheck_' . $order_id ) ) . '" class="button button-small" style="width:100%;text-align:center;">' . esc_html__( '↺ Re-check', 'ipgeolocation-fraudshield' ) . '</a>';
        echo '</div>';

        echo '<p style="margin:10px 0 0;font-size:10px;color:#ccc;text-align:center;">' . esc_html__( 'Powered by ipgeolocation.io', 'ipgeolocation-fraudshield' ) . '</p>';
        echo '</div>';
    }


    public function add_order_column( $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_status' ) $new['fraudshield_risk'] = __( 'Fraud Risk', 'ipgeolocation-fraudshield' );
        }
        return $new;
    }

    public function render_order_column( $column, $post_id_or_order ) {
        if ( $column !== 'fraudshield_risk' ) return;
        $order_id = is_a( $post_id_or_order, 'WC_Order' ) ? $post_id_or_order->get_id() : $post_id_or_order;
        $tier  = get_post_meta( $order_id, '_fraudshield_tier', true );
        $score = get_post_meta( $order_id, '_fraudshield_score', true );

        if ( ! $tier ) { echo '<span style="color:#ddd;">&nbsp;</span>'; return; }
        $c = [ 'low' => [ '#22d3a022','#22d3a0' ], 'medium' => [ '#f59e0b22','#f59e0b' ], 'high' => [ '#f43f5e22','#f43f5e' ] ][ $tier ] ?? [ '#f0f0f0','#999' ];
        printf( '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;background:%s;color:%s;">%s <span style="opacity:0.7;font-weight:400;">%s</span></span>',
            esc_attr( $c[0] ), esc_attr( $c[1] ), esc_html( $score ), esc_html( strtoupper( $tier ) ) );
    }


    public function ajax_test_api() {
        check_ajax_referer( 'ipgeofs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
        $test_ip = '8.8.8.8'; // Google DNS

        $url = add_query_arg( [ 'apiKey' => $api_key, 'ip' => $test_ip, 'fields' => 'location.country_code2,security.is_vpn' ], IPGEOFS_API_BASE );
        $res = wp_remote_get( $url, [ 'timeout' => 8 ] );

        if ( is_wp_error( $res ) ) {
            wp_send_json_error( $res->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code === 200 && isset( $body['location'] ) ) {
            wp_send_json_success( [ 'message' => 'API key is valid', 'country' => $body['location']['country_code2'] ?? '?' ] );
        } else {
            $msg = $body['message'] ?? $body['error'] ?? 'Invalid API key';
            wp_send_json_error( $msg );
        }
    }

    public function ajax_get_stats() {
        check_ajax_referer( 'ipgeofs_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Forbidden' );

        global $wpdb;
        $table = $wpdb->prefix . 'fraudshield_logs';
        $days  = (int) ( $_POST['days'] ?? 30 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as day, risk_tier, COUNT(*) as cnt
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at), risk_tier
             ORDER BY day ASC", $days
        ) );

        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(risk_tier='high') as high,
                SUM(risk_tier='medium') as medium,
                SUM(risk_tier='low') as low,
                AVG(risk_score) as avg_score
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days
        ) );

        wp_send_json_success( [ 'rows' => $rows, 'totals' => $totals ] );
    }
}
