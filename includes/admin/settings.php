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
function register_sections( $sections ) {
	$sections['flutterwave'] = __( 'Flutterwave', 'flutterwave-give' );

	return $sections;
}

add_filter( 'give_get_sections_gateways', 'register_sections' );

add_filter( 'give_get_settings_gateways', function ( array $settings ) {

	$section = give_get_current_setting_section();

	if ( $section !== 'flutterwave' ) {
		return $settings;
	}

	$section = [
		[
			'id'   => 'give_flutterwave_settings',
			'name' => esc_html__( 'Flutterwave', 'flutterwave-give' ),
			'desc' => esc_html__( 'Configure Flutterwave API credentials and behavior.', 'flutterwave-give' ),
			'type' => 'title',
		],
		[
			'name' => esc_html__( 'Public Key', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_public_key',
			'type' => 'text',
		],
		[
			'name' => esc_html__( 'Secret Key', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_secret_key',
			'type' => 'text',
		],
		[
			'name'    => esc_html__( 'Mode', 'flutterwave-give' ),
			'id'      => 'give_flutterwave_mode',
			'type'    => 'select',
			'options' => [
				'test' => esc_html__( 'Test', 'flutterwave-give' ),
				'live' => esc_html__( 'Live', 'flutterwave-give' ),
			],
			'default' => 'test',
		],
		[
			'name' => esc_html__( 'Webhook Secret (optional)', 'flutterwave-give' ),
			'id'   => 'give_flutterwave_webhook_secret',
			'type' => 'text',
			'desc' => esc_html__( 'If Flutterwave signs webhooks, put the secret here to verify (HMAC).', 'flutterwave-give' ),
		],
		[
			'id'   => 'give_flutterwave_settings_end',
			'type' => 'sectionend',
		],
	];

	return array_merge( $settings, $section );
} );