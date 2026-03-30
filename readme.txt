=== Flutterwave for GiveWP ===
Contributors: Abraham-Flutterwave
Tags: give, payments, flutterwave, donations, charity
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Short Description
Integrates Flutterwave as a payment gateway for GiveWP to accept donations via Flutterwave.

== Description ==
The Flutterwave Gateway for GiveWP adds a Flutterwave payment option to GiveWP donation forms so donations can be processed using Flutterwave's hosted checkout and API. Built for both sandbox and live environments with webhook handling and secure API key configuration.

Features:
* Support for Flutterwave API (sandbox & production)
* Webhook handler for asynchronous payment notifications
* Mapping of Flutterwave payment statuses to GiveWP statuses
* Configurable API keys and environment via settings
* Test/sandbox mode and live mode

== Requirements ==
* PHP 7.4 or greater
* GiveWP plugin (compatible version noted in plugin data)
* WordPress 6.0+

== Installation ==
1. Upload the plugin folder to wp-content/plugins/ or install via zip.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Donations → Settings → Payment Gateways and enable "Flutterwave".
4. Configure your API credentials and environment on the settings page.
5. (Optional) Configure webhook endpoint in the Flutterwave dashboard (see Webhooks section).

== Configuration ==
Settings available in GiveWP → Settings → Payment Gateways → Flutterwave:
* Public Key and Secret Key
* Environment: test | production
* Webhook secret (used to validate incoming webhooks)
* Test mode toggle


== Webhooks ==
Recommended webhook endpoint: /wp-json/flutterwave/v1/webhook
Register the endpoint URL in the Flutterwave dashboard for the desired events.

Supported webhook events (examples):
* charge.completed — mark donation as completed
* charge.failed    — mark donation as failed

Security:
* Validate webhook signatures using the webhook secret configured in settings.
* Ensure the webhook endpoint accepts only POST and checks content type.
* Respond quickly (HTTP 200) and process heavier tasks asynchronously.

== External Services ==
This plugin integrates with:
* Flutterwave API — payment processing and webhooks
* GiveWP — donation management in WordPress

== Contribution Guidelines ==
Contributions welcome. Please:
1. Fork the repository.
2. Create feature branches from main.
3. Write tests for new features.
4. Follow PSR-12 for PHP and project linting rules.
5. Open PRs with clear description and testing notes.

== Screenshots ==
1. screenshot-1.png — Flutterwave gateway settings in GiveWP
2. screenshot-2.png — Donation checkout with Flutterwave option
3. screenshot-3.png — Webhook logs / admin view

== Frequently Asked Questions ==
Q: How do I enable test mode?
A: Select 'test' in plugin settings.

Q: Where do I set the webhook secret?
A: In the Flutterwave gateway settings in GiveWP. Use the same secret in the Flutterwave dashboard webhook configuration.

== Changelog ==
= 1.0.0 =
* Initial release: basic payment processing, webhook handling, settings UI.

== Upgrade Notice ==
= 1.0.0 =
Initial public release. Follow upgrade/testing notes in CHANGELOG.md if present.

== Notes ==
- Add a LICENSE file at repository root if not present.
- Replace placeholder contributor usernames and screenshots with real assets before release.

== Support ==
For issues and support, open an issue in the source repository and include reproduction steps and environment details.

== Source Code ==
Repository: https://github.com/Flutterwave/GiveWP
