# Changelog

All notable changes to Flutterwave for GiveWP are documented here.

## [1.0.1] - 2026-09-17

Security release. All users should update.

### Security
- The return URL is now a signed GiveWP route, and a donation is completed only when the verified Flutterwave `tx_ref` matches a reference stored for that donation and the verified amount and currency match the donation. Previously anyone could complete any donation using the reference of a small successful payment.
- Webhooks now require a secret hash of at least 16 characters (`verif-hash` header, compared in constant time). The webhook body is never trusted; every notification is re-verified with the Flutterwave API.
- Invalid or forged return requests no longer mark donations as failed.
- Refunded and completed donations are never overwritten. Only pending donations can be marked failed or cancelled.
- A per-donation lock prevents the webhook and the donor's return from completing the same donation twice.
- Separate live and test secret keys, selected by GiveWP test mode. A key whose prefix does not match the mode is refused, so a test key cannot complete live donations.
- Checkout redirects are limited to `https://*.flutterwave.com`.
- Donors see a generic error when checkout cannot start; API details are logged only. Logs no longer store customer details.
- Secret keys and the webhook secret use masked settings fields.
- Webhook rejections return a generic `401 Unauthorized`.
- Admin notices warn about mismatched keys, a missing or short webhook secret, and a non-HTTPS webhook URL.
- Credentials are deleted on uninstall (`uninstall.php`).
- Added `.distignore` and `index.php` files; test files only run from the command line.

### Fixed
- Donations in zero-decimal currencies (UGX, RWF, XAF, XOF) were charged 1% of the donation amount.
- The webhook listener (`webhookNotificationsListener`) was missing, so GiveWP never delivered webhooks to the plugin.
- `getWebhookUrl()` called a method that does not exist.
- Checkout now sends `tx_ref`, as required by the Flutterwave v3 API.
- Cancelled checkouts now mark the pending donation as cancelled.
- Paying through an earlier checkout link still completes the donation.
- The amount-mismatch donation note is added once per reference instead of on every webhook retry.
- Global function names are now prefixed with `give_flutterwave_` to avoid collisions.

### Changed
- Requires GiveWP 4.5.0 or greater.
- The single secret key setting is migrated to the Live or Test key field. The unused Public Key and Mode settings are removed.
- The webhook URL is now GiveWP's gateway route URL, shown on the settings screen. Update it in the Flutterwave dashboard.
- The gateway transaction ID is now the Flutterwave transaction ID.
- Removed unused `assets/js/flutterwave-inline.js`.

### Upgrade notes
1. Set a webhook secret hash of at least 16 characters in GiveWP and in the Flutterwave dashboard.
2. Copy the webhook URL from the settings screen into the Flutterwave dashboard.
3. Check that the Live and Test secret keys are correct for GiveWP test mode.

## [1.0.0]

- Initial release: basic payment processing, webhook handling, settings UI.
