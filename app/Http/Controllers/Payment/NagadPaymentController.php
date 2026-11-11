<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CustomersInfo;
use App\Models\PaymentGatewayAttempt;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NagadPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    private function getNagadConfig()
    {
        return [
            'base_url' => rtrim((string) siteUrlSettings('payment_nagad_base_url'), '/'),
            'merchant_id' => (string) siteUrlSettings('payment_nagad_merchant_id'),
            'public_key' => siteUrlSettings('payment_nagad_public_key'),
            'private_key' => siteUrlSettings('payment_nagad_private_key'),
        ];
    }

    public function initiate(Request $request)
    {
        if (! (bool) siteUrlSettings('payment_nagad_enabled')) {
            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'nagad payments are currently unavailable.');
        }

        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $amount = $request->amount;
        $user = auth()->user();

        if (! $user || ! $user->customer) {
            return redirect()->back()->with('error', 'Unauthorized customer access.');
        }

        $customer = $user->customer;

        // Auto-detect local development to offer sandbox mock helper
        $host = request()->getHost();
        $isLocal = str_ends_with($host, '.test') || str_ends_with($host, '.local') || $host === 'localhost' || $host === '127.0.0.1';

        if ($isLocal && $request->has('mock')) {
            return response()->view('payment.mock_checkout', [
                'gateway' => 'Nagad',
                'customer' => $customer,
                'amount' => $amount,
                'reason' => null,
            ]);
        }

        $config = $this->getNagadConfig();

        // Production payment credentials must use HTTPS; never send merchant keys over plain HTTP.
        if (empty($config['merchant_id']) || empty($config['private_key']) || empty($config['public_key'])
            || ! str_starts_with((string) $config['base_url'], 'https://')) {
            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'Nagad',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'Nagad public/private key pair is not configured in settings. Simulating sandbox payment.',
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Nagad is not properly configured (keys are missing).');
        }

        $merchantId = $config['merchant_id'];
        $orderId = 'XLK'.Str::upper(Str::random(16));
        $attempt = PaymentGatewayAttempt::create([
            'gateway' => 'nagad',
            'customer_unique_id' => $customer->customer_unique_id,
            'amount' => round((float) $amount, 2),
            'currency' => 'BDT',
            'merchant_reference' => $orderId,
            'status' => 'initiated',
            'expires_at' => now()->addDay(),
            'next_poll_at' => now()->addMinute(),
        ]);

        try {
            $dateTime = now()->format('YmdHis');

            // 1. Initial Step: Post to payment-initializer (plaintext parameters)
            $postData = [
                'accountNumber' => $customer->mobile ?: '01700000000',
                'datetime' => $dateTime,
                'orderId' => $orderId,
                'merchantId' => $merchantId,
            ];

            $response = Http::withHeaders([
                'Client-Type' => 'MERCHANT',
                'X-KM-Api-Version' => 'v1.0.0',
                'X-KM-IP-Address' => request()->ip() ?: '127.0.0.1',
            ])->post($config['base_url']."/payment-initializer/merchantId/{$merchantId}/{$orderId}", $postData);

            $res = $response->json();

            if (isset($res['sensitiveData']) && isset($res['signature'])) {
                // Decrypt the sensitiveData to retrieve paymentReferenceId and challenge
                $decryptedData = $this->decrypt($res['sensitiveData'], $config['private_key']);
                if (!$decryptedData) {
                    throw new \Exception('Failed to decrypt payment initialization sensitiveData.');
                }

                $decryptedJson = json_decode($decryptedData, true);
                $paymentRefId = $decryptedJson['paymentReferenceId'] ?? null;
                $challenge = $decryptedJson['challenge'] ?? null;

                if (!$paymentRefId || !$challenge) {
                    throw new \Exception('paymentReferenceId or challenge missing in decrypted initialization data.');
                }

                $attempt->forceFill([
                    'provider_reference' => (string) $paymentRefId,
                    'status' => 'pending',
                    'next_poll_at' => now()->addMinute(),
                    'provider_metadata' => ['order_id' => $orderId],
                ])->save();

                // 2. Complete Step: Prepare sensitive payload for quick-pay-initializer
                $sensitivePayload = [
                    'merchantId' => $merchantId,
                    'orderId' => $orderId,
                    'amount' => (string) round($amount, 2),
                    'currencyCode' => '050',
                    'challenge' => $challenge,
                    'paymentRefId' => $paymentRefId,
                    'productDetails' => 'Internet Bill',
                    'clientMobileNo' => $customer->mobile ?: '01700000000',
                    'ip' => request()->ip() ?: '127.0.0.1',
                ];

                $sensitiveJson = json_encode($sensitivePayload);
                
                // Encrypt payload with Nagad's Public Key
                $encryptedPayload = $this->encrypt($sensitiveJson, $config['public_key']);
                
                // Sign plaintext JSON with Merchant's Private Key
                $signature = $this->sign($sensitiveJson, $config['private_key']);

                if (!$encryptedPayload || !$signature) {
                    throw new \Exception('Failed to encrypt or sign sensitive complete payload.');
                }

                $completePayload = [
                    'sensitiveData' => $encryptedPayload,
                    'signature' => $signature,
                ];

                // POST to quick-pay-initializer to generate redirect checkout URL
                $completeResponse = Http::withHeaders([
                    'Client-Type' => 'MERCHANT',
                    'X-KM-Api-Version' => 'v1.0.0',
                    'X-KM-IP-Address' => request()->ip() ?: '127.0.0.1',
                ])->post($config['base_url']."/quick-pay-initializer/merchantId/{$merchantId}/{$orderId}", $completePayload);

                $completeRes = $completeResponse->json();

                if (isset($completeRes['sensitiveData'])) {
                    // Decrypt the complete step response to get the callBackUrl
                    $decryptedComplete = $this->decrypt($completeRes['sensitiveData'], $config['private_key']);
                    $decryptedCompleteJson = json_decode($decryptedComplete, true);

                    if (isset($decryptedCompleteJson['callBackUrl'])) {
                        session(['nagad_attempt_uuid' => $attempt->attempt_uuid]);
                        return redirect()->away($decryptedCompleteJson['callBackUrl']);
                    }
                    
                    throw new \Exception('callBackUrl not found in decrypted complete response: ' . $decryptedComplete);
                }

                $errorMessage = $completeRes['message'] ?? 'Failed to initialize quick pay session.';
                throw new \Exception($errorMessage);
            }

            $attempt->forceFill([
                'status' => 'failed',
                'next_poll_at' => null,
                'last_error' => 'Nagad rejected the payment initialization request.',
            ])->save();

            Log::error('Nagad session initiation failed: '.json_encode($res));

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'Nagad',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'Nagad API error: '.($res['message'] ?? 'Failed to initialize session.'),
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Nagad session creation failed. Please try again.');

        } catch (\Exception $e) {
            $attempt->refresh();
            $attempt->forceFill([
                'status' => $attempt->provider_reference ? 'pending' : 'needs_review',
                'next_poll_at' => $attempt->provider_reference ? now()->addMinute() : null,
                'last_error' => $attempt->provider_reference
                    ? 'Checkout redirect step failed; provider payment reference retained for status polling.'
                    : 'Nagad checkout initiation did not produce a queryable provider reference.',
            ])->save();

            Log::error('Nagad initiate exception: '.$e->getMessage());

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'Nagad',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'Connection failed: '.$e->getMessage(),
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Nagad service temporarily unavailable. Please try again.');
        }
    }

    public function callback(Request $request)
    {
        // Nagad callback parameters
        $status = $request->query('status');
        $orderId = $request->query('order_id');
        $paymentRefId = trim((string) $request->query('payment_ref_id', ''));
        $attempt = $paymentRefId !== ''
            ? PaymentGatewayAttempt::query()->where('gateway', 'nagad')->where('provider_reference', $paymentRefId)->first()
            : null;
        if (! $attempt && session('nagad_attempt_uuid')) {
            $attempt = PaymentGatewayAttempt::query()
                ->where('attempt_uuid', session('nagad_attempt_uuid'))
                ->where('gateway', 'nagad')
                ->first();
        }
        if (! $attempt && $orderId) {
            $attempt = PaymentGatewayAttempt::query()
                ->where('gateway', 'nagad')
                ->where('merchant_reference', (string) $orderId)
                ->first();
        }

        if (! $attempt) {
            Log::warning('Nagad callback rejected because no local payment attempt matched.', [
                'payment_reference_present' => $paymentRefId !== '',
                'merchant_order_present' => filled($orderId),
            ]);

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Payment verification request was invalid. No matching checkout was found.');
        }

        if ($status === 'success' && $paymentRefId !== '') {
            try {
                $config = $this->getNagadConfig();
                $verify = Http::withHeaders([
                    'Client-Type' => 'MERCHANT',
                    'X-KM-Api-Version' => 'v1.0.0',
                    'X-KM-IP-Address' => request()->ip() ?: '127.0.0.1',
                ])->get($config['base_url']."/payment/status/{$paymentRefId}");
                
                $res = $verify->json();

                if (isset($res['sensitiveData'])) {
                    $decryptedVerify = $this->decrypt($res['sensitiveData'], $config['private_key']);
                    if ($decryptedVerify
                        && ! empty($res['signature'])
                        && $this->verifySignature($decryptedVerify, (string) $res['signature'], $config['public_key'])) {
                        $decryptedVerifyJson = json_decode($decryptedVerify, true);

                        if (is_array($decryptedVerifyJson)
                            && strtolower((string) ($decryptedVerifyJson['status'] ?? '')) === 'success') {
                            $amount = is_numeric($decryptedVerifyJson['amount'] ?? null) ? (float) $decryptedVerifyJson['amount'] : 0.0;
                            $responseOrderId = (string) ($decryptedVerifyJson['orderId'] ?? '');
                            $customer = CustomersInfo::where('customer_unique_id', $attempt->customer_unique_id)->first();

                            $attemptMatches = abs($amount - (float) $attempt->amount) <= 0.009
                                && $responseOrderId !== ''
                                && hash_equals((string) $attempt->merchant_reference, $responseOrderId)
                                && $attempt->provider_reference !== null
                                && hash_equals((string) $attempt->provider_reference, $paymentRefId);

                            if ($customer && $attemptMatches && $amount > 0) {
                                $providerTrxId = (string) ($decryptedVerifyJson['issuerPaymentRefNo'] ?? $paymentRefId);
                                $metadata = [
                                    'source' => 'nagad_callback',
                                    'payment_attempt_id' => $attempt->id,
                                    'external_invoice_id' => $attempt->merchant_reference,
                                    'verified_gateway_callback' => true,
                                ];
                                $this->paymentService->processSuccessPayment($customer, $amount, 'nagad', $providerTrxId, $metadata);

                                return redirect()->route('filament.portal.pages.dashboard')
                                    ->with('success', 'Payment of BDT '.$amount.' received successfully via Nagad. Your account is active.');
                            }
                        }
                    }
                }

                if ($attempt && $attempt->status !== 'paid') {
                    $attempt->forceFill([
                        'status' => 'needs_review',
                        'next_poll_at' => null,
                        'last_error' => 'Nagad callback did not pass response signature, status, amount, or order matching.',
                    ])->save();
                }

                Log::warning('Nagad validation did not match a verified payment.', [
                    'attempt_id' => $attempt?->id,
                    'provider_status_present' => isset($res['sensitiveData']),
                ]);

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Nagad verification failed.');

            } catch (\Exception $e) {
                Log::error('Nagad callback verification exception: '.$e->getMessage());

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Payment verification is temporarily unavailable. Please try again.');
            }
        }

        if ($attempt && $attempt->status !== 'paid') {
            $attempt->forceFill([
                'status' => 'cancelled',
                'next_poll_at' => null,
                'last_error' => 'Customer returned from Nagad without a successful payment callback.',
            ])->save();
        }

        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', 'Payment failed or cancelled. Status: '.$status);
    }

    private function verifySignature(string $plainText, string $signature, string $publicKey): bool
    {
        $decodedSignature = base64_decode($signature, true);

        return $decodedSignature !== false
            && openssl_verify($plainText, $decodedSignature, $this->cleanPublicKey($publicKey), OPENSSL_ALGO_SHA256) === 1;
    }

    private function cleanPrivateKey($key)
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN PRIVATE KEY-----') || str_contains($key, '-----BEGIN RSA PRIVATE KEY-----')) {
            return $key;
        }
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PRIVATE KEY-----";
    }

    private function cleanPublicKey($key)
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN PUBLIC KEY-----')) {
            return $key;
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----";
    }

    private function encrypt($data, $publicKey)
    {
        $publicKey = $this->cleanPublicKey($publicKey);
        $encrypted = '';
        if (openssl_public_encrypt($data, $encrypted, $publicKey)) {
            return base64_encode($encrypted);
        }
        Log::error('Nagad encryption failed: ' . openssl_error_string());
        return null;
    }

    private function decrypt($data, $privateKey)
    {
        $privateKey = $this->cleanPrivateKey($privateKey);
        $decrypted = '';
        if (openssl_private_decrypt(base64_decode($data), $decrypted, $privateKey)) {
            return $decrypted;
        }
        Log::error('Nagad decryption failed: ' . openssl_error_string());
        return null;
    }

    private function sign($data, $privateKey)
    {
        $privateKey = $this->cleanPrivateKey($privateKey);
        $signature = '';
        if (openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return base64_encode($signature);
        }
        Log::error('Nagad signing failed: ' . openssl_error_string());
        return null;
    }
}
