<?php
namespace GiveFlutterwave;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\PaymentGateway;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Contracts\WebhookNotificationsListener;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Log\Log;
use WP_Error;

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
	const SUPPORTED_CURRENCIES = ['NGN', 'GHS', 'USD', 'EUR', 'GBP', 'XAF', 'EGP', 'RWF', 'SLL', 'ZAR', 'TZS', 'UGX', 'XOF', 'ZMW'];

	// Outcomes of applyVerifiedTransaction().
	const RESULT_COMPLETE = 'complete';
	const RESULT_FAILED = 'failed';
	const RESULT_PENDING = 'pending';
	const RESULT_UNVERIFIED = 'unverified';
	const RESULT_MISMATCH = 'mismatch';
	const RESULT_IGNORED = 'ignored';

	const MIN_WEBHOOK_SECRET_LENGTH = 16;

	// Seconds after which a processing lock is considered abandoned.
	const LOCK_TTL = 60;

	/**
	 * How long a donor's return request waits for a concurrent webhook to finish processing.
	 *
	 * @var int
	 */
	protected $lockWaitSeconds = 5;

	// webhookNotificationsListener is added to routeMethods by GiveWP's Webhook class.
	public $routeMethods = [];
	public $secureRouteMethods = [
		'handleReturn',
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

	public function enqueueScript(int $formId)
	{
		wp_enqueue_script(
			'flutterwave-give-js',
			GIVE_FLUTTERWAVE_URL . 'assets/js/gateway.js',
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
		$currency = strtoupper($donation->amount->getCurrency()->getCode());
		if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
			throw new PaymentGatewayException(esc_html__('Currency not supported by Flutterwave.', 'flutterwave-give'));
		}

		$init = $this->createFlutterwaveTransaction($donation);

		if (is_wp_error($init)) {
			// Details stay in the log; donors only see a generic message.
			PaymentGatewayLog::error(
				'Flutterwave – Initialize Error',
				[
					'message' => $init->get_error_message(),
					'donation_id' => $donation->id
				]
			);
			throw new PaymentGatewayException(esc_html__('Unable to start the Flutterwave payment. Please try again or contact the site administrator.', 'flutterwave-give'));
		}

		$authUrl = $init['authorization_url'] ?? '';
		if (!$authUrl) {
			PaymentGatewayLog::error(
				'Flutterwave – Missing authorization_url',
				[
					'donation_id' => $donation->id
				]
			);
			throw new PaymentGatewayException(esc_html__('Payment link not received from Flutterwave.', 'flutterwave-give' ));
		}

		return new RedirectOffsite($authUrl);
	}

	/**
	 * Handle return from Flutterwave after payment (signed route).
	 *
	 * The donation is also bound to the references stored at checkout, so a tampered
	 * donation-id or reference can never complete another donation.
	 *
	 * @since 1.0.0
	 */
	public function handleReturn(array $queryParams): RedirectResponse
	{
		$donationId = absint($queryParams['donation-id'] ?? 0);
		$donation = $donationId ? Donation::find($donationId) : null;

		if (!$donation) {
			PaymentGatewayLog::error('Flutterwave Return: Donation not found', [
				'donation_id' => $donationId,
			]);
			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		if ($donation->status->isComplete()) {
			return new RedirectResponse(give_get_success_page_uri());
		}

		$reference = sanitize_text_field($queryParams['reference'] ?? '');
		if ($reference === '') {
			$reference = $this->getLatestReference($donationId);
		}

		if ($reference === '' || !$this->referenceBelongsToDonation($donationId, $reference)) {
			PaymentGatewayLog::error('Flutterwave Return Reference Mismatch', [
				'donation_id' => $donationId,
				'reference' => $reference,
			]);
			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		// The webhook for this payment usually arrives at the same moment; only one request may process it.
		if (!$this->waitForLock($donationId)) {
			PaymentGatewayLog::error('Flutterwave Return: Donation is locked by another request', [
				'donation_id' => $donationId,
			]);
			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		try {
			return $this->processReturn(Donation::find($donationId) ?: $donation, $reference, $queryParams);
		} finally {
			$this->releaseLock($donationId);
		}
	}

	protected function processReturn(Donation $donation, string $reference, array $queryParams): RedirectResponse
	{
		$donationId = (int) $donation->id;

		if ($donation->status->isComplete()) {
			return new RedirectResponse(give_get_success_page_uri());
		}

		$verify = $this->verifyFlutterwave($donation, $reference);

		if (is_wp_error($verify)) {
			PaymentGatewayLog::error('Flutterwave Return Verification Failed', [
				'donation_id' => $donationId,
				'reference' => $reference,
				'error' => $verify->get_error_message()
			]);

			return new RedirectResponse(give_get_failed_transaction_uri());
		}

		$result = $this->applyVerifiedTransaction($donation, $reference, $verify);

		// Flutterwave redirects with status=cancelled when the donor closes checkout without paying.
		$returnStatus = strtolower(sanitize_text_field($queryParams['status'] ?? ''));
		if ($result === self::RESULT_UNVERIFIED && $returnStatus === 'cancelled' && $donation->status->isPending()) {
			$donation->status = DonationStatus::CANCELLED();
			$donation->save();

			DonationNote::create([
				'donationId' => $donation->id,
				'content' => __('Donor cancelled the payment on Flutterwave.', 'flutterwave-give'),
			]);
		}

		return new RedirectResponse(
			$result === self::RESULT_COMPLETE ? give_get_success_page_uri() : give_get_failed_transaction_uri()
		);
	}

	/** ----------------------------------------------------------------
	 * Webhook listener
	 * --------------------------------------------------------------- */
	public function getWebhookUrl(): string
	{
		return static::webhook()->getNotificationUrl();
	}

	/**
	 * GiveWP route method for webhook notifications.
	 *
	 * @param array $queryParams
	 */
	public function webhookNotificationsListener($queryParams = [])
	{
		$rawBody = (string) file_get_contents('php://input');
		$headers = [
			'verif-hash' => isset($_SERVER['HTTP_VERIF_HASH']) ? (string) wp_unslash($_SERVER['HTTP_VERIF_HASH']) : '',
		];

		[$status, $body] = $this->processWebhookNotification($rawBody, $headers);

		wp_send_json($body, $status);
	}

	/**
	 * @param array $headers lower-case header name => value
	 * @return array{0: int, 1: array} HTTP status and response body
	 */
	public function processWebhookNotification(string $rawBody, array $headers): array
	{
		$secret = trim((string) give_get_option('give_flutterwave_webhook_secret'));
		if (strlen($secret) < self::MIN_WEBHOOK_SECRET_LENGTH) {
			Log::warning('Flutterwave Webhook: Rejected because the webhook secret is missing or too short');
			return [401, ['error' => 'Unauthorized']];
		}

		if (!$this->isValidWebhookSignature($headers, $secret)) {
			return [401, ['error' => 'Unauthorized']];
		}

		$payload = json_decode($rawBody, true);
		$payload = is_array($payload) ? $payload : [];
		$data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
		$reference = sanitize_text_field((string) ($data['tx_ref'] ?? ''));

		if (!$reference) {
			return [400, ['error' => 'Missing reference']];
		}

		$donationId = preg_match('/^give-(\d+)-/', $reference, $matches) ? absint($matches[1]) : 0;
		$donation = $donationId ? Donation::find($donationId) : null;

		if (!$donation || !$this->referenceBelongsToDonation($donationId, $reference)) {
			Log::warning('Flutterwave Webhook: Donation not found for reference', ['reference' => $reference]);
			return [200, ['ok' => true]];
		}

		if ($donation->status->isComplete()) {
			return [200, ['ok' => true]];
		}

		// Another request (usually the donor's return) is processing this donation; Flutterwave retries non-2xx.
		if (!$this->acquireLock($donationId)) {
			return [409, ['error' => 'Donation is being processed']];
		}

		try {
			$donation = Donation::find($donationId) ?: $donation;
			if ($donation->status->isComplete()) {
				return [200, ['ok' => true]];
			}

			// Never trust the webhook body for the outcome; re-verify with the Flutterwave API.
			$verify = $this->verifyFlutterwave($donation, $reference);
			if (is_wp_error($verify)) {
				PaymentGatewayLog::error('Flutterwave Webhook Verification Failed', [
					'donation_id' => $donationId,
					'reference' => $reference,
					'error' => $verify->get_error_message()
				]);
				return [500, ['error' => 'Verification failed']];
			}

			$this->applyVerifiedTransaction($donation, $reference, $verify);

			return [200, ['ok' => true]];
		} finally {
			$this->releaseLock($donationId);
		}
	}

	/**
	 * Flutterwave v3 sends the dashboard secret hash verbatim in the "verif-hash" header.
	 */
	protected function isValidWebhookSignature(array $headers, string $secret): bool
	{
		$verifHash = (string) ($headers['verif-hash'] ?? '');

		return $verifHash !== '' && hash_equals($secret, $verifHash);
	}

	/** ----------------------------------------------------------------
	 * Helpers: Flutterwave API calls
	 * --------------------------------------------------------------- */

	protected function createFlutterwaveTransaction(Donation $donation)
	{
		$secret = $this->getSecretKey($donation);
		if (is_wp_error($secret)) {
			return $secret;
		}

		$amount = $this->formatAmount($donation);
		$currency = $donation->amount->getCurrency()->getCode();        // e.g., NGN, USD
		$reference = 'give-' . $donation->id . '-' . uniqid('GWP');

		$this->storeReference($donation->id, $reference);

		// Use a secure Give route as return/callback
		$returnUrl = $this->generateSecureGatewayRouteUrl('handleReturn', $donation->id, [
			'reference' => $reference,
			'donation-id' => $donation->id,
		]);

		$body = [
			'amount'        => $amount,
			'currency'      => $currency,
			'tx_ref'        => $reference,
			'redirect_url'  => $returnUrl,
			'customer'      => [
				'email'  => $donation->donor->email
			]
		];

		PaymentGatewayLog::info(
			'Flutterwave – Initializing Checkout',
			[
				'donation_id' => $donation->id,
				'reference' => $reference,
				'amount' => $amount,
				'currency' => $currency,
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
			if (!self::isFlutterwaveUrl((string) $json['data']['link'])) {
				return new WP_Error('flutterwave_invalid_link', 'Checkout link is not a Flutterwave URL: ' . $json['data']['link']);
			}

			return [
				'authorization_url' => $json['data']['link'],
			];
		}

		return new WP_Error('flutterwave_init_failed', 'Failed to initialize Flutterwave transaction: ' . wp_json_encode($json));
	}

	protected function verifyFlutterwave(Donation $donation, string $reference)
	{
		Log::info('Flutterwave Verifying Transaction', ['reference' => $reference]);

		$secret = $this->getSecretKey($donation);
		if (is_wp_error($secret)) {
			return $secret;
		}

		$endpoint = 'https://api.flutterwave.com/v3/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference);

		$res = wp_remote_get($endpoint, [
			'headers' => ['Authorization' => 'Bearer ' . $secret],
			'timeout' => 30,
		]);

		if (is_wp_error($res)) return $res;
		$json = json_decode(wp_remote_retrieve_body($res), true);

		return is_array($json) ? $json : new WP_Error('flutterwave_verify_failed', 'Invalid verify response');
	}

	/** ----------------------------------------------------------------
	 * Helpers: configuration
	 * --------------------------------------------------------------- */

	/**
	 * Secret key for the donation's mode (GiveWP test mode vs live).
	 *
	 * @return string|WP_Error
	 */
	protected function getSecretKey(Donation $donation)
	{
		return self::getSecretKeyForMode($donation->mode->isTest());
	}

	/**
	 * Returns the configured key, or an error when it is missing or does not match the mode,
	 * so a test key can never complete live donations (and vice versa).
	 *
	 * @return string|WP_Error
	 */
	public static function getSecretKeyForMode(bool $testMode)
	{
		$mode = $testMode ? 'test' : 'live';
		$key = trim((string) give_get_option("give_flutterwave_{$mode}_secret_key"));

		if ($key === '') {
			return new WP_Error('flutterwave_missing_secret', sprintf('Flutterwave %s secret key is not set.', $mode));
		}

		$isTestKey = strpos($key, 'FLWSECK_TEST-') === 0;
		$isLiveKey = strpos($key, 'FLWSECK-') === 0;

		if (($testMode && !$isTestKey) || (!$testMode && !$isLiveKey)) {
			return new WP_Error(
				'flutterwave_key_mode_mismatch',
				sprintf('The Flutterwave secret key in use is not a %s key. Check the GiveWP test mode setting and your Flutterwave keys.', $mode)
			);
		}

		return $key;
	}

	/**
	 * Move the 1.0.0 single secret key into the Live or Test field and delete settings
	 * that are no longer shown, so no key stays active without being visible to admins.
	 */
	public static function migrateLegacySettings()
	{
		$legacyOptions = ['give_flutterwave_secret_key', 'give_flutterwave_public_key', 'give_flutterwave_mode'];
		$hasLegacy = false;

		foreach ($legacyOptions as $option) {
			if (give_get_option($option, null) !== null) {
				$hasLegacy = true;
			}
		}

		if (!$hasLegacy) {
			return;
		}

		$legacyKey = trim((string) give_get_option('give_flutterwave_secret_key'));
		if ($legacyKey !== '') {
			$field = strpos($legacyKey, 'FLWSECK_TEST-') === 0 ? 'give_flutterwave_test_secret_key' : 'give_flutterwave_live_secret_key';

			if (trim((string) give_get_option($field)) === '') {
				give_update_option($field, $legacyKey);
			}
		}

		foreach ($legacyOptions as $option) {
			give_delete_option($option);
		}
	}

	/**
	 * Configuration problems to show admins.
	 *
	 * @return string[]
	 */
	public static function getConfigurationProblems(bool $testMode, string $webhookUrl): array
	{
		$problems = [];

		$key = self::getSecretKeyForMode($testMode);
		if (is_wp_error($key)) {
			$problems[] = $key->get_error_message();
		}

		$secret = trim((string) give_get_option('give_flutterwave_webhook_secret'));
		if (strlen($secret) < self::MIN_WEBHOOK_SECRET_LENGTH) {
			$problems[] = sprintf(
				'The webhook secret hash must be at least %d characters. Webhooks are rejected until it is set in both GiveWP and the Flutterwave dashboard.',
				self::MIN_WEBHOOK_SECRET_LENGTH
			);
		}

		if ($webhookUrl !== '' && strtolower((string) wp_parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https') {
			$problems[] = 'The webhook URL is not HTTPS, so the webhook secret hash is sent unencrypted. Serve the site over HTTPS.';
		}

		return $problems;
	}

	public static function isFlutterwaveUrl(string $url): bool
	{
		$scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

		return $scheme === 'https'
			&& ($host === 'flutterwave.com' || substr($host, -strlen('.flutterwave.com')) === '.flutterwave.com');
	}

	/** ----------------------------------------------------------------
	 * Helpers: processing lock
	 * --------------------------------------------------------------- */

	protected function getLockName(int $donationId): string
	{
		return 'give_flutterwave_lock_' . $donationId;
	}

	/**
	 * Atomic per-donation lock. INSERT IGNORE relies on the unique option_name key;
	 * add_option() is not safe here because it upserts.
	 */
	protected function acquireLock(int $donationId): bool
	{
		global $wpdb;

		$name = $this->getLockName($donationId);
		$now = time();

		// Clear a lock left behind by a request that died mid-processing.
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d",
			$name,
			$now - self::LOCK_TTL
		));

		return (bool) $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$name,
			(string) $now
		));
	}

	protected function waitForLock(int $donationId): bool
	{
		$deadline = microtime(true) + $this->lockWaitSeconds;

		do {
			if ($this->acquireLock($donationId)) {
				return true;
			}

			if (microtime(true) >= $deadline) {
				return false;
			}

			usleep(250000);
		} while (true);
	}

	protected function releaseLock(int $donationId)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			$this->getLockName($donationId)
		));
	}

	/** ----------------------------------------------------------------
	 * Helpers: references
	 * --------------------------------------------------------------- */

	/**
	 * Every checkout attempt keeps its reference, so paying through an older checkout link
	 * still completes the donation.
	 */
	protected function storeReference(int $donationId, string $reference)
	{
		$references = $this->getStoredReferences($donationId);
		$references[] = $reference;

		give_update_payment_meta($donationId, '_give_flutterwave_references', array_values(array_unique($references)));
		give_update_payment_meta($donationId, '_give_flutterwave_reference', $reference);
	}

	protected function getStoredReferences(int $donationId): array
	{
		$references = give_get_payment_meta($donationId, '_give_flutterwave_references', true);
		$references = is_array($references) ? $references : [];

		$legacy = (string) give_get_payment_meta($donationId, '_give_flutterwave_reference', true);
		if ($legacy !== '') {
			$references[] = $legacy;
		}

		return array_values(array_unique(array_filter(array_map('strval', $references))));
	}

	protected function getLatestReference(int $donationId): string
	{
		return (string) give_get_payment_meta($donationId, '_give_flutterwave_reference', true);
	}

	protected function referenceBelongsToDonation(int $donationId, string $reference): bool
	{
		foreach ($this->getStoredReferences($donationId) as $stored) {
			if (hash_equals($stored, $reference)) {
				return true;
			}
		}

		return false;
	}

	/** ----------------------------------------------------------------
	 * Helpers: verification
	 * --------------------------------------------------------------- */

	/**
	 * Amount in major units, respecting the currency's decimals (e.g. 5000 XAF, 10.50 USD).
	 */
	protected function formatAmount(Donation $donation): string
	{
		return (string) $donation->amount->formatToDecimal();
	}

	/**
	 * Update the donation from a verify_by_reference response, but only when the verified
	 * transaction is the one created for this donation (tx_ref, amount and currency).
	 *
	 * @return string one of the RESULT_* constants
	 */
	protected function applyVerifiedTransaction(Donation $donation, string $expectedReference, array $verify): string
	{
		if (strtolower((string) ($verify['status'] ?? '')) !== 'success' || !is_array($verify['data'] ?? null)) {
			PaymentGatewayLog::error('Flutterwave Verification Unsuccessful', [
				'donation_id' => $donation->id,
				'reference' => $expectedReference,
				'message' => (string) ($verify['message'] ?? ''),
			]);
			return self::RESULT_UNVERIFIED;
		}

		$data = $verify['data'];
		$txRef = (string) ($data['tx_ref'] ?? '');

		if ($txRef === '' || !hash_equals($expectedReference, $txRef)) {
			PaymentGatewayLog::error('Flutterwave Verification Reference Mismatch', [
				'donation_id' => $donation->id,
				'expected_reference' => $expectedReference,
				'verified_reference' => $txRef,
			]);
			return self::RESULT_MISMATCH;
		}

		$verifiedStatus = strtolower((string) ($data['status'] ?? ''));

		PaymentGatewayLog::info('Flutterwave Verification', [
			'donation_id' => $donation->id,
			'reference' => $txRef,
			'status' => $verifiedStatus,
			'amount' => $data['amount'] ?? null,
			'currency' => $data['currency'] ?? null,
		]);

		if ($verifiedStatus === 'successful') {
			if ($donation->status->isComplete()) {
				return self::RESULT_COMPLETE;
			}

			if (!$this->canTransitionTo($donation, DonationStatus::COMPLETE)) {
				PaymentGatewayLog::error('Flutterwave Verification Ignored', [
					'donation_id' => $donation->id,
					'donation_status' => $donation->status->getValue(),
				]);
				return self::RESULT_IGNORED;
			}

			if (!$this->verifiedAmountMatches($donation, $data)) {
				PaymentGatewayLog::error('Flutterwave Verification Amount Mismatch', [
					'donation_id' => $donation->id,
					'expected_amount' => $this->formatAmount($donation),
					'expected_currency' => $donation->amount->getCurrency()->getCode(),
					'verified_amount' => $data['amount'] ?? null,
					'verified_currency' => $data['currency'] ?? null,
				]);

				// Webhook retries would otherwise add the same note repeatedly.
				$noted = give_get_payment_meta($donation->id, '_give_flutterwave_mismatch_noted', true);
				$noted = is_array($noted) ? $noted : [];

				if (!in_array($txRef, $noted, true)) {
					DonationNote::create([
						'donationId' => $donation->id,
						'content' => __('Flutterwave payment amount or currency does not match the donation. Donation was not completed.', 'flutterwave-give'),
					]);

					$noted[] = $txRef;
					give_update_payment_meta($donation->id, '_give_flutterwave_mismatch_noted', $noted);
				}

				return self::RESULT_MISMATCH;
			}

			$donation->status = DonationStatus::COMPLETE();
			$donation->gatewayTransactionId = isset($data['id']) ? (string) $data['id'] : $txRef;
			$donation->save();

			DonationNote::create([
				'donationId' => $donation->id,
				'content' => __('Payment completed and verified via Flutterwave.', 'flutterwave-give'),
			]);

			return self::RESULT_COMPLETE;
		}

		if ($verifiedStatus === 'failed') {
			if (!$this->canTransitionTo($donation, DonationStatus::FAILED)) {
				return self::RESULT_IGNORED;
			}

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

			return self::RESULT_FAILED;
		}

		// Pending or unknown — leave the donation as is.
		return self::RESULT_PENDING;
	}

	/**
	 * A payment can still complete a donation that failed, was cancelled or was abandoned
	 * (the donor may retry on the same checkout), but never a refunded or revoked one.
	 * Only pending donations can be marked failed.
	 */
	protected function canTransitionTo(Donation $donation, string $status): bool
	{
		$current = $donation->status->getValue();

		if ($status === DonationStatus::COMPLETE) {
			return in_array($current, [
				DonationStatus::PENDING,
				DonationStatus::PROCESSING,
				DonationStatus::FAILED,
				DonationStatus::CANCELLED,
				DonationStatus::ABANDONED,
			], true);
		}

		return in_array($current, [DonationStatus::PENDING, DonationStatus::PROCESSING], true);
	}

	protected function verifiedAmountMatches(Donation $donation, array $data): bool
	{
		if (!isset($data['amount']) || !is_numeric($data['amount'])) {
			return false;
		}

		$expectedCurrency = strtoupper($donation->amount->getCurrency()->getCode());
		$verifiedCurrency = strtoupper((string) ($data['currency'] ?? ''));

		return $verifiedCurrency === $expectedCurrency
			&& abs((float) $data['amount'] - (float) $this->formatAmount($donation)) < 0.005;
	}

}
