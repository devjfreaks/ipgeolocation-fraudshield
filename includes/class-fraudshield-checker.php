<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_Checker {

    public function __construct() {
        add_action( 'woocommerce_thankyou',               [ $this, 'check_order_by_id' ], 20, 1 );
        add_action( 'woocommerce_checkout_order_created', [ $this, 'store_ip_early' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed',   [ $this, 'check_on_status_change' ], 10, 3 );
        add_action( 'admin_post_ipgeofs_recheck',         [ $this, 'handle_manual_recheck' ] );
    }
    public function store_ip_early( WC_Order $order ) {
        $ip = $order->get_customer_ip_address();
        if ( $ip ) {
            update_post_meta( $order->get_id(), '_fraudshield_captured_ip', sanitize_text_field( $ip ) );
        }
    }
    public function check_on_status_change( int $order_id, string $old, string $new ) {
        if ( ! in_array( $new, [ 'processing', 'on-hold' ], true ) ) return;
        if ( get_post_meta( $order_id, '_fraudshield_checked', true ) ) return;
        $order = wc_get_order( $order_id );
        if ( $order ) $this->run_check( $order );
    }

    public function check_order_by_id( int $order_id ) {
        if ( get_post_meta( $order_id, '_fraudshield_checked', true ) ) return;
        $order = wc_get_order( $order_id );
        if ( $order ) $this->run_check( $order );
    }

    public function handle_manual_recheck() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        check_admin_referer( 'ipgeofs_recheck_' . $order_id );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( 'Forbidden' );

        $order = wc_get_order( $order_id );
        if ( $order ) {
            delete_post_meta( $order_id, '_fraudshield_checked' );
            $this->run_check( $order );
        }
        $order = wc_get_order( $order_id );
        $redirect = $order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' );
        wp_redirect( add_query_arg( 'ipgeofs_rechecked', '1', $redirect ) );
        exit;
    }

    private function run_check( WC_Order $order ) {
        $order_id = $order->get_id();

        $test_mode = get_option( 'ipgeofs_test_mode' ) === 'yes';
        if ( $test_mode ) {
            $ip = sanitize_text_field( get_option( 'ipgeofs_test_ip', '8.8.8.8' ) );
        } else {
            $ip = get_post_meta( $order_id, '_fraudshield_captured_ip', true )
                ?: $order->get_customer_ip_address();
        }

        if ( ! $ip ) {
            $this->mark_skipped( $order_id, 'No IP address on order.' );
            return;
        }

        $api_response = IPGEOFS_API::lookup( $ip );

        if ( is_wp_error( $api_response ) ) {
            $code    = $api_response->get_error_code();
            $message = $api_response->get_error_message();

            if ( $code === 'private_ip' ) {
                error_log( '[FraudShield] Order #' . $order_id . ': private/local IP skipped.' );
                $this->mark_skipped( $order_id, 'Local/private IP skipped.' );
                return;
            }

            if ( $code === 'no_api_key' ) {
                error_log( '[FraudShield] Order #' . $order_id . ': no API key configured, fraud check skipped.' );
                $this->mark_skipped( $order_id, 'No API key configured.' );
                $order->add_order_note(
                    __( 'FraudShield: Fraud check skipped, no API key configured. Go to FraudShield Settings to add your key.', 'ipgeolocation-fraudshield' ),
                    false
                );
                return;
            }

            error_log( '[FraudShield] Order #' . $order_id . ': API error, ' . $message );
            $this->mark_skipped( $order_id, $message );
            $order->add_order_note(
                sprintf( __( 'FraudShield: Fraud check failed, %s', 'ipgeolocation-fraudshield' ), $message ),
                false
            );
            return;
        }

        $billing_country = $order->get_billing_country();
        $scorer          = new IPGEOFS_Scorer( $api_response, $billing_country );
        $result          = $scorer->score();

        update_post_meta( $order_id, '_fraudshield_checked',    true );
        update_post_meta( $order_id, '_fraudshield_score',      $result['score'] );
        update_post_meta( $order_id, '_fraudshield_tier',       $result['tier'] );
        update_post_meta( $order_id, '_fraudshield_signals',    wp_json_encode( $result['signals'] ) );
        update_post_meta( $order_id, '_fraudshield_ip_country', $result['ip_country'] );
        update_post_meta( $order_id, '_fraudshield_ip',         $ip );

        if ( $test_mode ) {
            update_post_meta( $order_id, '_ipgeofs_test_mode', true );
        }

        $this->log( $order_id, $ip, $billing_country, $result, $api_response );
        $this->act( $order, $result );
    }

    private function act( WC_Order $order, array $result ) {
        $score          = $result['score'];
        $tier           = $result['tier'];
        $hold_score     = (int) get_option( 'ipgeofs_auto_hold_score', 71 );
        $mail_score     = (int) get_option( 'ipgeofs_email_alert_score', 41 );
        $action         = get_option( 'ipgeofs_high_risk_action', 'hold' );
        $test_mode      = get_option( 'ipgeofs_test_mode' ) === 'yes';
        $payment_method = $order->get_payment_method(); // e.g. 'cod', 'stripe', 'paypal'
        $is_cod         = $payment_method === 'cod';
        $note_suffix    = $test_mode ? ' [TEST MODE]' : '';
        
        $has_virtual = false;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ( $product->is_virtual() || $product->is_downloadable() ) ) {
                $has_virtual = true;
                break;
            }
        }


        if ( $score >= $hold_score ) {

            if ( $action === 'cancel' ) {
                if ( ! $is_cod ) {
                    $order->update_status( 'on-hold', '', false );
                    $order->add_order_note(
                        sprintf(
                            'FraudShield: Auto-cancel was configured but this order was paid online (%s). Order placed on hold instead. If you confirm fraud, cancel manually and issue a refund from the order screen.',
                            strtoupper( $payment_method )
                        ) . $note_suffix,
                        false
                    );
                } else {
                    $order->update_status( 'cancelled', '', false );
                    $order->add_order_note(
                        sprintf( 'FraudShield: Order auto-cancelled due to high fraud risk (score: %d/100). Payment was Cash on Delivery, no charge was made.', $score ) . $note_suffix,
                        false
                    );
                }
            }

            elseif ( $action === 'hold' ) {
                $order->update_status( 'on-hold', '', false );
                if ( ! $is_cod ) {
                    $order->add_order_note(
                        sprintf(
                            'FraudShield: Order placed on hold due to high fraud risk (score: %d/100). Payment was made online (%s), funds are held. Review and either approve (set to Processing) or cancel and issue a refund.',
                            $score,
                            strtoupper( $payment_method )
                        ) . $note_suffix,
                        false
                    );
                } else {
                    $order->add_order_note(
                        sprintf( 'FraudShield: Order placed on hold due to high fraud risk (score: %d/100). Payment is Cash on Delivery, no charge has been made yet.', $score ) . $note_suffix,
                        false
                    );
                }

                if ( $has_virtual && ! $is_cod ) {
                    $order->add_order_note(
                        '⚠ FraudShield Warning: This order contains virtual/downloadable products. If a download link was already sent, holding the order will NOT revoke access. Review immediately.' . $note_suffix,
                        false
                    );
                }
            }

            elseif ( $action === 'flag' ) {
                $order->add_order_note(
                    sprintf( 'FraudShield: Order flagged for manual review (score: %d/100). No status change applied.', $score ) . $note_suffix,
                    false
                );
            }
        }

        if ( $score >= $mail_score ) {
            IPGEOFS_Mailer::send_alert( $order, $result, $test_mode );
        }
    }

    private function log( int $order_id, string $ip, string $billing_country, array $result, array $raw ) {
        if ( get_option( 'ipgeofs_log_all', 'yes' ) !== 'yes' && $result['tier'] === 'low' ) return;
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'fraudshield_logs', [
            'order_id'        => $order_id,
            'ip_address'      => $ip,
            'billing_country' => $billing_country,
            'ip_country'      => $result['ip_country'],
            'risk_score'      => $result['score'],
            'risk_tier'       => $result['tier'],
            'signals'         => wp_json_encode( $result['signals'] ),
            'raw_response'    => wp_json_encode( $raw ),
        ], [ '%d','%s','%s','%s','%d','%s','%s','%s' ] );
    }

    private function mark_skipped( int $order_id, string $reason ) {
        update_post_meta( $order_id, '_fraudshield_checked', false );
        update_post_meta( $order_id, '_fraudshield_skip_reason', $reason );
    }
}
