<?php

declare(strict_types=1);

namespace GiveFlutterwave\Tests;

use GiveFlutterwave\Give_Flutterwave_Gateway;
use Give\Donations\Models\Donation;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use PHPUnit\Framework\TestCase;

class GatewayTest extends TestCase
{
    use TestState;

    protected function setUp(): void
    {
        $this->setUpState();
    }

    protected function tearDown(): void
    {
        $this->tearDownState();
    }

    public function testIdIsFlutterwave(): void
    {
        $this->assertSame('flutterwave', Give_Flutterwave_Gateway::id());
    }

    public function testGetNameIncludesFlutterwave(): void
    {
        $gateway = new Give_Flutterwave_Gateway();
        $this->assertStringContainsString('Flutterwave', $gateway->getName());
    }

    public function testPurchaseReturnsRedirectOffsiteWhenInitSucceeds(): void
    {
        $donation = new Donation();

        $gateway = new class extends Give_Flutterwave_Gateway {
            protected function createFlutterwaveTransaction(Donation $donation)
            {
                return ['authorization_url' => 'https://example.com/checkout'];
            }
        };

        $result = $gateway->purchase($donation, []);
        $this->assertInstanceOf(RedirectOffsite::class, $result);
        $this->assertSame('https://example.com/checkout', $result->url);
    }

    public function testCreatePaymentMarksDonationFailedWithGenericMessage(): void
    {
        $donation = new Donation();

        $gateway = new class extends Give_Flutterwave_Gateway {
            protected function createFlutterwaveTransaction(Donation $donation)
            {
                return new \WP_Error('flutterwave_init_failed', 'Failed to initialize: {"status":"error","message":"internal detail"}');
            }
        };

        try {
            $gateway->createPayment($donation, []);
            $this->fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            $this->assertStringNotContainsString('internal detail', $e->getMessage());
        }

        $this->assertTrue($donation->status->isFailed());
    }

    public function testPurchaseRejectsUnsupportedCurrency(): void
    {
        $this->expectException(PaymentGatewayException::class);

        (new Give_Flutterwave_Gateway())->purchase(new Donation('100.00', 'JPY'), []);
    }

    public function testCheckoutUsesSignedReturnUrlDecimalAmountAndStoresReference(): void
    {
        $this->setUpState(new Donation('5000', 'XAF'));
        $GLOBALS['give_test_meta'][55] = [];
        $GLOBALS['give_test_remote_post'] = [
            'response' => ['code' => 200],
            'body' => json_encode(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']]),
        ];

        $result = (new Give_Flutterwave_Gateway())->purchase($this->donation, []);

        $body = json_decode($GLOBALS['give_test_remote_post_args'][0]['args']['body'], true);
        $this->assertSame('https://checkout.flutterwave.com/x', $result->url);
        $this->assertSame('5000', $body['amount']);
        $this->assertSame('XAF', $body['currency']);
        $this->assertStringStartsWith('https://example.com/secure/handleReturn/55?', $body['redirect_url']);
        $this->assertSame([$body['tx_ref']], $GLOBALS['give_test_meta'][55]['_give_flutterwave_references']);
        $this->assertStringStartsWith('give-55-', $body['tx_ref']);
    }

    public function testSecretKeyMustMatchMode(): void
    {
        $this->assertSame('FLWSECK_TEST-abc-X', Give_Flutterwave_Gateway::getSecretKeyForMode(true));
        $this->assertSame('FLWSECK-abc-X', Give_Flutterwave_Gateway::getSecretKeyForMode(false));

        $GLOBALS['give_test_options']['give_flutterwave_live_secret_key'] = 'FLWSECK_TEST-abc-X';
        $this->assertInstanceOf(\WP_Error::class, Give_Flutterwave_Gateway::getSecretKeyForMode(false));

        $GLOBALS['give_test_options']['give_flutterwave_test_secret_key'] = 'FLWSECK-abc-X';
        $this->assertInstanceOf(\WP_Error::class, Give_Flutterwave_Gateway::getSecretKeyForMode(true));
    }

    public function testLegacyLiveKeyIsMigratedAndLegacyOptionsDeleted(): void
    {
        $GLOBALS['give_test_options'] = [
            'give_flutterwave_secret_key' => 'FLWSECK-legacy-X',
            'give_flutterwave_public_key' => 'FLWPUBK-legacy-X',
            'give_flutterwave_mode' => 'live',
        ];

        Give_Flutterwave_Gateway::migrateLegacySettings();

        $this->assertSame(['give_flutterwave_live_secret_key' => 'FLWSECK-legacy-X'], $GLOBALS['give_test_options']);
    }

    public function testLegacyTestKeyMigrationKeepsExistingKey(): void
    {
        $GLOBALS['give_test_options'] = [
            'give_flutterwave_secret_key' => 'FLWSECK_TEST-legacy-X',
            'give_flutterwave_test_secret_key' => 'FLWSECK_TEST-new-X',
        ];

        Give_Flutterwave_Gateway::migrateLegacySettings();

        $this->assertSame(['give_flutterwave_test_secret_key' => 'FLWSECK_TEST-new-X'], $GLOBALS['give_test_options']);
    }

    public function testLegacyKeyIsNoLongerUsedAsFallback(): void
    {
        $GLOBALS['give_test_options'] = ['give_flutterwave_secret_key' => 'FLWSECK-legacy-X'];

        $this->assertInstanceOf(\WP_Error::class, Give_Flutterwave_Gateway::getSecretKeyForMode(false));
    }

    public function testConfigurationProblems(): void
    {
        $this->assertSame([], Give_Flutterwave_Gateway::getConfigurationProblems(true, 'https://example.com/?give-listener=give-gateway'));

        $GLOBALS['give_test_options']['give_flutterwave_webhook_secret'] = 'short';
        $problems = Give_Flutterwave_Gateway::getConfigurationProblems(false, 'http://example.com/?give-listener=give-gateway');

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('at least 16 characters', $problems[0]);
        $this->assertStringContainsString('not HTTPS', $problems[1]);
    }

    public function testCheckoutLinkMustBeFlutterwave(): void
    {
        $this->assertTrue(Give_Flutterwave_Gateway::isFlutterwaveUrl('https://checkout.flutterwave.com/v3/hosted/pay/abc'));
        $this->assertTrue(Give_Flutterwave_Gateway::isFlutterwaveUrl('https://flutterwave.com/pay/abc'));
        $this->assertFalse(Give_Flutterwave_Gateway::isFlutterwaveUrl('http://checkout.flutterwave.com/pay'));
        $this->assertFalse(Give_Flutterwave_Gateway::isFlutterwaveUrl('https://evilflutterwave.com/pay'));
        $this->assertFalse(Give_Flutterwave_Gateway::isFlutterwaveUrl('https://flutterwave.com.evil.example/pay'));

        $GLOBALS['give_test_remote_post'] = [
            'response' => ['code' => 200],
            'body' => json_encode(['status' => 'success', 'data' => ['link' => 'https://evil.example/pay']]),
        ];

        $this->expectException(PaymentGatewayException::class);
        (new Give_Flutterwave_Gateway())->purchase($this->donation, []);
    }
}
