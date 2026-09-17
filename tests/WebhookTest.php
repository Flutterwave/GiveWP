<?php

declare(strict_types=1);

namespace GiveFlutterwave\Tests;

use GiveFlutterwave\Give_Flutterwave_Gateway;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
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

    private function process(array $payload, array $headers): array
    {
        return (new Give_Flutterwave_Gateway())->processWebhookNotification(json_encode($payload), $headers);
    }

    private function chargeCompleted(): array
    {
        return ['event' => 'charge.completed', 'data' => ['tx_ref' => self::REFERENCE, 'status' => 'successful', 'amount' => 100]];
    }

    public function testRejectsWhenWebhookSecretNotConfigured(): void
    {
        unset($GLOBALS['give_test_options']['give_flutterwave_webhook_secret']);

        [$status] = $this->process($this->chargeCompleted(), ['verif-hash' => '']);

        $this->assertSame(401, $status);
        $this->assertTrue($this->donation->status->isPending());
    }

    public function testRejectsShortWebhookSecret(): void
    {
        $GLOBALS['give_test_options']['give_flutterwave_webhook_secret'] = 'short';

        [$status, $body] = $this->process($this->chargeCompleted(), ['verif-hash' => 'short']);

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'Unauthorized'], $body);
    }

    public function testReturnsConflictWhenDonationIsLocked(): void
    {
        $GLOBALS['give_test_locks']['give_flutterwave_lock_55'] = time();
        $this->mockVerify($this->successfulTransaction());

        [$status] = $this->process($this->chargeCompleted(), ['verif-hash' => self::WEBHOOK_SECRET]);

        $this->assertSame(409, $status);
        $this->assertTrue($this->donation->status->isPending());
        $this->assertSame([], $GLOBALS['give_test_remote_get_urls']);
    }

    public function testRejectsInvalidSignature(): void
    {
        [$status, $body] = $this->process($this->chargeCompleted(), ['verif-hash' => 'wrong']);

        $this->assertSame(401, $status);
        $this->assertSame(['error' => 'Unauthorized'], $body);
    }

    public function testCompletesOnlyAfterApiVerification(): void
    {
        $this->mockVerify($this->successfulTransaction());

        [$status] = $this->process($this->chargeCompleted(), ['verif-hash' => self::WEBHOOK_SECRET]);

        $this->assertSame(200, $status);
        $this->assertTrue($this->donation->status->isComplete());
    }

    public function testRejectsMissingVerifHash(): void
    {
        [$status] = $this->process($this->chargeCompleted(), []);

        $this->assertSame(401, $status);
    }

    public function testIgnoresPayloadStatusWhenApiAmountDiffers(): void
    {
        $this->mockVerify($this->successfulTransaction(['amount' => 1]));

        $this->process($this->chargeCompleted(), ['verif-hash' => self::WEBHOOK_SECRET]);

        $this->assertTrue($this->donation->status->isPending());
    }

    public function testIgnoresReferenceNotStoredForDonation(): void
    {
        $payload = ['data' => ['tx_ref' => 'give-55-GWPforged', 'status' => 'successful']];

        [$status] = $this->process($payload, ['verif-hash' => self::WEBHOOK_SECRET]);

        $this->assertSame(200, $status);
        $this->assertTrue($this->donation->status->isPending());
        $this->assertSame([], $GLOBALS['give_test_remote_get_urls']);
    }

    public function testReturnsBadRequestWhenReferenceMissing(): void
    {
        [$status, $body] = $this->process([], ['verif-hash' => self::WEBHOOK_SECRET]);

        $this->assertSame(400, $status);
        $this->assertSame(['error' => 'Missing reference'], $body);
    }
}
