<?php
defined( 'ABSPATH' ) || exit;

class IPGEOFS_Mailer {

    public static function send_alert( WC_Order $order, array $result, bool $test_mode = false ) {
        $to       = get_option( 'ipgeofs_alert_email', get_option( 'admin_email' ) );
        $score    = $result['score'];
        $tier     = strtoupper( $result['tier'] );
        $order_id = $order->get_id();

        $subject = sprintf( '[FraudShield%s] %s RISK: Score %d/100 on Order #%s',
            $test_mode ? ' TEST' : '', $tier, $score, $order_id );

        $tier_color = [ 'HIGH' => '#f43f5e', 'MEDIUM' => '#f59e0b', 'LOW' => '#22d3a0' ][ $tier ] ?? '#888';
        $order_url  = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

        $signal_rows = '';
        foreach ( $result['signals'] as $sig ) {
            if ( ! $sig['flagged'] ) continue;
            $signal_rows .= '<tr>
                <td style="padding:8px 14px;border-bottom:1px solid #f0f0f0;font-size:13px;">' . esc_html($sig['label']) . '</td>
                <td style="padding:8px 14px;border-bottom:1px solid #f0f0f0;font-size:12px;color:#888;">' . esc_html($sig['detail']) . '</td>
            </tr>';
        }
        if ( ! $signal_rows ) $signal_rows = '<tr><td colspan="3" style="padding:8px 14px;font-size:13px;color:#888;">No specific signals flagged.</td></tr>';

        $test_banner = $test_mode
            ? '<tr><td style="background:#fff8e6;border-bottom:3px solid #f59e0b;padding:10px 32px;font-size:12px;color:#7a5a00;text-align:center;"><strong>TEST MODE</strong>: This alert was triggered using a test IP address</td></tr>'
            : ''; 

        $message = '<!DOCTYPE html><html><head><meta charset="UTF-8"/></head><body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  ' . $test_banner . '
  <tr><td style="background:#0a0a1a;padding:22px 32px;text-align:center;">
    <div style="font-size:20px;font-weight:800;color:#fff;">🛡 FraudShield</div>
    <div style="font-size:11px;color:#666;margin-top:3px;">powered by ipgeolocation.io</div>
  </td></tr>
  <tr><td style="background:' . esc_attr($tier_color) . '18;border-bottom:3px solid ' . esc_attr($tier_color) . ';padding:24px 32px;text-align:center;">
    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:' . esc_attr($tier_color) . ';">' . esc_html($tier) . ' RISK ORDER</div>
    <div style="font-size:52px;font-weight:800;color:' . esc_attr($tier_color) . ';line-height:1.1;margin:8px 0;">' . (int)$score . '<span style="font-size:18px;color:#aaa;">/100</span></div>
    <div style="font-size:13px;color:#666;">Order #' . (int)$order_id . ': ' . $order->get_formatted_order_total() . '</div>
  </td></tr>
  <tr><td style="padding:24px 32px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#aaa;margin-bottom:12px;">ORDER DETAILS</div>
    <table width="100%" style="font-size:13px;">
      <tr><td style="padding:5px 0;color:#888;width:40%;">Customer</td><td>' . esc_html($order->get_billing_first_name().' '.$order->get_billing_last_name()) . '</td></tr>
      <tr><td style="padding:5px 0;color:#888;">Email</td><td>' . esc_html($order->get_billing_email()) . '</td></tr>
      <tr><td style="padding:5px 0;color:#888;">IP Address</td><td style="font-family:monospace;">' . esc_html($order->get_customer_ip_address()) . '</td></tr>
      <tr><td style="padding:5px 0;color:#888;">IP Country</td><td>' . esc_html($result['ip_country'] ?: '') . '</td></tr>
      <tr><td style="padding:5px 0;color:#888;">Billing Country</td><td>' . esc_html($order->get_billing_country()) . '</td></tr>
      <tr><td style="padding:5px 0;color:#888;">Payment Method</td><td>' . esc_html($order->get_payment_method_title()) . '</td></tr>
    </table>
  </td></tr>
  <tr><td style="padding:0 32px 24px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#aaa;margin-bottom:12px;">FRAUD SIGNALS</div>
    <table width="100%" style="border:1px solid #eee;border-radius:8px;border-collapse:separate;border-spacing:0;overflow:hidden;">
      <thead><tr style="background:#f8f8f8;">
        <th style="padding:8px 14px;font-size:11px;color:#aaa;text-align:left;font-weight:700;text-transform:uppercase;">Signal</th>
        <th style="padding:8px 14px;font-size:11px;color:#aaa;text-align:left;font-weight:700;text-transform:uppercase;">Detail</th>
      </tr></thead>
      <tbody>' . $signal_rows . '</tbody>
    </table>
  </td></tr>
  <tr><td style="padding:0 32px 32px;text-align:center;">
    <a href="' . esc_url($order_url) . '" style="display:inline-block;background:#6c63ff;color:#fff;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;text-decoration:none;">Review Order #' . (int)$order_id . ' →</a>
  </td></tr>
  <tr><td style="background:#f8f8f8;padding:14px 32px;text-align:center;border-top:1px solid #eee;">
    <div style="font-size:11px;color:#bbb;">Sent by FraudShield · <a href="https://ipgeolocation.io" style="color:#6c63ff;">ipgeolocation.io</a></div>
    <div style="font-size:11px;color:#bbb;margin-top:3px;"><a href="' . esc_url(admin_url('admin.php?page=ipgeolocation-fraudshield-settings')) . '" style="color:#6c63ff;">Manage alert settings</a></div>
  </td></tr>
</table>
</td></tr></table></body></html>';

        add_filter( 'wp_mail_content_type', [ __CLASS__, 'html_content_type' ] );
        wp_mail( $to, $subject, $message );
        remove_filter( 'wp_mail_content_type', [ __CLASS__, 'html_content_type' ] );
    }

    public static function html_content_type(): string { return 'text/html'; }
}
