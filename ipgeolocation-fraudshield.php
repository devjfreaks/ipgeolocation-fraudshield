<?php
/**
 * Plugin Name:       IPGeolocation FraudShield
 * Plugin URI:        https://ipgeolocation.io/tutorials/woocommerce-fraud-prevention-setup-guide-for-fraudshield
 * Description:       Real-time fraud detection for WooCommerce. Flags suspicious orders by analysing IP location, VPN/proxy usage, threat scores, and more.
 * Version:           1.0.0
 * Author:            IPGeolocation.io
 * Author URI:        https://ipgeolocation.io
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ipgeolocation-fraudshield
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   9.4
 */

defined( 'ABSPATH' ) || exit;


define( 'IPGEOFS_VERSION',     '1.0.0' );
define( 'IPGEOFS_PLUGIN_FILE', __FILE__ );
define( 'IPGEOFS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'IPGEOFS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'IPGEOFS_API_BASE',    'https://api.ipgeolocation.io/v3/ipgeo' );

spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'IPGEOFS_' ) !== 0 ) {
        return;
    }

    $slug = strtolower( str_replace( [ 'IPGEOFS_', '_' ], [ '', '-' ], $class ) );
    $file = IPGEOFS_PLUGIN_DIR . 'includes/class-fraudshield-' . $slug . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );
add_filter( 'plugin_row_meta', function( $links, $file ) {
    if ( $file === plugin_basename( __FILE__ ) ) {
        foreach ( $links as $key => $link ) {
            if ( strpos( $link, 'plugin site' ) !== false ) {
                unset( $links[$key]);
            }
        }

        $links[] = '<a href="https://ipgeolocation.io/tutorials/woocommerce-fraud-prevention-setup-guide-for-fraudshield" target="_blank">Tutorial</a>';
    }
    return $links;
}, 10, 2 );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>' . wp_kses_post( __( '<strong>FraudShield</strong> requires WooCommerce to be active.', 'ipgeolocation-fraudshield' ) ) . '</p></div>';
        } );
        return;
    }
    add_action( 'before_woocommerce_init', function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    } );
    IPGEOFS_Core::instance();
} );

register_activation_hook( __FILE__, [ 'IPGEOFS_Installer', 'activate' ] );
add_action( 'ipgeofs_cleanup_logs', [ 'IPGEOFS_Installer', 'run_cleanup' ] );
register_deactivation_hook( __FILE__, [ 'IPGEOFS_Installer', 'deactivate' ] );
