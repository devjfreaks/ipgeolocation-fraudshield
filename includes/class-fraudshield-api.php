<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_API {

    /**
     * Fetch IP data from ipgeolocation.io.
     * Only requests the fields needed for fraud scoring to keep latency low.
     *
     * @param string $ip
     * @return array|WP_Error
     */
    public static function lookup( string $ip ) {
        $api_key = get_option( 'ipgeofs_api_key' );
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', __( 'FraudShield: API key not set.', 'ipgeolocation-fraudshield' ) );
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return new WP_Error( 'invalid_ip', __( 'FraudShield: Invalid IP address.', 'ipgeolocation-fraudshield' ) );
        }

        if ( self::is_private_ip( $ip ) ) {
            return new WP_Error( 'private_ip', __( 'FraudShield: Private IP: skipping.', 'ipgeolocation-fraudshield' ) );
        }

        $url = add_query_arg( [
            'apiKey'  => $api_key,
            'ip'      => $ip,
            'include' => 'security',
            'fields'  => implode( ',', [
                'location.country_code2',
                'security.is_tor',
                'security.is_proxy',
                'security.is_vpn',
                'security.is_known_attacker',
                'security.is_bot',
                'security.is_spam',
                'security.is_residential_proxy',
                'security.is_cloud_provider',
                'security.proxy_confidence_score',
                'security.vpn_confidence_score',
            ] ),
        ], IPGEOFS_API_BASE );

        $response = wp_remote_get( $url, [
            'timeout'    => 8,
            'user-agent' => 'FraudShield-WooCommerce/' . IPGEOFS_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 || ! is_array( $data ) ) {
            return new WP_Error(
                'api_error',
                sprintf( __( 'FraudShield: API returned HTTP %d.', 'ipgeolocation-fraudshield' ), $code )
            );
        }

        return $data;
    }


    private static function is_private_ip( string $ip ): bool {
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
