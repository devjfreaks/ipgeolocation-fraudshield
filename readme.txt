=== IPGeolocation FraudShield ===
Contributors: ipgeolocation
Tags: woocommerce, fraud detection, fake orders, ip geolocation
Requires at least: 5.8
Tested up to:   7.1
Stable tag: 1.0.0
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to:   9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Detect and manage fraudulent WooCommerce orders using IP-based risk scoring.

== Description ==

FraudShield checks the buyer's IP address when an order is placed and assigns a fraud risk score from 0 to 100. Based on that score, orders can be automatically held, cancelled, or flagged for manual review. The store admin can also receive email alerts with a full breakdown of what triggered the score.

The check runs after order creation so it has no impact on checkout speed or customer experience.

= How it works =

FraudShield connects to the ipgeolocation.io Security API and evaluates the response against the fraud signals you have enabled. The score is calculated as:

(sum of triggered signal weights) / (sum of enabled signal weights) x 100

This keeps scoring consistent regardless of how many signals you have turned on.

= Fraud signals =

* Country mismatch (billing country vs IP country)
* VPN detected
* Tor exit node
* Proxy detected
* Residential proxy
* Known attacker IP
* Bot traffic
* Spam source
* Cloud or datacenter IP

= Risk tiers =

* Low: 0 to 40
* Medium: 41 to 70
* High: 71 to 100

= Features =

* Automatic fraud check on every new order
* Toggle individual signals on or off
* Adjustable weight per signal
* Critical override option to force a score of 100 on any signal
* Hold, cancel, or flag high-risk orders automatically
* Email alerts with signal breakdown
* Configurable score thresholds for alerts and actions
* Dashboard with order stats and trends
* Fraud log with filtering and search
* Risk score column in the orders list
* Per-order fraud details panel in the admin
* Test mode with predefined IPs for safe testing
* Log retention control

= External service =

This plugin uses the ipgeolocation.io API to look up IP address data. When an order is placed, the customer's IP address is sent to ipgeolocation.io servers to retrieve security information used for fraud scoring. No other personal data is transmitted.

Please review their policies before using this plugin:

* ipgeolocation.io website: https://ipgeolocation.io
* Terms of service: https://ipgeolocation.io/tos.html
* Privacy policy: https://ipgeolocation.io/privacy

An API key from ipgeolocation.io is required to use this plugin. A free plan is available. Some signals require a paid plan.

== Development ==

Development takes place on GitHub: https://github.com/devjfreaks/fraudshield

== Installation ==

1. Go to Plugins > Add New in your WordPress admin.
2. Search for FraudShield and click Install Now.
3. Or click Upload Plugin and upload the plugin ZIP file.
4. Activate the plugin from the Plugins screen.
5. Go to FraudShield > Settings.
6. Enter your ipgeolocation.io API key and click Test Key to verify it works.
7. Configure your signals, weights, and thresholds.
8. Click Save Settings.

To get an API key, register at https://ipgeolocation.io, then copy the key from your dashboard and paste it into the plugin settings.

Make sure SMTP is configured on your WordPress site if you want to receive email alerts.

== Frequently Asked Questions ==

= Does it slow down checkout? =
No. The fraud check runs after the order is created, so customers are not affected.

= Do I need a paid plan? =
A free plan is available but some signals such as residential proxy detection require a paid plan at ipgeolocation.io.

= What happens to high-risk orders? =
Depending on your settings, they can be automatically held, cancelled, or flagged for manual review.

= What data is sent to the external service? =
Only the customer's IP address is sent to ipgeolocation.io. No names, emails, or payment details are transmitted.

= Does auto-cancel work with online payments? =
If auto-cancel is configured and the order was paid online, FraudShield will hold the order instead of cancelling it. This prevents cancelling a paid order without issuing a refund. A note is added to the order explaining what happened and what action the merchant should take.

= Can I test it without real orders? =
Yes. Enable test mode in the settings and choose a predefined IP to simulate different risk scenarios. Remember to turn off test mode and save settings when you are done, otherwise all real orders will use the test IP.

= What if my API key quota runs out? =
The plugin will skip the fraud check and log the error silently. Orders will not be blocked and customers will not see any errors.

== Support ==

For help with setup or configuration, contact us at https://ipgeolocation.io/contact.html


== Third Party Libraries ==

This plugin includes Chart.js v4.5.1 for admin dashboard charts.
License: MIT
Source: https://www.chartjs.org
License URI: https://github.com/chartjs/Chart.js/blob/master/LICENSE.md

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release
