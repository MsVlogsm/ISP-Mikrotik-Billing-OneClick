<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentGatewayCallbackSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_bkash_callback_without_a_local_attempt_cannot_execute_or_credit_payment(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => 'bill.xlinkbd.net',
            'SERVER_NAME' => 'bill.xlinkbd.net',
            'SERVER_PORT' => '8082',
        ])->get('/payment/bkash/callback?status=success&paymentID=UNMATCHED-BKASH-PAYMENT');

        $response->assertRedirect(route('filament.portal.pages.pay-bill'));
        Http::assertNothingSent();
    }

    public function test_nagad_callback_without_a_local_attempt_cannot_verify_or_credit_payment(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => 'bill.xlinkbd.net',
            'SERVER_NAME' => 'bill.xlinkbd.net',
            'SERVER_PORT' => '8082',
        ])->get('/payment/nagad/callback?status=success&payment_ref_id=UNMATCHED-NAGAD-REF&order_id=UNMATCHED-ORDER');

        $response->assertRedirect(route('filament.portal.pages.pay-bill'));
        Http::assertNothingSent();
    }

    public function test_sslcommerz_callback_without_a_local_attempt_cannot_validate_or_credit_payment(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => 'bill.xlinkbd.net',
            'SERVER_NAME' => 'bill.xlinkbd.net',
            'SERVER_PORT' => '8082',
        ])->get('/payment/sslcommerz/callback?status=VALID&tran_id=UNMATCHED-TRANSACTION&val_id=UNMATCHED-VALIDATION');

        $response->assertRedirect(route('filament.portal.pages.pay-bill'));
        Http::assertNothingSent();
    }

    public function test_public_mock_payment_endpoint_is_not_available_on_production_host(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => 'bill.xlinkbd.net',
            'SERVER_NAME' => 'bill.xlinkbd.net',
            'SERVER_PORT' => '8082',
        ])->post('/payment/mock/submit', [
            'customer_id' => 1,
            'amount' => 100,
            'gateway' => 'bkash',
        ]);

        $response->assertNotFound();
        Http::assertNothingSent();
    }
}
