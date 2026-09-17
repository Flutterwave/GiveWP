=== Flutterwave for GiveWP ===
Contributors: Abraham-Flutterwave
Tags: give, payments, flutterwave, donations, charity
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept GiveWP donations through Flutterwave's hosted checkout.

== Description ==
Flutterwave for GiveWP adds Flutterwave as a payment gateway for GiveWP donation forms. Donors are redirected to Flutterwave's hosted checkout to pay, and every payment is verified with the Flutterwave API before a donation is marked complete.

Features:
* Flutterwave v3 hosted checkout
* Payment verification on the donor's return and through webhooks
* A donation is completed only when the Flutterwave reference, amount and currency match it
* Separate live and test secret keys, selected by GiveWP test mode
* Supported currencies: NGN, GHS, USD, EUR, GBP, XAF, EGP, RWF, SLL, ZAR, TZS, UGX, XOF, ZMW

== Requirements ==
* WordPress 6.0 or greater
* GiveWP 4.5.0 or greater
* PHP 7.4 or greater
* A Flutterwave account
* HTTPS on the site (required to receive webhooks securely)

== Installation ==
1. Install the plugin from the release zip (built with `wp dist-archive .`). Do not deploy a git clone: it includes development files.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Donations → Settings → Payment Gateways and enable "Flutterwave".
4. Open the Flutterwave section and enter your Live Secret Key and Test Secret Key.
5. Set a webhook secret hash, then add the webhook URL and the same secret hash in your Flutterwave dashboard (see Webhooks).

== Configuration ==
Settings are in Donations → Settings → Payment Gateways → Flutterwave:

* Live Secret Key: starts with FLWSECK-. Used when GiveWP test mode is off.
* Test Secret Key: starts with FLWSECK_TEST-. Used when GiveWP test mode is on.
* Webhook Secret Hash: required, at least 16 characters.

A key that does not match the current mode is refused, and an admin notice explains what to fix.

== Webhooks ==
The webhook URL is shown under the Webhook Secret Hash setting. In the Flutterwave dashboard, add that URL as your webhook and set the same secret hash in both places.

Security:
* Webhooks are rejected unless the secret hash is configured and matches the verif-hash header sent by Flutterwave.
* The webhook body is never trusted. Every notification is re-verified with the Flutterwave API, and a donation is completed only when the reference, amount and currency match.

== External Services ==
This plugin connects to the Flutterwave API (https://api.flutterwave.com) to create checkout sessions and verify payments.

* When a donor submits a donation, the plugin sends the donation amount, currency, a transaction reference, the donor's email address and a return URL to Flutterwave.
* When the donor returns or a webhook arrives, the plugin sends the transaction reference to Flutterwave to verify the payment.

The service is provided by Flutterwave. See Flutterwave's terms of service and privacy policy at https://flutterwave.com.

== Frequently Asked Questions ==
= How do I enable test mode? =
Turn on test mode in GiveWP (Donations → Settings → Payment Gateways) and enter your Test Secret Key. The plugin uses the test key while GiveWP test mode is on and the live key otherwise.

= Where do I set the webhook secret? =
In Donations → Settings → Payment Gateways → Flutterwave. Use the same secret hash in the Flutterwave dashboard webhook settings.

= Why is my donation not marked complete? =
Check that a webhook secret hash of at least 16 characters is set in both GiveWP and Flutterwave, that the secret key matches GiveWP test mode, and review the gateway logs in GiveWP.

== Screenshots ==
1. Flutterwave gateway settings in GiveWP
2. Donation checkout with Flutterwave option
3. Webhook logs / admin view

== Changelog ==
= 1.0.1 - 2026-09-17 =
Security release. All users should update.

* Security: The return URL is now signed, and a donation is completed only when the Flutterwave reference, amount and currency match it. Previously a small payment could complete a larger donation.
* Security: Webhooks now require a secret hash of at least 16 characters and are re-verified with the Flutterwave API.
* Security: Invalid return requests can no longer mark other donations as failed.
* Security: Refunded or completed donations are never overwritten, and a lock prevents the webhook and the donor's return from completing a donation twice.
* Security: Separate live and test secret keys; a key that does not match GiveWP test mode is refused.
* Security: Checkout redirects are limited to Flutterwave URLs.
* Security: Donors see a generic error message, logs no longer store customer details, and keys are masked on the settings screen.
* Security: Credentials are deleted when the plugin is uninstalled.
* Fix: Donations in zero-decimal currencies (UGX, RWF, XAF, XOF) were charged 1% of the amount.
* Fix: The webhook listener was not connected to GiveWP.
* Fix: Checkout now sends tx_ref as required by the Flutterwave v3 API.
* Fix: Cancelled checkouts now mark the donation as cancelled.
* Change: Requires GiveWP 4.5.0 or greater.
* Change: The old single secret key is moved to the Live or Test key field, and the unused Public Key and Mode settings are removed.
* Change: The webhook URL has changed; copy it from the settings screen into the Flutterwave dashboard.

= 1.0.0 =
* Initial release: basic payment processing, webhook handling, settings UI.

== Upgrade Notice ==
= 1.0.1 =
Security release. After updating, set a webhook secret hash of at least 16 characters, copy the new webhook URL into the Flutterwave dashboard, and check that your secret keys match GiveWP test mode.

= 1.0.0 =
Initial public release.

== Support ==
For issues and support, open an issue in the source repository and include reproduction steps and environment details.

== Source Code ==
Repository: https://github.com/Flutterwave/GiveWP
