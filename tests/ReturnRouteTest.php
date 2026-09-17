<?php

declare(strict_types=1);

namespace GiveFlutterwave\Tests;

use GiveFlutterwave\Give_Flutterwave_Gateway;
use Give\Donations\Models\Donation;
use Give\Donations\ValueObjects\DonationStatus;
use PHPUnit\Framework\TestCase;

class ReturnRouteTest extends TestCase
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

    private function handleReturn(array $params)
    {
        return (new Give_Flutterwave_Gateway())->handleReturn($params);
    }

    public function testOnlyHandleReturnIsRoutedAndItIsSigned(): void
    {
        $gateway = new Give_Flutterwave_Gateway();

        $this->assertSame(['handleReturn'], $gateway->secureRouteMethods);
        $this->assertSame([], $gateway->routeMethods);
    }

    public function testCompletesWhenReferenceAmountAndCurrencyMatch(): void
    {
        $this->mockVerify($this->successfulTransaction());

        $response = $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isComplete());
        $this->assertSame('987', $this->donation->gatewayTransactionId);
        $this->assertSame('https://example.com/success', $response->url);
        $this->assertStringEndsWith('tx_ref=' . self::REFERENCE, $GLOBALS['give_test_remote_get_urls'][0]);
    }

    public function testRejectsReferenceThatIsNotStoredForDonation(): void
    {
        $this->mockVerify($this->successfulTransaction(['tx_ref' => 'give-56-GWPother', 'amount' => 1]));

        $response = $this->handleReturn(['donation-id' => 55, 'reference' => 'give-56-GWPother']);

        $this->assertTrue($this->donation->status->isPending());
        $this->assertSame('https://example.com/failed', $response->url);
        $this->assertSame([], $GLOBALS['give_test_remote_get_urls']);
    }

    public function testAcceptsEarlierCheckoutReference(): void
    {
        $GLOBALS['give_test_meta'][55]['_give_flutterwave_references'] = [self::REFERENCE, 'give-55-GWPnewer'];
        $GLOBALS['give_test_meta'][55]['_give_flutterwave_reference'] = 'give-55-GWPnewer';
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isComplete());
    }

    public function testDoesNotCompleteWhenVerifiedAmountIsLower(): void
    {
        $this->mockVerify($this->successfulTransaction(['amount' => 1]));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isPending());
    }

    public function testDoesNotCompleteWhenVerifiedCurrencyDiffers(): void
    {
        $this->mockVerify($this->successfulTransaction(['currency' => 'NGN']));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isPending());
    }

    public function testDoesNotCompleteWhenVerifiedTxRefDiffers(): void
    {
        $this->mockVerify($this->successfulTransaction(['tx_ref' => 'give-56-GWPother']));

        $this->handleReturn(['donation-id' => 55]);

        $this->assertTrue($this->donation->status->isPending());
    }

    public function testZeroDecimalCurrencyComparesWholeAmount(): void
    {
        $this->setUpState(new Donation('5000', 'XAF'));
        $this->mockVerify($this->successfulTransaction(['amount' => 50, 'currency' => 'XAF']));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);
        $this->assertTrue($this->donation->status->isPending());

        $this->mockVerify($this->successfulTransaction(['amount' => 5000, 'currency' => 'XAF']));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);
        $this->assertTrue($this->donation->status->isComplete());
    }

    public function testUnknownReferenceDoesNotFailDonation(): void
    {
        $this->mockVerify([], 'error');

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isPending());
    }

    public function testCancelledCheckoutCancelsPendingDonation(): void
    {
        $this->mockVerify([], 'error');

        $response = $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE, 'status' => 'cancelled']);

        $this->assertTrue($this->donation->status->isCancelled());
        $this->assertSame('https://example.com/failed', $response->url);
    }

    public function testCancelledStatusIsIgnoredWhenPaymentSucceeded(): void
    {
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE, 'status' => 'cancelled']);

        $this->assertTrue($this->donation->status->isComplete());
    }

    public function testMarksFailedOnlyWhenOwnTransactionFailed(): void
    {
        $this->mockVerify($this->successfulTransaction(['status' => 'failed']));

        $response = $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isFailed());
        $this->assertSame('https://example.com/failed', $response->url);
    }

    public function testFailedDonationCanStillComplete(): void
    {
        $this->donation->status = DonationStatus::FAILED();
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isComplete());
    }

    public function testRefundedDonationIsNotChanged(): void
    {
        $this->donation->status = DonationStatus::REFUNDED();
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isRefunded());
    }

    public function testCompletedDonationIsNotMarkedFailed(): void
    {
        $this->donation->status = DonationStatus::COMPLETE();
        $this->mockVerify($this->successfulTransaction(['status' => 'failed']));

        $response = $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isComplete());
        $this->assertSame('https://example.com/success', $response->url);
    }

    public function testWaitsForConcurrentProcessingLock(): void
    {
        $GLOBALS['give_test_locks']['give_flutterwave_lock_55'] = time();
        $this->mockVerify($this->successfulTransaction());

        $gateway = new class extends Give_Flutterwave_Gateway {
            protected $lockWaitSeconds = 0;
        };
        $response = $gateway->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isPending());
        $this->assertSame('https://example.com/failed', $response->url);
        $this->assertSame([], $GLOBALS['give_test_remote_get_urls']);
    }

    public function testStaleLockIsTakenOver(): void
    {
        $GLOBALS['give_test_locks']['give_flutterwave_lock_55'] = time() - 120;
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isComplete());
        $this->assertSame([], $GLOBALS['give_test_locks']);
    }

    public function testLockIsReleasedAfterProcessing(): void
    {
        $this->mockVerify($this->successfulTransaction(['status' => 'pending']));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertSame([], $GLOBALS['give_test_locks']);
    }

    public function testAmountMismatchNoteIsAddedOnce(): void
    {
        $this->mockVerify($this->successfulTransaction(['amount' => 1]));

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);
        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertSame([self::REFERENCE], $GLOBALS['give_test_meta'][55]['_give_flutterwave_mismatch_noted']);
    }

    public function testLiveDonationRejectsTestKey(): void
    {
        $this->setUpState(new Donation('100.00', 'USD', false));
        $GLOBALS['give_test_options']['give_flutterwave_live_secret_key'] = 'FLWSECK_TEST-abc-X';
        $this->mockVerify($this->successfulTransaction());

        $this->handleReturn(['donation-id' => 55, 'reference' => self::REFERENCE]);

        $this->assertTrue($this->donation->status->isPending());
        $this->assertSame([], $GLOBALS['give_test_remote_get_urls']);
    }
}
