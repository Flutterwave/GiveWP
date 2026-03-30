<?php

declare(strict_types=1);

namespace GiveFlutterwave\Tests;

use GiveFlutterwave\Give_Flutterwave_Gateway;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    public function testHandleWebhookNotificationReturnsBadRequestWhenReferenceMissing(): void
    {
        $gateway = new Give_Flutterwave_Gateway();
        $request = new \WP_REST_Request([], []);

        $response = $gateway->handleWebhookNotification($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(400, $response->get_status());
        $this->assertSame(['error' => 'Missing reference'], $response->get_data());
    }
}
