<?php
namespace GiveFlutterwave;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\DonationSummary;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Contracts\WebhookNotificationsListener;
use Give\Framework\PaymentGateways\Commands\GatewayCommand;
use Give\Framework\PaymentGateways\Commands\PaymentComplete;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\Support\Facades\Scripts\ScriptAsset;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Framework\Support\ValueObjects\Money;
use Give\Session\SessionDonation\DonationAccessor;
use Give\Log\Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

/**
 * Flutterwave Gateway (Hosted Checkout)
 *
 * Uses GiveWP’s modern gateway framework:
 * - Offsite redirect via method routes (generateSecureGatewayRouteUrl)
 * - Webhook listener via WebhookNotificationsListener
 */
class Give_Flutterwave_Gateway extends PaymentGateway implements WebhookNotificationsListener
{
	public $routeMethods = [
		'webhookNotificationsListener',
		'handleReturn'
	];
	public $secureRouteMethods = [
//		'handleReturn',
		'handleCancelledPaymentReturn',
		'return'
	];
	/** ----------------------------------------------------------------
	 * Identity
	 * --------------------------------------------------------------- */
	public static function id(): string
	{
		return 'flutterwave';
	}

	public function getId(): string
	{
		return self::id();
	}

	/**
	 * @inheritDoc
	 */
	public function createPayment(Donation $donation, $gatewayData) {
		try {
			return $this->purchase($donation, $gatewayData);
//			throw new PaymentGatewayException( __(' Currency not supported by selected payment option.', 'flutterwave-give' ) );
		} catch ( PaymentGatewayException $e ) {
			$donation->status = DonationStatus::FAILED();
			$donation->save();

			DonationNote::create( [
				'donationId' => $donation->id,
				'content'    => sprintf(
				/* translators: %s: Donation reason */
					esc_html__( 'Donation failed. Reason: %s', 'flutterwave-give' ),
					esc_html( $e->getMessage() )
				),
			] );

			throw new PaymentGatewayException( esc_html( $e->getMessage() ) );
		}

	}

	public function getName(): string
	{
		return __( 'Flutterwave', 'flutterwave-give' );
	}

	public function getPaymentMethodLabel(): string
	{
		 return __( 'Flutterwave', 'flutterwave-give' );
	}

	public function getTransactionUrl(Donation $donation): ?string
	{
		$checkoutUrl = give_get_payment_meta($donation->id, '_give_flutterwave_checkout_url', true);
		return $checkoutUrl ?: null;
	}

	public function enqueueScript(int $formId)
	{
		// Load Flutterwave’s JS SDK
		wp_enqueue_script(
			'flutterwave-give-js',
			GIVE_FLUTTERWAVE_URL . 'assets/js/gateway.js', // Confirm latest CDN path in docs
			[],
			GIVE_FLUTTERWAVE_VER,
			true
		);

		// Pass localized config to JS
		wp_localize_script('flutterwave-give-js', 'giveFlutterwaveSettings', $this->formSettings($formId));
	}

	public function formSettings(int $formId): array
	{
		return [
			'message' => __( 'You will be redirected to Flutterwave to complete the donation!', 'flutterwave-give' ),
		];
//		return [
//			'publicKey'   => give_get_option('give_flutterwave_public_key'),
//			'currency'    => give_get_currency(),
//			'mode'        => give_get_option('give_flutterwave_mode'),
//			'formId'      => $formId,
//			'ajaxUrl'     => $this->generateGatewayRouteUrl('tokenize'),
//		];
	}

	/**
	 * @inheritDoc
	 */
	public function getLegacyFormFieldMarkup( int $formId, array $args ): string
	{
		return "<div class=\"flutterwave-give-help-text\">
                    <p>" . esc_html__( 'You will be redirected to Flutterwave to complete the donation!', 'flutterwave-give' ) . "</p>
                </div>";
	}


	/** ----------------------------------------------------------------
	 * Form v3 support (optional; no custom JS needed for redirect)
	 * --------------------------------------------------------------- */
	public function supportsFormVersions(): array
	{
		// We support v3 by default; no enqueue needed for an offsite redirect.
		return [3];
	}

	/** ----------------------------------------------------------------
	 * Entry point: called by Give when donor submits with this gateway
	 * --------------------------------------------------------------- */
	public function purchase(Donation $donation, $gatewayData)
	{
		// 1) Create a pending donation row has been handled by Give pre-purchase.
		// 2) Initialize Flutterwave transaction and redirect donor to authorization_url.

		$init = $this->createFlutterwaveTransaction($donation);

		if (is_wp_error($init)) {
			$secret = trim((string) give_get_option('give_flutterwave_secret_key'));
			PaymentGatewayLog::error(
				'Flutterwave – Initialize Error',
				[
					'message' => $init->get_error_message(),
					'donation_id' => $donation->id
				]
			);
			$errorMessage = $init->get_error_message();
			throw new PaymentGatewayException(esc_html($errorMessage));
		}

		$authUrl = $init['authorization_url'] ?? '';
		if (!$authUrl) {
			PaymentGatewayLog::error(
				'Flutterwave – Missing authorization_url',
				[
					'response' => $init,
					'donation_id' => $donation->id
				]
			);
			throw new PaymentGatewayException(esc_html__('Payment link not received from Flutterwave.', 'flutterwave-give' ));
		}

		return new RedirectOffsite($authUrl);
	}

	/** ----------------------------------------------------------------
	 * Secure return route (optional)
	 * You can link this as Flutterwave callback/return in the init payload.
	 * --------------------------------------------------------------- */

	/**
	 * Handle return from Flutterwave after payment
	 *
	 * @since 1.0.0
	 */
	public function handleReturn(array $queryParams): RedirectResponse
	{
		// The donation ID comes from the route signature
		$donationId = absint($queryParams['donation-id'] ?? 0);
		$reference = sanitize_text_field($queryParams['reference'] ?? '');

		if (empty($donationId) || empty($reference)) {
			PaymentGatewayLog::error('Flutterwave Missing Parameters', [
				'params' => $queryParams
			]);
			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		$donation = Donation::find($donationId);
		if (!$donation) {
			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		// If already completed, just redirect to success
		if (give_is_donation_completed($donationId)) {
			return new RedirectResponse(give_get_success_page_uri());
		}

		// Verify the transaction with Flutterwave API
		$verify = $this->verifyFlutterwave($reference);

		if (is_wp_error($verify)) {
			PaymentGatewayLog::error('Flutterwave Return Verification Failed', [
				'donation_id' => $donationId,
				'reference' => $reference,
				'error' => $verify->get_error_message()
			]);

			$donation->status = DonationStatus::FAILED();
			$donation->save();

			DonationNote::create([
				'donationId' => $donation->id,
				'content' => sprintf(
					/* translators: %s: Error message from Flutterwave */
					__('Payment verification failed: %s', 'flutterwave-give'),
					$verify->get_error_message()
				),
			]);

			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		// Check the verified status from Flutterwave
		$verifiedStatus = strtolower($verify['data']['status'] ?? '');

		PaymentGatewayLog::info('Flutterwave Return Verification', $verify);

		if ($verifiedStatus === 'successful') {
			$donation->status = DonationStatus::COMPLETE();
			$donation->gatewayTransactionId = $reference;
			$donation->save();

			DonationNote::create([
				'donationId' => $donation->id,
				'content' => __('Payment completed and verified via Flutterwave.', 'flutterwave-give'),
			]);

			return new RedirectResponse(give_get_success_page_uri());
		} else {
			// Payment not successful
			$donation->status = DonationStatus::FAILED();
			$donation->save();

			DonationNote::create([
				'donationId' => $donation->id,
				'content' => sprintf(
					/* translators: %s: Payment status from Flutterwave (e.g., pending, failed) */
					__('Payment status from Flutterwave: %s', 'flutterwave-give'),
					$verifiedStatus
				),
			]);

			return new RedirectResponse(give_get_failed_transaction_uri());
		}
	}

	/**
	 * This method is called when the user cancels the payment on Flutterwave.
	 *
	 * @since 1.0.0
	 */
	protected function handleCancelledPaymentReturn(array $queryParams): RedirectResponse
	{
		$donationId = (int)$queryParams['donation-id'];

		/** @var Donation $donation */
		$donation = Donation::find($donationId);
		$donation->status = DonationStatus::CANCELLED();
		$donation->save();

		return new RedirectResponse(esc_url_raw($queryParams['givewp-return-url']));
	}

	/** ----------------------------------------------------------------
	 * Webhook listener
	 * --------------------------------------------------------------- */
	public function getWebhookUrl(): string
	{
		// GiveWP will expose: /wp-json/give-api/v2/gateways/flutterwave/webhook
		// (Provided by $this->webhook)
		return static::webhook()->url();
	}

	public function handleWebhookNotification(WP_REST_Request $request): WP_REST_Response
	{
		$payload = $request->get_json_params() ?: [];
		$reference = sanitize_text_field($payload['reference'] ?? '');
		$status    = strtolower(sanitize_text_field($payload['status'] ?? ''));

		if (!$reference) {
			return new WP_REST_Response(['error' => 'Missing reference'], 400);
		}

		// Optional HMAC verification if Flutterwave provides a signing secret.
		$secret = give_get_option('give_flutterwave_webhook_secret');
		if ($secret) {
			$sigHeader = $request->get_header('x-verif-hash');
			if ($secret !== $sigHeader) {
				return new WP_REST_Response(['error' => 'Invalid signature'], 400);
			}
		}

		$donationId = give()->donations->getIdByPaymentKey($reference);
		if (!$donationId) {
			Log::warning('Flutterwave Webhook: Donation not found for reference', ['reference' => $reference]);
			return new WP_REST_Response(['ok' => true], 200);
		}

		if ($status === 'success') {
			give_update_payment_status($donationId, 'publish');
		} elseif ($status === 'failed' || $status === 'cancelled') {
			give_update_payment_status($donationId, 'failed');
		} else {
			// Unknown status — do nothing, keep pending.
			Log::info('Flutterwave Webhook: Unknown status', ['status' => $status, 'reference' => $reference]);
		}

		return new WP_REST_Response(['ok' => true], 200);
	}

	/** ----------------------------------------------------------------
	 * Helpers: Flutterwave API calls
	 * --------------------------------------------------------------- */

	private function createFlutterwaveTransaction(Donation $donation)
	{
		$secret = trim((string) give_get_option('give_flutterwave_secret_key'));
		if (!$secret) {
			return new WP_Error('flutterwave_missing_secret', 'Flutterwave secret key is not set.');
		}

		$amount =  (string)(floatval($donation->amount->getAmount()) / 100); // e.g., NGN kobo
		$currency = $donation->amount->getCurrency()->getCode();        // e.g., NGN, USD
//		$reference = $donation->gatewayTransactionId ?: $donation->paymentKey;
		$reference = 'give-' . $donation->id . '-' . uniqid('GWP');

		// Store reference in donation meta
		give_update_payment_meta($donation->id, '_give_flutterwave_reference', $reference);

		// Use a secure Give route as return/callback
		$returnUrl = $this->generateSecureGatewayRouteUrl('handleReturn', $donation->id, [
			'reference' => $reference,
		]);

		$returnUrl = $this->generateGatewayRouteUrl('handleReturn', [
			'reference' => $reference,
			'donation-id' => $donation->id,
		]);

		$body = [
			'amount'        => $amount,
			'currency'      => $currency,
			'reference'     => $reference,
			'redirect_url'  => $returnUrl,
			'customer'      => [
				'email'  => $donation->donor->email
			]
		];

		PaymentGatewayLog::info(
			'Fluttterwave – Payload for Checkout',
			[
				'donation_id' => $donation->id,
				'payload' => $body,
			]
		);

		$endpoint = 'https://api.flutterwave.com/v3/payments';
		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode($body),
			'timeout' => 45,
		];

		$res = wp_remote_post($endpoint, $args);
		if (is_wp_error($res)) return $res;

		$code = wp_remote_retrieve_response_code($res);
		$json = json_decode(wp_remote_retrieve_body($res), true);

		if ($code >= 200 && $code < 300 && isset($json['data']['link'])) {
			// store gateway transaction id if available
			if (!empty($json['data']['reference'])) {
				give_update_payment_meta($donation->id, '_give_payment_transaction_id', sanitize_text_field($json['data']['reference']));
			}
			return [
				'authorization_url' => $json['data']['link'],
			];
		}

		return new WP_Error('flutterwave_init_failed', 'Failed to initialize Flutterwave transaction: ' . wp_json_encode($json));
	}

	private function verifyFlutterwave(string $reference)
	{
		Log::info(' Flutterwave Verifying Transaction', ['reference' => $reference]);
		$secret = trim((string) give_get_option('give_flutterwave_secret_key'));
		if (!$secret) return new WP_Error('flutterwave_missing_secret', 'Secret missing');

		// https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=DevRef002156

		$endpoint = "https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=$reference";

		$res = wp_remote_get($endpoint, [
			'headers' => ['Authorization' => 'Bearer ' . $secret],
			'timeout' => 30,
		]);

		if (is_wp_error($res)) return $res;
		$json = json_decode(wp_remote_retrieve_body($res), true);

		return $json ?: new WP_Error('flutterwave_verify_failed', 'Invalid verify response');
	}

}