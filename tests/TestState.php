<?php

declare(strict_types=1);

namespace GiveFlutterwave\Tests;

use Give\Donations\Models\Donation;

/**
 * Shared setup for the global state read by the WordPress/GiveWP stubs.
 */
trait TestState
{
    protected const REFERENCE = 'give-55-GWPabc123';

    protected const WEBHOOK_SECRET = 'hook-secret-0123456789';

    protected Donation $donation;

    protected function setUpState(?Donation $donation = null): void
    {
        $this->donation = $donation ?? new Donation();
        $this->donation->id = 55;

        $GLOBALS['give_test_donations'] = [55 => $this->donation];
        $GLOBALS['give_test_meta'] = [55 => [
            '_give_flutterwave_reference' => self::REFERENCE,
            '_give_flutterwave_references' => [self::REFERENCE],
        ]];
        $GLOBALS['give_test_options'] = [
            'give_flutterwave_test_secret_key' => 'FLWSECK_TEST-abc-X',
            'give_flutterwave_live_secret_key' => 'FLWSECK-abc-X',
            'give_flutterwave_webhook_secret' => self::WEBHOOK_SECRET,
        ];
        $GLOBALS['give_test_remote_get_urls'] = [];
        $GLOBALS['give_test_remote_post_args'] = [];
        $GLOBALS['give_test_locks'] = [];
    }

    protected function tearDownState(): void
    {
        foreach (['donations', 'meta', 'options', 'remote_get', 'remote_get_urls', 'remote_post', 'remote_post_args', 'locks'] as $key) {
            unset($GLOBALS['give_test_' . $key]);
        }
    }

    protected function mockVerify(array $data, string $status = 'success'): void
    {
        $GLOBALS['give_test_remote_get'] = [
            'response' => ['code' => 200],
            'body' => json_encode(['status' => $status, 'data' => $data]),
        ];
    }

    protected function successfulTransaction(array $overrides = []): array
    {
        return array_merge(
            ['id' => 987, 'tx_ref' => self::REFERENCE, 'status' => 'successful', 'amount' => 100, 'currency' => 'USD'],
            $overrides
        );
    }
}
