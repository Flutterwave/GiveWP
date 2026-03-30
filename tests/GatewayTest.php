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

    public function testCreatePaymentMarksDonationFailedWhenGatewayErrorOccurs(): void
    {
        $donation = new Donation();

        $gateway = new class extends Give_Flutterwave_Gateway {
            protected function createFlutterwaveTransaction(Donation $donation)
            {
                return new \WP_Error('flutterwave_missing_secret', 'Secret not set');
            }
        };

        $this->expectException(PaymentGatewayException::class);

        try {
            $gateway->createPayment($donation, []);
        } finally {
            $this->assertSame('failed', $donation->status);
        }
    }
}
