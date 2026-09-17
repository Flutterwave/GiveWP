<?php
/**
 * Remove Flutterwave credentials and processing locks when the plugin is deleted.
 * GiveWP may not be loaded here, so the give_settings option is edited directly.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$give_flutterwave_options = [
	'give_flutterwave_live_secret_key',
	'give_flutterwave_test_secret_key',
	'give_flutterwave_webhook_secret',
	'give_flutterwave_secret_key',
	'give_flutterwave_public_key',
	'give_flutterwave_mode',
];

$give_settings = get_option('give_settings');
if (is_array($give_settings)) {
	foreach ($give_flutterwave_options as $give_flutterwave_option) {
		unset($give_settings[$give_flutterwave_option]);
	}
	update_option('give_settings', $give_settings);
}

global $wpdb;
$wpdb->query($wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$wpdb->esc_like('give_flutterwave_lock_') . '%'
));
