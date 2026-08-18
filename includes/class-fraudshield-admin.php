<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_notices',         [ $this, 'maybe_show_notices' ] );
    }

    public function register_menu() {
        $shield_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>');
        add_menu_page( __( 'FraudShield', 'ipgeolocation-fraudshield' ), __( 'FraudShield', 'ipgeolocation-fraudshield' ),
            'manage_woocommerce', 'ipgeolocation-fraudshield', [ $this, 'render_page' ], $shield_svg, 56 );
        add_submenu_page( 'ipgeolocation-fraudshield', __( 'Dashboard', 'ipgeolocation-fraudshield' ), __( 'Dashboard', 'ipgeolocation-fraudshield' ),
            'manage_woocommerce', 'ipgeolocation-fraudshield', [ $this, 'render_page' ] );
        add_submenu_page( 'ipgeolocation-fraudshield', __( 'Fraud Logs', 'ipgeolocation-fraudshield' ), __( 'Fraud Logs', 'ipgeolocation-fraudshield' ),
            'manage_woocommerce', 'ipgeolocation-fraudshield-logs', [ $this, 'render_logs_page' ] );
        add_submenu_page( 'ipgeolocation-fraudshield', __( 'Settings', 'ipgeolocation-fraudshield' ), __( 'Settings', 'ipgeolocation-fraudshield' ),
            'manage_options', 'ipgeolocation-fraudshield-settings', [ $this, 'render_settings_page' ] );
    }

    public function register_settings() {
        $fields = [
            'ipgeofs_api_key','ipgeofs_enabled','ipgeofs_auto_hold_score',
            'ipgeofs_email_alert_score','ipgeofs_block_vpn','ipgeofs_block_tor',
            'ipgeofs_block_proxy','ipgeofs_block_bot','ipgeofs_alert_email',
            'ipgeofs_high_risk_action','ipgeofs_log_all',
            'ipgeofs_test_mode','ipgeofs_test_ip',
            'ipgeofs_whitelist_countries','ipgeofs_notify_customer','ipgeofs_log_retention_days',
            'ipgeofs_block_residential_proxy',
            'ipgeofs_block_known_attacker',
            'ipgeofs_block_country_mismatch',
            'ipgeofs_block_spam',
            'ipgeofs_block_cloud_provider',
            'ipgeofs_weight_country_mismatch',
            'ipgeofs_weight_vpn',
            'ipgeofs_weight_tor',
            'ipgeofs_weight_proxy',
            'ipgeofs_weight_residential_proxy',
            'ipgeofs_weight_known_attacker',
            'ipgeofs_weight_bot',
            'ipgeofs_weight_spam',
            'ipgeofs_weight_cloud_provider',
            'ipgeofs_force_country_mismatch',
            'ipgeofs_force_vpn',
            'ipgeofs_force_tor',
            'ipgeofs_force_proxy',
            'ipgeofs_force_residential_proxy',
            'ipgeofs_force_known_attacker',
            'ipgeofs_force_bot',
            'ipgeofs_force_spam',
            'ipgeofs_force_cloud_provider', 
            'ipgeofs_show_dashboard_widget',   
        ];
        foreach ( $fields as $key ) {
            register_setting( 'ipgeofs_settings', $key, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'ipgeolocation-fraudshield' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'ipgeofs-admin',
            IPGEOFS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            IPGEOFS_VERSION
        );

        wp_enqueue_script(
            'chart-js',
            IPGEOFS_PLUGIN_URL . 'assets/js/vendor/chart.umd.js',
            [],
            '4.5.1',
            true
        );

        wp_enqueue_script(
            'ipgeofs-admin',
            IPGEOFS_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery', 'chart-js' ],
            IPGEOFS_VERSION,
            true
        );

        global $wpdb;

        $table = $wpdb->prefix . 'fraudshield_logs';

        $totals = $wpdb->get_row(
            "SELECT
                SUM(risk_tier='high') as high,
                SUM(risk_tier='medium') as med,
                SUM(risk_tier='low') as low
            FROM {$table}"
        );

        wp_localize_script( 'ipgeofs-admin', 'ipgeofsAdmin', [
            'nonce'    => wp_create_nonce( 'ipgeofs_admin_nonce' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),

            'stats' => [
                'high' => (int) ( $totals->high ?? 0 ),
                'med'  => (int) ( $totals->med ?? 0 ),
                'low'  => (int) ( $totals->low ?? 0 ),
            ],

            'strings'  => [
                'testing' => __( 'Testing…', 'ipgeolocation-fraudshield' ),
                'valid'   => __( 'API key is valid ', 'ipgeolocation-fraudshield' ),
                'invalid' => __( 'Invalid API key ', 'ipgeolocation-fraudshield' ),
                'saving'  => __( 'Saving…', 'ipgeolocation-fraudshield' ),
            ],
        ] );
    }

    public function maybe_show_notices() {
        if ( get_option( 'ipgeofs_api_key' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen ) return;
        echo '<div class="notice notice-warning is-dismissible"><p>' .
            wp_kses_post( sprintf( __( '🛡 <strong>FraudShield</strong> is installed but needs an API key. <a href="%s">Configure now →</a>', 'ipgeolocation-fraudshield' ),
                esc_url( admin_url( 'admin.php?page=ipgeolocation-fraudshield-settings' ) ) ) ) .
            '</p></div>';
    }

    public function register_dashboard_widget() {
        if ( get_option( 'ipgeofs_show_dashboard_widget', 'yes' ) !== 'yes' ) {
            return;
        }
        wp_add_dashboard_widget( 'ipgeofs_widget',
            __( '🛡 FraudShield: Fraud Activity', 'ipgeolocation-fraudshield' ),
            [ $this, 'render_dashboard_widget' ] );
    }


    public function render_dashboard_widget() {
        global $wpdb;
        $table  = $wpdb->prefix . 'fraudshield_logs';
        $counts = $wpdb->get_row( "SELECT SUM(risk_tier='low') as low, SUM(risk_tier='medium') as med, SUM(risk_tier='high') as high, COUNT(*) as total FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
            <?php foreach([['high',(int)$counts->high,'#f43f5e'],['medium',(int)$counts->med,'#f59e0b'],['low',(int)$counts->low,'#22d3a0']] as [$t,$n,$c]): ?>
            <div style="text-align:center;padding:10px 6px;background:<?php echo esc_attr($c); ?>11;border-radius:8px;border:1px solid <?php echo esc_attr($c); ?>33;">
                <div style="font-size:22px;font-weight:800;color:<?php echo esc_attr($c); ?>;"><?php echo esc_html( $n ); ?></div>
                <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:0.05em;margin-top:2px;"><?php echo esc_html(ucfirst($t)); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:12px;color:#999;margin:0;">
            <?php printf( esc_html__( '%d orders analysed in the last 7 days', 'ipgeolocation-fraudshield' ), (int)$counts->total ); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ipgeolocation-fraudshield-logs')); ?>" style="float:right;">View logs →</a>
        </p>
        <?php
    }


    public function render_page() {
        global $wpdb;
        $table  = $wpdb->prefix . 'fraudshield_logs';
        $totals = $wpdb->get_row( "SELECT COUNT(*) as total, SUM(risk_tier='high') as high, SUM(risk_tier='medium') as med, SUM(risk_tier='low') as low, ROUND(AVG(risk_score),1) as avg FROM {$table}" );
        $recent = $wpdb->get_results( "SELECT l.*, l.order_id FROM {$table} l ORDER BY created_at DESC LIMIT 10" );
        ?>
        <div class="wrap fs-wrap">
        <?php $this->render_header( __('Dashboard','ipgeolocation-fraudshield') ); ?>

        <div class="fs-stats-row">
            <?php
            $cards = [
                [ 'label' => __('Total checked','ipgeolocation-fraudshield'),  'value' => (int)$totals->total,  'color' => '' ],
                [ 'label' => __('High risk','ipgeolocation-fraudshield'),      'value' => (int)$totals->high,   'color' => 'red' ],
                [ 'label' => __('Medium risk','ipgeolocation-fraudshield'),    'value' => (int)$totals->med,    'color' => 'amber' ],
                [ 'label' => __('Low risk','ipgeolocation-fraudshield'),       'value' => (int)$totals->low,    'color' => 'green' ],
                [ 'label' => __('Avg. score','ipgeolocation-fraudshield'),     'value' => (float)$totals->avg,  'color' => '' ],
            ];
            foreach ( $cards as $card ):
            ?>
            <div class="fs-stat-tile fs-stat-tile--<?php echo esc_attr($card['color']); ?>">
                <div class="fs-stat-tile__num"><?php echo esc_html($card['value']); ?></div>
                <div class="fs-stat-tile__label"><?php echo esc_html($card['label']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="fs-grid-2col">
            <div class="fs-card">
                <div class="fs-card__title">
                    <?php esc_html_e('Risk trend','ipgeolocation-fraudshield'); ?>
                </div>
                <div class="fs-card__body">
                    <canvas id="fs-trend-chart" style="width:100%; height:260px;"></canvas>
                </div>
            </div>

            <div class="fs-card">
                <div class="fs-card__title"><?php esc_html_e('Risk distribution','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body" style="display:flex;align-items:center;justify-content:center;">
                    <canvas id="fs-donut-chart" style="width:300px;height:300px;margin:auto;"></canvas>
                </div>
            </div>
        </div>

        <div class="fs-card">
            <div class="fs-card__title"><?php esc_html_e('Recent orders','ipgeolocation-fraudshield'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ipgeolocation-fraudshield-logs')); ?>" class="fs-link-btn"><?php esc_html_e('View all →','ipgeolocation-fraudshield'); ?></a>
            </div>
            <div class="fs-card__body fs-card__body--flush">
                <?php $this->render_orders_table( $recent ); ?>
            </div>
        </div>

        </div>

        <?php
    }


    public function render_logs_page() {
        global $wpdb;
        $table  = $wpdb->prefix . 'fraudshield_logs';
        $per_page    = 25;
        $current     = max( 1, (int)( $_GET['paged'] ?? 1 ) );
        $offset      = ( $current - 1 ) * $per_page;
        $tier_filter = sanitize_text_field( $_GET['tier'] ?? '' );
        $search      = sanitize_text_field( $_GET['s'] ?? '' );

        $where_parts = [];
        if ( $tier_filter ) $where_parts[] = $wpdb->prepare( 'risk_tier = %s', $tier_filter );
        if ( $search )      $where_parts[] = $wpdb->prepare( '(ip_address LIKE %s OR order_id = %d)', '%' . $wpdb->esc_like($search) . '%', (int)$search );
        $where = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        $logs  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
        ?>
        <div class="wrap fs-wrap">
        <?php $this->render_header( __('Fraud Logs','ipgeolocation-fraudshield') ); ?>

        <div class="fs-toolbar">
            <div class="fs-filter-tabs">
                <?php foreach ( ['' => __('All','ipgeolocation-fraudshield'), 'high' => __('High','ipgeolocation-fraudshield'), 'medium' => __('Medium','ipgeolocation-fraudshield'), 'low' => __('Low','ipgeolocation-fraudshield')] as $v => $l ):
                    $cls = $tier_filter === $v ? 'fs-tab fs-tab--active' : 'fs-tab';
                    $url = add_query_arg( ['page'=>'ipgeolocation-fraudshield-logs','tier'=>$v,'paged'=>1], admin_url('admin.php') );
                ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($cls); ?>"><?php echo esc_html($l); ?></a>
                <?php endforeach; ?>
            </div>
            <form class="fs-search-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="ipgeolocation-fraudshield-logs"/>
                <input type="hidden" name="tier" value="<?php echo esc_attr($tier_filter); ?>"/>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search IP or order…','ipgeolocation-fraudshield'); ?>" class="fs-search-input"/>
                <button type="submit" class="fs-btn fs-btn--sm"><?php esc_html_e('Search','ipgeolocation-fraudshield'); ?></button>
            </form>
        </div>

        <div class="fs-card fs-card--flush">
            <table class="fs-table widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('Score','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('IP Address','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('IP Country','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('Billing Country','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('Signals Flagged','ipgeolocation-fraudshield'); ?></th>
                        <th><?php esc_html_e('Date','ipgeolocation-fraudshield'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty($logs) ): ?>
                    <tr><td colspan="7" class="fs-empty"><?php esc_html_e('No logs found.','ipgeolocation-fraudshield'); ?></td></tr>
                <?php else: foreach ( $logs as $log ):
                    $signals  = json_decode($log->signals, true) ?? [];
                    $flagged  = array_values(array_filter($signals, fn($s) => $s['flagged']));
                    $order_url = admin_url('post.php?post='.$log->order_id.'&action=edit');
                    $tier_cls  = 'fs-pill fs-pill--' . $log->risk_tier;
                ?>
                    <tr class="fs-log-row fs-log-row--<?php echo esc_attr($log->risk_tier); ?>">
                        <td><a href="<?php echo esc_url($order_url); ?>" class="fs-order-link">#<?php echo (int)$log->order_id; ?></a></td>
                        <td>
                            <span class="<?php echo esc_attr($tier_cls); ?>">
                                <?php echo (int)$log->risk_score; ?> <?php echo esc_html(strtoupper($log->risk_tier)); ?>
                            </span>
                        </td>
                        <td><code class="fs-code"><?php echo esc_html($log->ip_address); ?></code></td>
                        <td><?php echo esc_html($log->ip_country ?: ''); ?></td>
                        <td><?php echo esc_html($log->billing_country ?: ''); ?></td>
                        <td>
                            <?php foreach(array_slice($flagged,0,3) as $sig): ?>
                            <span class="fs-signal-tag"><?php echo esc_html($sig['label']); ?></span>
                            <?php endforeach; ?>
                            <?php if(count($flagged)>3): ?><span class="fs-signal-tag fs-signal-tag--more">+<?php echo (int) ( count($flagged) - 3 ); ?></span><?php endif; ?>
                            <?php if(!$flagged): ?><span style="color:#bbb;font-size:12px;">&nbsp;</span><?php endif; ?>
                        </td>
                        <td class="fs-date"><?php echo esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($log->created_at))); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div class="fs-pagination">
            <?php echo wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$current,'total'=>ceil($total/$per_page),'prev_text'=>'&laquo;','next_text'=>'&raquo;'])); ?>
        </div>
        </div>
        <?php
    }


    public function render_settings_page() {
        if ( ! current_user_can('manage_options') ) return;

        if ( isset($_POST['ipgeofs_api_key']) ) {
            check_admin_referer('ipgeofs_save_settings');
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.','ipgeolocation-fraudshield') . '</p></div>';
        }

        $s = fn($k,$d='') => get_option($k,$d);
        ?>
        <div class="wrap fs-wrap">
        <?php $this->render_header( __('Settings','ipgeolocation-fraudshield') ); ?>

        <div class="fs-settings-layout">
        <form method="post" action="" id="fs-settings-form">
            <?php wp_nonce_field('ipgeofs_save_settings'); ?>

            <!-- API Configuration -->
            <div class="fs-card" id="fs-section-api">
                <div class="fs-card__title"><?php esc_html_e('API Configuration','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body">
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Plugin enabled','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <label class="fs-toggle">
                                <input type="checkbox" name="ipgeofs_enabled" value="yes" <?php checked($s('ipgeofs_enabled','yes'),'yes'); ?>>
                                <span class="fs-toggle__track"><span class="fs-toggle__thumb"></span></span>
                            </label>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label" for="fs-api-key">
                            <?php esc_html_e('ipgeolocation.io API key','ipgeolocation-fraudshield'); ?>
                            <span class="fs-label__req">*</span>
                        </label>
                        <div class="fs-field-input">
                            <div class="fs-api-key-wrap">
                                <input type="password" id="fs-api-key" name="ipgeofs_api_key"
                                       value="<?php echo esc_attr($s('ipgeofs_api_key')); ?>"
                                       class="fs-input fs-input--key" placeholder="<?php esc_attr_e('Paste your API key…','ipgeolocation-fraudshield'); ?>"
                                       autocomplete="new-password" spellcheck="false"/>
                                <button type="button" class="fs-btn fs-btn--ghost fs-toggle-key" data-target="fs-api-key">👁</button>
                                <button type="button" id="fs-test-api-btn" class="fs-btn fs-btn--outline"><?php esc_html_e('Test key','ipgeolocation-fraudshield'); ?></button>
                            </div>
                            <div id="fs-api-test-result" class="fs-api-test-result" style="display:none;"></div>
                            <p class="fs-hint"><?php printf( esc_html__('Get your free key at %s, free tier includes 1,000 requests/day.','ipgeolocation-fraudshield'), '<a href="https://ipgeolocation.io" target="_blank">ipgeolocation.io</a>'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Risk Thresholds -->
            <div class="fs-card" id="fs-section-thresholds">
                <div class="fs-card__title"><?php esc_html_e('Risk Thresholds','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body">
                    <div class="fs-threshold-visual">
                        <div class="fs-threshold-bar">
                            <div class="fs-threshold-bar__low" id="fs-bar-low"></div>
                            <div class="fs-threshold-bar__medium" id="fs-bar-medium"></div>
                            <div class="fs-threshold-bar__high" id="fs-bar-high"></div>
                        </div>
                        <div class="fs-threshold-labels">
                            <span style="color:#22d3a0;"><?php esc_html_e('Low (0–40)','ipgeolocation-fraudshield'); ?></span>
                            <span style="color:#f59e0b;"><?php esc_html_e('Medium (41–70)','ipgeolocation-fraudshield'); ?></span>
                            <span style="color:#f43f5e;"><?php esc_html_e('High (71–100)','ipgeolocation-fraudshield'); ?></span>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Email alert at score ≥','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <input type="number" name="ipgeofs_email_alert_score" value="<?php echo esc_attr($s('ipgeofs_email_alert_score',41)); ?>" min="1" max="100" class="fs-input fs-input--sm"/>
                            <span class="fs-hint-inline"><?php esc_html_e('Recommended: 41','ipgeolocation-fraudshield'); ?></span>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Auto-action at score ≥','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <input type="number" name="ipgeofs_auto_hold_score" value="<?php echo esc_attr($s('ipgeofs_auto_hold_score',71)); ?>" min="1" max="100" class="fs-input fs-input--sm"/>
                            <span class="fs-hint-inline"><?php esc_html_e('Recommended: 71','ipgeolocation-fraudshield'); ?></span>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('High-risk action','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <select name="ipgeofs_high_risk_action" class="fs-select">
                                <option value="hold"   <?php selected($s('ipgeofs_high_risk_action','hold'),'hold'); ?>><?php esc_html_e('Hold order for review','ipgeolocation-fraudshield'); ?></option>
                                <option value="cancel" <?php selected($s('ipgeofs_high_risk_action','hold'),'cancel'); ?>><?php esc_html_e('Auto-cancel order','ipgeolocation-fraudshield'); ?></option>
                                <option value="flag"   <?php selected($s('ipgeofs_high_risk_action','hold'),'flag'); ?>><?php esc_html_e('Flag only (no status change)','ipgeolocation-fraudshield'); ?></option>
                            </select>
                            <p class="fs-hint" style="margin-top:8px;">
                                <strong>Auto-cancel</strong> is safe for Cash on Delivery orders (no payment taken). For online payments (card, PayPal etc.), the plugin will <strong>hold instead of cancel</strong> to prevent cancelling a paid order without a refund.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signal Blocking -->
            <div class="fs-card" id="fs-section-signals">
                <div class="fs-card__title"><?php esc_html_e('Signal Blocking','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body">
                    <p class="fs-hint" style="margin-bottom:16px;"><?php esc_html_e('Each enabled signal adds points to the fraud score. Disable if causing false positives for your store.','ipgeolocation-fraudshield'); ?></p>
                    <div class="fs-toggle-grid">
                        <?php $toggles = [
                            'ipgeofs_block_country_mismatch' => [
                                __('Country mismatch','ipgeolocation-fraudshield'),
                                __('Billing country differs from the IP geolocation country','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_vpn' => [
                                __('VPN connections','ipgeolocation-fraudshield'),
                                __('IP address is identified as using a VPN service','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_tor' => [
                                __('Tor exit nodes','ipgeolocation-fraudshield'),
                                __('Traffic is routed through the Tor network (high anonymity)','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_proxy' => [
                                __('Proxy connections','ipgeolocation-fraudshield'),
                                __('IP address is detected as a proxy server','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_residential_proxy' => [
                                __('Residential proxy','ipgeolocation-fraudshield'),
                                __('IP uses a residential proxy, which is harder to detect and often used to bypass restrictions','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_known_attacker' => [
                                __('Known attacker IPs','ipgeolocation-fraudshield'),
                                __('IP is flagged in threat intelligence databases for malicious activity','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_bot' => [
                                __('Bot traffic','ipgeolocation-fraudshield'),
                                __('Request is identified as automated or non-human traffic','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_spam' => [
                                __('Spam source','ipgeolocation-fraudshield'),
                                __('IP is associated with spam or abusive behavior','ipgeolocation-fraudshield')
                            ],

                            'ipgeofs_block_cloud_provider' => [
                                __('Cloud/datacenter IP','ipgeolocation-fraudshield'),
                                __('IP belongs to a cloud or datacenter provider rather than a typical residential network','ipgeolocation-fraudshield')
                            ],
                        ];
                        foreach ($toggles as $name => [$label, $desc]): ?>
                        <div class="fs-toggle-card">
                            <div class="fs-toggle-card__info">
                                <div class="fs-toggle-card__label"><?php echo esc_html($label); ?></div>
                                <div class="fs-toggle-card__desc"><?php echo esc_html($desc); ?></div>

                                <?php
                                $force_map = [
                                    'ipgeofs_block_country_mismatch'  => 'ipgeofs_force_country_mismatch',
                                    'ipgeofs_block_vpn'               => 'ipgeofs_force_vpn',
                                    'ipgeofs_block_tor'               => 'ipgeofs_force_tor',
                                    'ipgeofs_block_proxy'             => 'ipgeofs_force_proxy',
                                    'ipgeofs_block_residential_proxy' => 'ipgeofs_force_residential_proxy',
                                    'ipgeofs_block_known_attacker'    => 'ipgeofs_force_known_attacker',
                                    'ipgeofs_block_bot'               => 'ipgeofs_force_bot',
                                    'ipgeofs_block_spam'              => 'ipgeofs_force_spam',
                                    'ipgeofs_block_cloud_provider'    => 'ipgeofs_force_cloud_provider',
                                ];

                                if (isset($force_map[$name])): 
                                    $force_key = $force_map[$name];
                                ?>
                                <div style="margin-top:6px;">
                                    <label style="font-size:12px; color:#666;">
                                        <input type="checkbox"
                                            name="<?php echo esc_attr($force_key); ?>"
                                            value="yes"
                                            <?php checked($s($force_key,'no'),'yes'); ?>
                                        >
                                        <?php esc_html_e('Treat as critical (force 100 score)', 'ipgeolocation-fraudshield'); ?>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>

                            <label class="fs-toggle">
                                <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="yes" <?php checked($s($name,'yes'),'yes'); ?>>
                                <span class="fs-toggle__track"><span class="fs-toggle__thumb"></span></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Signal Weights -->
            <div class="fs-card" id="fs-section-weights">
                <div class="fs-card__title">
                    <?php esc_html_e( 'Signal Weights', 'ipgeolocation-fraudshield' ); ?>
                </div>
                <div class="fs-card__body">
                    <p class="fs-hint" style="margin-bottom:20px;">
                        <?php esc_html_e( 'Each value (1–100) represents the relative importance of that signal.
The final score is calculated proportionally. Higher weight = more impact on the total score.', 'ipgeolocation-fraudshield' ); ?>
                    </p>
                    <?php
                    $weights = [
                        'ipgeofs_weight_country_mismatch'  => __( 'Country mismatch', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_vpn'               => __( 'VPN detected', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_tor'               => __( 'Tor exit node', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_proxy'             => __( 'Proxy detected', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_residential_proxy' => __( 'Residential proxy', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_known_attacker'    => __( 'Known attacker IP', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_bot'               => __( 'Bot traffic', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_spam'              => __( 'Spam source', 'ipgeolocation-fraudshield' ),
                        'ipgeofs_weight_cloud_provider'    => __( 'Cloud/datacenter IP', 'ipgeolocation-fraudshield' ),
                    ];
                    foreach ( $weights as $key => $label ) :
                        $val = (int) get_option( $key, 25 );
                    ?>
                    <div class="fs-weight-row">
                        <label class="fs-weight-label"><?php echo esc_html( $label ); ?></label>
                        <?php
                        $toggle_map = [
                            'ipgeofs_weight_country_mismatch'  => 'ipgeofs_block_country_mismatch',
                            'ipgeofs_weight_vpn'               => 'ipgeofs_block_vpn',
                            'ipgeofs_weight_tor'               => 'ipgeofs_block_tor',
                            'ipgeofs_weight_proxy'             => 'ipgeofs_block_proxy',
                            'ipgeofs_weight_residential_proxy' => 'ipgeofs_block_residential_proxy',
                            'ipgeofs_weight_known_attacker'    => 'ipgeofs_block_known_attacker',
                            'ipgeofs_weight_bot'               => 'ipgeofs_block_bot',
                            'ipgeofs_weight_spam'              => 'ipgeofs_block_spam',
                            'ipgeofs_weight_cloud_provider'    => 'ipgeofs_block_cloud_provider',
                        ];
                        ?>

                        <input type="range"
                            class="fs-weight-slider"
                            name="<?php echo esc_attr( $key ); ?>"
                            id="<?php echo esc_attr( $key ); ?>"
                            min="0" max="100"
                            value="<?php echo esc_attr( $val ); ?>"
                            data-toggle="<?php echo esc_attr( $toggle_map[$key] ); ?>"
                        />
                        <input type="number" class="fs-input fs-input--sm fs-weight-num"
                            value="<?php echo esc_attr( $val ); ?>" min="0" max="100"
                            data-for="<?php echo esc_attr( $key ); ?>" readonly/>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Alerts & Logging -->
            <div class="fs-card" id="fs-section-alerts">
                <div class="fs-card__title"><?php esc_html_e('Alerts & Logging','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body">
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Alert email','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <input type="email" name="ipgeofs_alert_email" value="<?php echo esc_attr($s('ipgeofs_alert_email',get_option('admin_email'))); ?>" class="fs-input"/>
                            <p class="fs-hint"><?php esc_html_e('Fraud alerts are sent here for medium and high risk orders.','ipgeolocation-fraudshield'); ?></p>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e( 'Dashboard widget', 'ipgeolocation-fraudshield' ); ?></label>
                        <div class="fs-field-input">
                            <label class="fs-toggle">
                                <input type="checkbox" name="ipgeofs_show_dashboard_widget" value="yes"
                                    <?php checked( get_option( 'ipgeofs_show_dashboard_widget', 'yes' ), 'yes' ); ?> />
                                <span class="fs-toggle__track"><span class="fs-toggle__thumb"></span></span>
                            </label>
                            <span class="fs-hint-inline">
                                <?php esc_html_e( 'Show FraudShield activity widget on the WordPress dashboard', 'ipgeolocation-fraudshield' ); ?>
                            </span>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Log all orders','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <label class="fs-toggle">
                                <input type="checkbox" name="ipgeofs_log_all" value="yes" <?php checked($s('ipgeofs_log_all','yes'),'yes'); ?>>
                                <span class="fs-toggle__track"><span class="fs-toggle__thumb"></span></span>
                            </label>
                            <p class="fs-hint"><?php esc_html_e('If off, only medium and high risk orders are stored in the log.','ipgeolocation-fraudshield'); ?></p>
                        </div>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e( 'Log retention period', 'ipgeolocation-fraudshield' ); ?></label>
                        <div class="fs-field-input">
                            <input type="number" name="ipgeofs_log_retention_days"
                                value="<?php echo esc_attr( $s( 'ipgeofs_log_retention_days', 30 ) ); ?>"
                                min="7" max="365" class="fs-input fs-input--sm"/>
                            <span class="fs-hint-inline"><?php esc_html_e( 'days. Logs older than this are deleted automatically', 'ipgeolocation-fraudshield' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Mode -->
            <div class="fs-card fs-card--test" id="fs-section-test">
                <div class="fs-card__title"><?php esc_html_e('Test Mode','ipgeolocation-fraudshield'); ?></div>
                <div class="fs-card__body">
                    <div class="fs-test-mode-banner">
                        <?php esc_html_e('Use test mode to simulate fraud scenarios without modifying your real orders. When enabled, the test IP overrides the real buyer IP for ALL new orders.','ipgeolocation-fraudshield'); ?>
                    </div>
                    <div class="fs-field-row">
                        <label class="fs-label"><?php esc_html_e('Enable test mode','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <label class="fs-toggle fs-toggle--warning">
                                <input type="checkbox" name="ipgeofs_test_mode" value="yes" id="fs-test-mode-toggle" <?php checked($s('ipgeofs_test_mode'),'yes'); ?>>
                                <span class="fs-toggle__track"><span class="fs-toggle__thumb"></span></span>
                            </label>
                        </div>
                    </div>
                    <?php $test_row_style = $s('ipgeofs_test_mode') === 'yes' ? '' : 'opacity:0.4;pointer-events:none;'; ?>
                    <div class="fs-field-row" id="fs-test-ip-row" style="<?php echo esc_attr( $test_row_style ); ?>">
                        <label class="fs-label"><?php esc_html_e('Test IP address','ipgeolocation-fraudshield'); ?></label>
                        <div class="fs-field-input">
                            <input type="text" name="ipgeofs_test_ip" id="fs-test-ip" value="<?php echo esc_attr($s('ipgeofs_test_ip','185.220.101.1')); ?>" class="fs-input fs-input--mono" placeholder="e.g. 185.220.101.1"/>
                            <div class="fs-test-presets">
                                <span class="fs-hint" style="margin-right:8px;"><?php esc_html_e('Quick presets:','ipgeolocation-fraudshield'); ?></span>
                                <button type="button" class="fs-preset-btn" data-ip="185.220.101.1">Tor exit</button>
                                <button type="button" class="fs-preset-btn" data-ip="2.56.188.34">VPN/Proxy</button>
                                <button type="button" class="fs-preset-btn" data-ip="100.0.223.242">Residential Proxy</button>
                                <button type="button" class="fs-preset-btn" data-ip="8.8.8.8">Google DNS</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fs-form-footer">
                <button type="submit" class="fs-btn fs-btn--primary fs-btn--lg" id="fs-save-btn">
                    <span class="fs-btn__text"><?php esc_html_e('Save Settings','ipgeolocation-fraudshield'); ?></span>
                </button>
                <span class="fs-save-status" id="fs-save-status"></span>
            </div>

        </form>
        </div>
        </div>
        <?php
    }

    private function save_settings() {
        $bool_fields = [
            'ipgeofs_enabled',
            'ipgeofs_block_vpn',
            'ipgeofs_block_tor',
            'ipgeofs_block_proxy',
            'ipgeofs_block_bot',
            'ipgeofs_log_all',
            'ipgeofs_show_dashboard_widget',
            'ipgeofs_test_mode',
            'ipgeofs_block_residential_proxy',
            'ipgeofs_block_known_attacker',
            'ipgeofs_block_country_mismatch',
            'ipgeofs_block_spam',
            'ipgeofs_block_cloud_provider',
            'ipgeofs_force_country_mismatch',
            'ipgeofs_force_vpn',
            'ipgeofs_force_tor',
            'ipgeofs_force_proxy',
            'ipgeofs_force_residential_proxy',
            'ipgeofs_force_known_attacker',
            'ipgeofs_force_bot',
            'ipgeofs_force_spam',
            'ipgeofs_force_cloud_provider'
        ];
        foreach ( $bool_fields as $f ) update_option( $f, isset($_POST[$f]) ? 'yes' : 'no' );

        $text_fields = ['ipgeofs_api_key','ipgeofs_alert_email','ipgeofs_high_risk_action','ipgeofs_test_ip'];
        foreach ( $text_fields as $f ) {
            if ( isset($_POST[$f]) ) update_option( $f, sanitize_text_field($_POST[$f]) );
        }
        $weight_fields = [
            'ipgeofs_weight_country_mismatch',
            'ipgeofs_weight_vpn',
            'ipgeofs_weight_tor',
            'ipgeofs_weight_proxy',
            'ipgeofs_weight_residential_proxy',
            'ipgeofs_weight_known_attacker',
            'ipgeofs_weight_bot',
            'ipgeofs_weight_spam',
            'ipgeofs_weight_cloud_provider',    
        ];
        foreach ( $weight_fields as $f ) {
            if ( isset( $_POST[$f] ) ) {
                update_option( $f, max( 0, min( 100, absint( $_POST[$f] ) ) ) );
            }
        }
        update_option( 'ipgeofs_auto_hold_score',   absint($_POST['ipgeofs_auto_hold_score'] ?? 71) );
        update_option( 'ipgeofs_email_alert_score', absint($_POST['ipgeofs_email_alert_score'] ?? 41) );
        update_option( 'ipgeofs_log_retention_days', absint( $_POST['ipgeofs_log_retention_days'] ?? 30 ) );
    }


    private function render_header( string $page_title ) {
        $api_set = (bool) get_option('ipgeofs_api_key');
        $enabled = get_option('ipgeofs_enabled') === 'yes';
        $test_on = get_option('ipgeofs_test_mode') === 'yes';
        ?>
        <div class="fs-page-header">
            <div class="fs-page-header__left">
                <div>
                    <h1 class="fs-page-header__title">FraudShield <span class="fs-version-pill">v<?php echo esc_html(IPGEOFS_VERSION); ?></span></h1>
                    <div class="fs-page-header__sub"><?php echo esc_html($page_title); ?></div>
                </div>
            </div>
            <div class="fs-page-header__status">
                <?php if ($test_on): ?><span class="fs-status-badge fs-status-badge--warn">Test Mode</span><?php endif; ?>
                <?php if (!$api_set): ?><span class="fs-status-badge fs-status-badge--error">⚠ No API Key</span>
                <?php elseif (!$enabled): ?><span class="fs-status-badge fs-status-badge--off">Off</span>
                <?php else: ?><span class="fs-status-badge fs-status-badge--on">● Active</span><?php endif; ?>
                <div class="fs-page-header__nav">
                    <?php foreach([
                        'ipgeolocation-fraudshield'          => __('Dashboard','ipgeolocation-fraudshield'),
                        'ipgeolocation-fraudshield-logs'     => __('Logs','ipgeolocation-fraudshield'),
                        'ipgeolocation-fraudshield-settings' => __('Settings','ipgeolocation-fraudshield'),
                    ] as $page => $label):
                        $active = ($_GET['page'] ?? '') === $page ? 'fs-nav-tab fs-nav-tab--active' : 'fs-nav-tab';
                    ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page='.$page)); ?>" class="<?php echo esc_attr($active); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_orders_table( array $logs ) {
        if ( empty($logs) ) { echo '<div class="fs-empty">' . esc_html__('No orders checked yet.','ipgeolocation-fraudshield') . '</div>'; return; }
        echo '<table class="fs-table fs-table--compact">';
        echo '<thead><tr><th>' . esc_html__('Order','ipgeolocation-fraudshield') . '</th><th>' . esc_html__('Score','ipgeolocation-fraudshield') . '</th><th>' . esc_html__('IP','ipgeolocation-fraudshield') . '</th><th>' . esc_html__('Countries','ipgeolocation-fraudshield') . '</th><th>' . esc_html__('Date','ipgeolocation-fraudshield') . '</th></tr></thead><tbody>';
        foreach ( $logs as $log ) {
            $tier_cls = 'fs-pill fs-pill--' . $log->risk_tier;
            $order_url = admin_url('post.php?post='.$log->order_id.'&action=edit');
            echo '<tr>';
            echo '<td><a href="' . esc_url($order_url) . '" class="fs-order-link">#' . (int)$log->order_id . '</a></td>';
            echo '<td><span class="' . esc_attr($tier_cls) . '">' . (int)$log->risk_score . ' ' . esc_html(strtoupper($log->risk_tier)) . '</span></td>';
            echo '<td><code class="fs-code">' . esc_html($log->ip_address) . '</code></td>';
            echo '<td><span class="fs-flag">' . esc_html($log->ip_country ?: '?') . '</span> → <span class="fs-flag">' . esc_html($log->billing_country ?: '?') . '</span></td>';
            echo '<td class="fs-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($log->created_at))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
