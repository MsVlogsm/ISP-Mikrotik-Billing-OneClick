<?php

namespace Tests\Feature;

use App\Http\Controllers\Payment\BkashPaymentController;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class BkashTokenAuthenticationTest extends TestCase
{
    public function test_token_grant_uses_provider_username_and_password_headers(): void
    {
        config([
            'services.bkash.base_url' => 'https://tokenized.sandbox.bka.sh/v1.2.0-beta',
            'services.bkash.username' => 'merchant-test-user',
            'services.bkash.password' => 'merchant-test-password',
            'services.bkash.app_key' => 'test-app-key',
            'services.bkash.app_secret' => 'test-app-secret',
        ]);

        Http::fake([
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant' => Http::response([
                'statusCode' => '0000',
                'id_token' => 'test-id-token',
            ], 200),
        ]);

        $controller = app(BkashPaymentController::class);
        $method = new ReflectionMethod(BkashPaymentController::class, 'generateToken');
        $method->setAccessible(true);

        $this->assertSame('test-id-token', $method->invoke($controller));

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/token/grant'
                && $request->hasHeader('username', 'merchant-test-user')
                && $request->hasHeader('password', 'merchant-test-password')
                && ! $request->hasHeader('Authorization')
                && $request['app_key'] === 'test-app-key'
                && $request['app_secret'] === 'test-app-secret';
        });
    }
}
