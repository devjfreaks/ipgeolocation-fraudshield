<?php
defined( 'ABSPATH' ) || exit;

/**
 * IPGEOFS_Scorer
 *
 * Takes the raw ipgeolocation.io response + order billing country
 * and produces a 0–100 risk score with a labelled signal breakdown.
 *
 * Scoring weights (total possible: ~205, clamped to 100):
 *   Country mismatch           +25
 *   VPN detected               +30
 *   Tor detected               +40
 *   Proxy detected             +30
 *   Residential proxy          +20
 *   Known attacker             +45
 *   Bot                        +35
 *   Spam source                +20
 *   Suspicious user agent      +35
 *   Cloud/datacenter IP        +15
 *   Threat score (proportional)+30 max
 */
class IPGEOFS_Scorer {

    /** @var array Raw API response */
    private array $data;

    /** @var string Two-letter billing country from the order */
    private string $billing_country;

    /** @var array Accumulated signal results */
    private array $signals = [];

    /** @var int Running score total before clamping */
    private int $raw_score = 0;

    private bool $force_max_score = false;

    public function __construct( array $api_data, string $billing_country ) {
        $this->data            = $api_data;
        $this->billing_country = strtoupper( trim( $billing_country ) );
    }

    /**
     * Run all signal checks and return the result.
     *
     * @return array {
     *   score   int       0–100
     *   tier    string    low|medium|high
     *   signals array     [{label, flagged, points, detail}]
     *   ip_country string
     * }
     */
    public function score(): array {
        $this->check_country_mismatch();
        $this->check_vpn();
        $this->check_tor();
        $this->check_proxy();
        $this->check_residential_proxy();
        $this->check_known_attacker();
        $this->check_bot();
        $this->check_spam();
        $this->check_cloud_provider();
        if ( $this->force_max_score ) {
            return [
                'score'      => 100,
                'tier'       => 'high',
                'signals'    => $this->signals,
                'ip_country' => $this->get( 'location.country_code2', '' ),
            ];
        }


        $total_possible = 0;
        foreach ($this->signals as $sig) {
            if ($sig['enabled']) {
                $total_possible += $sig['points']; // now includes unflagged ones too
            }
        }

        if ( $total_possible <= 0 ) {
            $final_score = 0;
        } else {
            $ratio       = $this->raw_score / $total_possible;
            $final_score = (int) round( $ratio * 100 );
        }

        $final_score = max( 0, min( 100, $final_score ) );

        return [
            'score'      => $final_score,
            'tier'       => $this->get_tier( $final_score ),
            'signals'    => $this->signals,
            'ip_country' => $this->get( 'location.country_code2', '' ),
        ];
    }


    private function check_country_mismatch() {
        $ip_country = strtoupper( $this->get( 'location.country_code2', '' ) );
        $match      = ( $ip_country && $this->billing_country )
                      ? $ip_country === $this->billing_country
                      : null;
        $block      = get_option( 'ipgeofs_block_country_mismatch', 'yes' ) === 'yes';
        $pts        = (int) get_option( 'ipgeofs_weight_country_mismatch', 25 );
        $enabled    = $block; // signal is only "in play" if enabled in settings

        if ( ( $match === false ) && $block ) {
            $force = get_option( 'ipgeofs_force_country_mismatch', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Country mismatch', 'ipgeolocation-fraudshield' ),
            ( $match === false ) && $block,
            $pts,
            sprintf(
                __( 'IP in %1$s, billing in %2$s', 'ipgeolocation-fraudshield' ),
                $ip_country,
                $this->billing_country
            ),
            $enabled
        );
    }

    private function check_vpn() {
        $is_vpn  = (bool) $this->get( 'security.is_vpn', false );
        $block   = get_option( 'ipgeofs_block_vpn', 'yes' ) === 'yes';
        $pts     = (int) get_option( 'ipgeofs_weight_vpn', 30 );
        $enabled = $block; // signal is only "in play" if enabled in settings

        if ( $is_vpn && $block ) {
            $force = get_option( 'ipgeofs_force_vpn', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $conf = (int) $this->get( 'security.vpn_confidence_score', 0 );
        $this->add_signal(
            __( 'VPN detected', 'ipgeolocation-fraudshield' ),
            $is_vpn && $block,
            $pts,
            sprintf( __( 'Confidence: %d%%', 'ipgeolocation-fraudshield' ), $conf ),
            $enabled
        );
    }

    private function check_tor() {
        $is_tor  = (bool) $this->get( 'security.is_tor', false );
        $block   = get_option( 'ipgeofs_block_tor', 'yes' ) === 'yes';
        $pts     = (int) get_option( 'ipgeofs_weight_tor', 40 );
        $enabled = $block; // signal is only "in play" if enabled in settings

        if ( $is_tor && $block ) {
            $force = get_option( 'ipgeofs_force_tor', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Tor exit node', 'ipgeolocation-fraudshield' ),
            $is_tor && $block,
            $pts,
            __( 'Traffic via Tor network', 'ipgeolocation-fraudshield' ),
            $enabled
        );
    }

    private function check_proxy() {
        $is_proxy = (bool) $this->get( 'security.is_proxy', false );
        $block    = get_option( 'ipgeofs_block_proxy', 'yes' ) === 'yes';
        $pts      = (int) get_option( 'ipgeofs_weight_proxy', 30 );
        $enabled  = $block; // signal is only "in play" if enabled in settings

        if ( $is_proxy && $block ) {
            $force = get_option( 'ipgeofs_force_proxy', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $conf = (int) $this->get( 'security.proxy_confidence_score', 0 );
        $this->add_signal(
            __( 'Proxy detected', 'ipgeolocation-fraudshield' ),
            $is_proxy && $block,
            $pts,
            sprintf( __( 'Confidence: %d%%', 'ipgeolocation-fraudshield' ), $conf ),
            $enabled
        );
    }

    private function check_residential_proxy() {
        $is_residential_proxy = (bool) $this->get( 'security.is_residential_proxy', false );
        $block                = get_option( 'ipgeofs_block_residential_proxy', 'yes' ) === 'yes';
        $pts                  = (int) get_option( 'ipgeofs_weight_residential_proxy', 20 );
        $enabled              = $block; // signal is only "in play" if enabled in settings

        if ( $is_residential_proxy && $block ) {
            $force = get_option( 'ipgeofs_force_residential_proxy', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Residential proxy', 'ipgeolocation-fraudshield' ),
            $is_residential_proxy && $block,
            $pts,
            __( 'Harder-to-detect proxy type', 'ipgeolocation-fraudshield' ),
            $enabled
        );
    }

    private function check_known_attacker() {
        $is_known_attacker = (bool) $this->get( 'security.is_known_attacker', false );
        $block             = get_option( 'ipgeofs_block_known_attacker', 'yes' ) === 'yes';
        $pts               = (int) get_option( 'ipgeofs_weight_known_attacker', 45 );
        $enabled           = $block; // signal is only "in play" if enabled in settings

        if ( $is_known_attacker && $block ) {
            $force = get_option( 'ipgeofs_force_known_attacker', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Known attacker IP', 'ipgeolocation-fraudshield' ),
            $is_known_attacker && $block,
            $pts,
            __( 'IP flagged in threat intelligence feeds', 'ipgeolocation-fraudshield' ),
            $enabled
        );
    }

    private function check_bot() {
        $is_bot  = (bool) $this->get( 'security.is_bot', false );
        $block   = get_option( 'ipgeofs_block_bot', 'yes' ) === 'yes';
        $pts     = (int) get_option( 'ipgeofs_weight_bot', 35 );
        $enabled = $block; // signal is only "in play" if enabled in settings

        if ( $is_bot && $block ) {
            $force = get_option( 'ipgeofs_force_bot', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Bot traffic', 'ipgeolocation-fraudshield' ),
            $is_bot && $block,
            $pts,
            __( 'Automated request detected', 'ipgeolocation-fraudshield' ),
            $enabled
        );
    }

    private function check_spam() {
        $is_spam = (bool) $this->get( 'security.is_spam', false );
        $block   = get_option( 'ipgeofs_block_spam', 'yes' ) === 'yes';
        $pts     = (int) get_option( 'ipgeofs_weight_spam', 20 );
        $enabled = $block; // signal is only "in play" if enabled in settings

        if ( $is_spam && $block ) {
            $force = get_option( 'ipgeofs_force_spam', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $this->add_signal(
            __( 'Spam source', 'ipgeolocation-fraudshield' ),
            $is_spam && $block,
            $pts,
            __( 'IP associated with spam activity', 'ipgeolocation-fraudshield' ),
            $enabled
        );
    }


    private function check_cloud_provider() {
        $is_cloud_provider = (bool) $this->get( 'security.is_cloud_provider', false );
        $block             = get_option( 'ipgeofs_block_cloud_provider', 'yes' ) === 'yes';
        $pts               = (int) get_option( 'ipgeofs_weight_cloud_provider', 15 );
        $enabled           = $block; // signal is only "in play" if enabled in settings

        if ( $is_cloud_provider && $block ) {
            $force = get_option( 'ipgeofs_force_cloud_provider', 'no' ) === 'yes';
            if ( $force ) $this->force_max_score = true;
        }

        $provider = $this->get( 'security.cloud_provider_name', __( 'Unknown', 'ipgeolocation-fraudshield' ) );
        $this->add_signal(
            __( 'Cloud/datacenter IP', 'ipgeolocation-fraudshield' ),
            $is_cloud_provider && $block,
            $pts,
            sprintf( __( 'Provider: %s', 'ipgeolocation-fraudshield' ), $provider ),
            $enabled
        );
    }



    private function add_signal( string $label, bool $flagged, int $points, string $detail = '', bool $enabled = true ) {
        if ( $flagged && $enabled ) {
            $this->raw_score += $points;
        }
        $this->signals[] = [
            'label'   => $label,
            'flagged' => $flagged,
            'points'  => $points,
            'detail'  => $detail,
            'enabled' => $enabled,
        ];
    }

    private function get( string $dot_path, $default = null ) {
        $keys  = explode( '.', $dot_path );
        $value = $this->data;
        foreach ( $keys as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return $default;
            }
            $value = $value[ $key ];
        }
        return $value;
    }

    private function get_tier( int $score ): string {
        if ( $score >= 71 ) return 'high';
        if ( $score >= 41 ) return 'medium';
        return 'low';
    }
}
