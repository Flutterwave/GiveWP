<?php

if (!defined('ABSPATH')) exit;

/**
 * Register sections.
 *
 * @since 1.0.0
 *
 * @param array $sections
 * @return array
 */
function give_flutterwave_register_sections( $sections ) {
	$sections['flutterwave'] = __( 'Flutterwave', 'flutterwave-give' );

	return $sections;
}

add_filter( 'give_get_sections_gateways', 'give_flutterwave_register_sections' );

add_filter( 'give_get_settings_gateways', function ( array $settings ) {

	$section = give_get_current_setting_section();

	if ( $section !== 'flutterwave' ) {
		return $settings;
	}

	$webhookUrl = '';
	try {
		$webhookUrl = \GiveFlutterwave\Give_Flutterwave_Gateway::webhook()->getNotificationUrl();
	} catch ( \Throwable $e ) {
		// Leave the URL out of the description if GiveWP cannot build it.
	}

	$section = [
		[
			'id'   => 'give_flutterwave_settings',
			'name' => esc_html__( 'Flutterwave', 'flutterwave-give' ),
			'desc' => esc_html__( 'Configure Flutterwave API credentials. The test or live key is chosen by GiveWP test mode.', 'flutterwave-give' ),
			'type' => 'title',
		],
		[
			'name' => esc_html__( 'Live Secret Key', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_live_secret_key',
			'type' => 'api_key',
			'desc' => esc_html__( 'Starts with FLWSECK-. Used when GiveWP test mode is off.', 'flutterwave-give' ),
		],
		[
			'name' => esc_html__( 'Test Secret Key', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_test_secret_key',
			'type' => 'api_key',
			'desc' => esc_html__( 'Starts with FLWSECK_TEST-. Used when GiveWP test mode is on.', 'flutterwave-give' ),
		],
		[
			'name' => esc_html__( 'Webhook Secret Hash', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_webhook_secret',
			'type' => 'api_key',
			'desc' => $webhookUrl
				? sprintf(
					/* translators: %s: Webhook URL */
					esc_html__( 'Required, at least 16 characters. The secret hash set in your Flutterwave dashboard webhook settings; webhooks are rejected without it. Webhook URL: %s', 'flutterwave-give' ),
					'<code>' . esc_html( $webhookUrl ) . '</code>'
				)
				: esc_html__( 'Required, at least 16 characters. The secret hash set in your Flutterwave dashboard webhook settings; webhooks are rejected without it.', 'flutterwave-give' ),
		],
		[
			'id'   => 'give_flutterwave_settings_end',
			'type' => 'sectionend',
		],
	];

	return array_merge( $settings, $section );
} );
