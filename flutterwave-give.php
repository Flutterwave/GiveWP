<?php
/**
 * Plugin Name: Flutterwave for Give
 * Plugin URI: https://developers.flutterwave.com/
 * Description: Accept donations via Flutterwave (hosted checkout) in GiveWP.
 * Version: 1.0.0
 * Author: Flutterwave
 * Author URI: https://app.flutterwave.com/
 * Developer: Flutterwave Developers
 * Developer URI: https://developers.flutterwave.com/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: flutterwave-give
 * Requires Plugins: give
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) exit;

if ( !defined( 'GIVE_FLUTTERWAVE_FILE' ) ) {
	define( 'GIVE_FLUTTERWAVE_FILE', __FILE__ );
}

if ( !defined( 'GIVE_FLUTTERWAVE_PATH' ) ) {
	define( 'GIVE_FLUTTERWAVE_PATH', plugin_dir_path( __FILE__ ) );
}

if ( !defined( 'GIVE_FLUTTERWAVE_URL' ) ) {
	define( 'GIVE_FLUTTERWAVE_URL', plugin_dir_url( __FILE__ ) );
}

if ( !defined( 'GIVE_FLUTTERWAVE_VER' ) ) {
	define( 'GIVE_FLUTTERWAVE_VER', '1.0.0' );
}

if ( !defined( 'GIVE_FLUTTERWAVE_MIN_GIVE_VERSION' ) ) {
	define( 'GIVE_FLUTTERWAVE_MIN_GIVE_VERSION', '4.5.0' );
}

/**
 * Register plugin settings link.
 *
 * @since 1.0.0
 *
 * @param array $links
 * @return array
 */
function give_flutterwave_register_settings_link( $links ) {
	$url = admin_url( 'edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=flutterwave' );
	$label = esc_html__( 'Settings', 'flutterwave-give' );

	$settings_link = '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Remove Flutterwave from the gateway list if it is disabled or currency is not supported.
 *
 * @since 3.0.2
 */
function give_flutterwave_filter_gateway( $gateways, $form_id ) {

	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return $gateways;
	}

	// Sanitize request URI to satisfy WP coding standards.
	$request_uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	$request_uri = wp_check_invalid_utf8( $request_uri );

	// Skip gateway filtering on create Give form donation page
	if ( false !== strpos( $request_uri, '/wp-admin/post-new.php?post_type=give_forms' ) ) {
		return $gateways;
	}

	if ( $form_id ) {
		$is_supported_currency = in_array( give_get_currency( $form_id ), \GiveFlutterwave\Give_Flutterwave_Gateway::SUPPORTED_CURRENCIES, true );
		$is_enabled = give_is_setting_enabled( give_get_meta( $form_id, 'flutterwave_customize_flutterwave_donations', true, 'global' ), [ 'enabled', 'global' ] );

		if ( ! $is_supported_currency || ! $is_enabled ) {
			unset( $gateways['flutterwave'] );
		}
	}

	return $gateways;
}


/**
 * Warn admins about unsafe or incomplete Flutterwave configuration.
 */
function give_flutterwave_config_notice() {
	if ( ! current_user_can( 'manage_give_settings' ) || ! give_is_gateway_active( \GiveFlutterwave\Give_Flutterwave_Gateway::id() ) ) {
		return;
	}

	$webhook_url = '';
	try {
		$webhook_url = \GiveFlutterwave\Give_Flutterwave_Gateway::webhook()->getNotificationUrl();
	} catch ( \Throwable $e ) {
		// Skip the HTTPS check if GiveWP cannot build the URL.
	}

	$problems = \GiveFlutterwave\Give_Flutterwave_Gateway::getConfigurationProblems( give_is_test_mode(), $webhook_url );

	foreach ( $problems as $problem ) {
		echo '<div class="notice notice-error"><p><strong>Flutterwave for GiveWP:</strong> ' . esc_html( $problem ) . '</p></div>';
	}
}

/**
 * Register Flutterwave as a payment method in GiveWP.
 *
 * @since 4.0.0
 */
function give_flutterwave_register_gateway( $registrar ) {
	$registrar->registerGateway( \GiveFlutterwave\Give_Flutterwave_Gateway::class );
}

add_action('plugins_loaded', function () {
	// WebhookNotificationsListener (used by the gateway class) was added in GiveWP 4.5.0.
	if (!class_exists('Give') || !defined('GIVE_VERSION') || version_compare(GIVE_VERSION, GIVE_FLUTTERWAVE_MIN_GIVE_VERSION, '<')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Flutterwave for GiveWP</strong> requires GiveWP ' . esc_html(GIVE_FLUTTERWAVE_MIN_GIVE_VERSION) . ' or later to be active.</p></div>';
		});
		return;
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'give_flutterwave_register_settings_link' );

	if (!class_exists('GiveFlutterwave\Give_Flutterwave_Gateway')) {
		require_once GIVE_FLUTTERWAVE_PATH . 'includes/admin/settings.php';
		require_once GIVE_FLUTTERWAVE_PATH . 'includes/class-flutterwave-give-gateway.php';

		add_action( 'givewp_register_payment_gateway', 'give_flutterwave_register_gateway' );

		// Register gateway in GiveWP’s list.
		add_filter('give_payment_gateways', function ($gateways) {
			$gateways[\GiveFlutterwave\Give_Flutterwave_Gateway::id()] = [
				'admin_label'    => esc_html__('Flutterwave', 'flutterwave-give'),
				'checkout_label' => esc_html__('Flutterwave', 'flutterwave-give'),
			];

			return $gateways;
		});

		add_filter('give_enabled_payment_gateways', 'give_flutterwave_filter_gateway', 10, 2);

		\GiveFlutterwave\Give_Flutterwave_Gateway::migrateLegacySettings();

		add_action('admin_notices', 'give_flutterwave_config_notice');
	}
});