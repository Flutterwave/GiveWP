<?php

declare(strict_types=1);

namespace {

if (PHP_SAPI !== 'cli') {
    exit;
}

// Minimal WordPress function & class stubs for running PHPUnit without WP.

if (!defined('GIVE_FLUTTERWAVE_URL')) {
    define('GIVE_FLUTTERWAVE_URL', 'https://example.com/');
}
if (!defined('GIVE_FLUTTERWAVE_VER')) {
    define('GIVE_FLUTTERWAVE_VER', '1.0.0');
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string
    {
        return trim($text);
    }
}

if (!function_exists('absint')) {
    function absint($value): int
    {
        return (int) abs((float) $value);
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value)
    {
        return json_encode($value);
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src, array $deps = [], $ver = false, bool $inFooter = false)
    {
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $objectName, $l10n)
    {
        return true;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('give_update_option')) {
    function give_update_option(string $key, $value)
    {
        $GLOBALS['give_test_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('give_delete_option')) {
    function give_delete_option(string $key)
    {
        unset($GLOBALS['give_test_options'][$key]);
        return true;
    }
}

/**
 * Minimal $wpdb that understands the lock queries used by the gateway.
 */
class Give_Test_WPDB
{
    public $options = 'wp_options';

    public function prepare(string $query, ...$args): array
    {
        return [$query, $args];
    }

    public function query($prepared)
    {
        [$query, $args] = $prepared;
        $locks = &$GLOBALS['give_test_locks'];
        $locks = $locks ?? [];

        if (strpos($query, 'INSERT IGNORE') === 0) {
            if (isset($locks[$args[0]])) {
                return 0;
            }
            $locks[$args[0]] = (int) $args[1];
            return 1;
        }

        if (strpos($query, 'DELETE') === 0) {
            if (!isset($locks[$args[0]])) {
                return 0;
            }
            if (isset($args[1]) && $locks[$args[0]] >= $args[1]) {
                return 0;
            }
            unset($locks[$args[0]]);
            return 1;
        }

        throw new \LogicException('Unexpected query: ' . $query);
    }
}

$GLOBALS['wpdb'] = new Give_Test_WPDB();

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        $GLOBALS['give_test_remote_post_args'][] = ['url' => $url, 'args' => $args];
        return $GLOBALS['give_test_remote_post'] ?? ['response' => ['code' => 200], 'body' => '{}'];
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = [])
    {
        $GLOBALS['give_test_remote_get_urls'][] = $url;
        return $GLOBALS['give_test_remote_get'] ?? ['response' => ['code' => 200], 'body' => '{}'];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response)
    {
        return $response['response']['code'] ?? 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array $response)
    {
        return $response['body'] ?? '';
    }
}

if (!function_exists('give_get_option')) {
    function give_get_option(string $key, $default = null)
    {
        return $GLOBALS['give_test_options'][$key] ?? $default;
    }
}

if (!function_exists('give_get_currency')) {
    function give_get_currency(): string
    {
        return 'USD';
    }
}

if (!function_exists('give_get_failed_transaction_uri')) {
    function give_get_failed_transaction_uri(): string
    {
        return 'https://example.com/failed';
    }
}

if (!function_exists('give_get_success_page_uri')) {
    function give_get_success_page_uri(): string
    {
        return 'https://example.com/success';
    }
}

if (!function_exists('give_is_donation_completed')) {
    function give_is_donation_completed($donationId): bool
    {
        return in_array($donationId, $GLOBALS['give_test_completed'] ?? [], true);
    }
}

if (!function_exists('give_update_payment_status')) {
    function give_update_payment_status($donationId, string $status): void
    {
        // no-op
    }
}

if (!function_exists('give_get_payment_meta')) {
    function give_get_payment_meta($donationId, string $key, $single = false)
    {
        return $GLOBALS['give_test_meta'][$donationId][$key] ?? null;
    }
}

if (!function_exists('give_update_payment_meta')) {
    function give_update_payment_meta($donationId, string $key, $value): void
    {
        $GLOBALS['give_test_meta'][$donationId][$key] = $value;
    }
}

if (!function_exists('give')) {
    function give()
    {
        static $instance;
        if (!$instance) {
            $instance = new class {
                public $donations;
                public function __construct()
                {
                    $this->donations = new class {
                        public function getIdByPaymentKey($key)
                        {
                            return null;
                        }
                    };
                }
            };
        }
        return $instance;
    }
}

}

// Minimal stubs for GiveWP classes used by the gateway.

namespace Give\Donations\ValueObjects {

class DonationStatus
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const COMPLETE = 'publish';
    const REFUNDED = 'refunded';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const ABANDONED = 'abandoned';
    const REVOKED = 'revoked';

    private $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function __callStatic($name, $arguments)
    {
        return new static(constant(static::class . '::' . $name));
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'is') === 0) {
            $constant = strtoupper(substr($name, 2));
            return $this->value === constant(static::class . '::' . $constant);
        }

        throw new \BadMethodCallException("Method $name does not exist on enum");
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class DonationMode
{
    private $test;

    public function __construct(bool $test)
    {
        $this->test = $test;
    }

    public function isTest(): bool
    {
        return $this->test;
    }
}

}

namespace Give\Donations\Models {

use Give\Donations\ValueObjects\DonationMode;
use Give\Donations\ValueObjects\DonationStatus;

class Donation
{
    public $id = 1;
    public $amount;
    public $donor;
    public $status;
    public $mode;
    public $gatewayTransactionId;

    public function __construct(string $decimalAmount = '100.00', string $currency = 'USD', bool $testMode = true)
    {
        $this->amount = new class($decimalAmount, $currency) {
            private $decimalAmount;
            private $currency;

            public function __construct(string $decimalAmount, string $currency)
            {
                $this->decimalAmount = $decimalAmount;
                $this->currency = $currency;
            }

            public function formatToDecimal()
            {
                return $this->decimalAmount;
            }

            public function getCurrency()
            {
                return new class($this->currency) {
                    private $code;

                    public function __construct(string $code)
                    {
                        $this->code = $code;
                    }

                    public function getCode()
                    {
                        return $this->code;
                    }
                };
            }
        };
        $this->donor = new class {
            public $email = 'donor@example.com';
        };
        $this->status = DonationStatus::PENDING();
        $this->mode = new DonationMode($testMode);
    }

    public function save(): void
    {
        // no-op
    }

    public static function find($id)
    {
        return $GLOBALS['give_test_donations'][$id] ?? null;
    }
}

class DonationNote
{
    public static function create(array $args)
    {
        // no-op
    }
}

}

namespace Give\Framework\PaymentGateways\Commands {

class RedirectOffsite
{
    public $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }
}

class PaymentComplete {}
class PaymentRefunded {}

}

namespace Give\Framework\Http\Response\Types {

class RedirectResponse
{
    public $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }
}

}

namespace Give\Framework\PaymentGateways\Exceptions {

class PaymentGatewayException extends \Exception
{
}

}

namespace Give\Framework\PaymentGateways {

class PaymentGateway
{
    protected function generateSecureGatewayRouteUrl(string $method, $id = null, array $args = []): string
    {
        $query = http_build_query($args);
        return "https://example.com/secure/{$method}/{$id}?{$query}";
    }

    protected function generateGatewayRouteUrl(string $method, array $args = []): string
    {
        $query = http_build_query($args);
        return "https://example.com/{$method}?{$query}";
    }
}

}

namespace Give\Framework\PaymentGateways\Contracts {

interface WebhookNotificationsListener
{
}

}

namespace Give\Framework\Support\ValueObjects {

class Money
{
    public function getAmount()
    {
        return 10000;
    }

    public function getCurrency()
    {
        return new class {
            public function getCode()
            {
                return 'USD';
            }
        };
    }
}

}

namespace Give\Session\SessionDonation {

class DonationAccessor {}

}

namespace Give\Framework\Support\Facades\Scripts {

class ScriptAsset {}

}

namespace Give\Framework\PaymentGateways\Log {

class PaymentGatewayLog
{
    public static function info(string $message, array $context = [])
    {
        // no-op
    }

    public static function error(string $message, array $context = [])
    {
        // no-op
    }
}

}

namespace Give\Log {

class Log
{
    public static function info(string $message, array $context = [])
    {
        // no-op
    }

    public static function warning(string $message, array $context = [])
    {
        // no-op
    }
}

}

namespace {
    class WP_Error
    {
        private string $code;
        private string $message;

        public function __construct(string $code, string $message)
        {
            $this->code = $code;
            $this->message = $message;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }

    class WP_REST_Request
    {
        private array $data;
        private array $headers;

        public function __construct(array $data = [], array $headers = [])
        {
            $this->data = $data;
            $this->headers = $headers;
        }

        public function get_json_params(): array
        {
            return $this->data;
        }

        public function get_header(string $name)
        {
            return $this->headers[$name] ?? null;
        }
    }

    class WP_REST_Response
    {
        private array $data;
        private int $status;

        public function __construct(array $data = [], int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data(): array
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
