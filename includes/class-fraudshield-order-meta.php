<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes FraudShield data on WooCommerce orders through the order object,
 * so it works with both order storage modes (HPOS and the legacy posts table).
 *
 * Version 1.0.0 wrote this data with the post meta functions. On stores using HPOS that
 * data sits on the order's placeholder post, where WooCommerce does not look. Reads fall
 * back to it so results from 1.0.0 stay visible, and writes clear it so it can't come back.
 */
class IPGEOFS_Order_Meta {

    /**
     * @param WC_Order|int $order Order object or ID.
     * @param string       $key   Meta key.
     * @return mixed Stored value, or '' when not set.
     */
    public static function get( $order, string $key ) {
        $order = self::order( $order );
        if ( ! $order ) {
            return '';
        }

        $value = $order->get_meta( $key, true );
        if ( '' === $value && self::hpos_enabled() ) {
            $value = get_post_meta( $order->get_id(), $key, true );
        }
        return $value;
    }

    /**
     * Saves several meta values on an order in one write.
     *
     * @param WC_Order $order  Order.
     * @param array    $values Meta key => value.
     */
    public static function update( WC_Order $order, array $values ) {
        foreach ( $values as $key => $value ) {
            $order->update_meta_data( $key, $value );
        }
        $order->save_meta_data();
        self::clear_legacy( $order->get_id(), array_keys( $values ) );
    }

    /**
     * Removes meta values from an order.
     *
     * @param WC_Order $order Order.
     * @param string[] $keys  Meta keys.
     */
    public static function delete( WC_Order $order, array $keys ) {
        foreach ( $keys as $key ) {
            $order->delete_meta_data( $key );
        }
        $order->save_meta_data();
        self::clear_legacy( $order->get_id(), $keys );
    }

    /**
     * Admin edit link for an order, correct for both storage modes.
     *
     * @param int $order_id Order ID.
     */
    public static function edit_url( int $order_id ): string {
        if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && method_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, 'get_order_admin_edit_url' ) ) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
        }
        return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }

    /**
     * Moves FraudShield data that 1.0.0 left on HPOS placeholder posts into the orders.
     * WooCommerce does not sync that data, so it would be lost if the store later turns on
     * compatibility mode or switches order storage. Runs in batches, ordered by ID.
     *
     * @param int $after Only look at orders with an ID above this.
     * @param int $limit Orders per batch.
     * @return int Highest order ID looked at, or 0 when nothing is left.
     */
    public static function migrate_legacy_batch( int $after = 0, int $limit = 50 ): int {
        if ( ! self::hpos_enabled() ) {
            return 0;
        }
        global $wpdb;
        // One-off read of WordPress core tables to find leftover rows; every value goes through prepare().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND pm.post_id > %d AND ( pm.meta_key LIKE %s OR pm.meta_key LIKE %s )
             ORDER BY pm.post_id ASC LIMIT %d",
            'shop_order_placehold',
            $after,
            $wpdb->esc_like( '_fraudshield_' ) . '%',
            $wpdb->esc_like( '_ipgeofs_' ) . '%',
            $limit
        ) );

        $last = 0;
        foreach ( $ids as $id ) {
            $id    = (int) $id;
            $last  = $id;
            $order = wc_get_order( $id );
            if ( ! $order ) {
                continue;
            }
            $legacy = [];
            foreach ( get_post_meta( $id ) as $key => $values ) {
                if ( 0 === strpos( $key, '_fraudshield_' ) || 0 === strpos( $key, '_ipgeofs_' ) ) {
                    $legacy[ $key ] = maybe_unserialize( $values[0] );
                }
            }
            foreach ( $legacy as $key => $value ) {
                // Never overwrite a newer result saved by 1.0.1.
                if ( '' === $order->get_meta( $key, true ) ) {
                    $order->update_meta_data( $key, $value );
                }
            }
            $order->save_meta_data();
            foreach ( array_keys( $legacy ) as $key ) {
                delete_post_meta( $id, $key );
            }
        }
        return count( $ids ) === $limit ? $last : 0;
    }

    private static function order( $order ) {
        return $order instanceof WC_Order ? $order : wc_get_order( $order );
    }

    private static function hpos_enabled(): bool {
        return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * With HPOS on, deletes copies that 1.0.0 left on the placeholder post.
     * With HPOS off, post meta is the order meta, so nothing is touched.
     * With compatibility mode on, WooCommerce keeps the posts copy in sync itself.
     */
    private static function clear_legacy( int $order_id, array $keys ) {
        if ( ! self::hpos_enabled() || 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) ) {
            return;
        }
        foreach ( $keys as $key ) {
            delete_post_meta( $order_id, $key );
        }
    }
}
