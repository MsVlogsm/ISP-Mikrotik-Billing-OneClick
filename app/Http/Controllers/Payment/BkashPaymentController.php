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

class BkashPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    private function getBkashConfig()
    {
        return [
            'base_url' => siteUrlSettings('payment_bkash_base_url') ?: config('services.bkash.base_url'),
            'username' => siteUrlSettings('payment_bkash_username') ?: config('services.bkash.username'),
            'password' => siteUrlSettings('payment_bkash_password') ?: config('services.bkash.password'),
            'app_key' => siteUrlSettings('payment_bkash_app_key') ?: config('services.bkash.app_key'),
            'app_secret' => siteUrlSettings('payment_bkash_app_secret') ?: config('services.bkash.app_secret'),
        ];
    }

    private function generateToken()
    {
        $config = $this->getBkashConfig();
        $apiType = siteUrlSettings('payment_bkash_api_type') ?: 'tokenized';

        $tokenUrl = ($apiType === 'tokenized') 
            ? $config['base_url'].'/tokenized/checkout/token/grant'
            : $config['base_url'].'/checkout/token/grant';

        $response = Http::withBasicAuth($config['username'], $config['password'])
            ->post($tokenUrl, [
                'app_key' => $config['app_key'],
                'app_secret' => $config['app_secret'],
            ]);

        $body = $response->json();

        if (! isset($body['id_token'])) {
            Log::error('bKash token generation failed: '.json_encode($body));
            throw new \Exception('bKash gateway authentication failed.');
        }

        return $body['id_token'];
    }

    public function initiate(Request $request)
    {
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
            return $this->showMockPaymentPage('bKash', $customer, $amount);
        }

        $merchantInvoiceNumber = 'XLK'.Str::upper(Str::random(16));
        $attempt = PaymentGatewayAttempt::create([
            'gateway' => 'bkash',
            'customer_unique_id' => $customer->customer_unique_id,
            'amount' => round((float) $amount, 2),
            'currency' => 'BDT',
            'merchant_reference' => $merchantInvoiceNumber,
            'status' => 'initiated',
            'expires_at' => now()->addDay(),
            'next_poll_at' => now()->addMinute(),
        ]);

        try {
            $idToken = $this->generateToken();
            $config = $this->getBkashConfig();
            $apiType = siteUrlSettings('payment_bkash_api_type') ?: 'tokenized';

            $callbackURL = route('payment.bkash.callback');

            if ($apiType === 'tokenized') {
                $createUrl = $config['base_url'].'/tokenized/checkout/create';
                $payload = [
                    'mode' => '0011',
                    'payerReference' => $customer->customer_unique_id,
                    'callbackURL' => $callbackURL,
                    'amount' => (string) round($amount, 2),
                    'merchantInvoiceNumber' => $merchantInvoiceNumber,
                    'intent' => 'sale',
                ];
            } else {
                $createUrl = $config['base_url'].'/checkout/payment/create';
                $payload = [
                    'amount' => (string) round($amount, 2),
                    'currency' => 'BDT',
                    'intent' => 'sale',
                    'merchantInvoiceNumber' => $merchantInvoiceNumber,
                    'callbackURL' => $callbackURL,
                ];
            }

            $payment = Http::withToken($idToken)
                ->withHeaders([
                    'X-App-Key' => $config['app_key'],
                ])
                ->post($createUrl, $payload);

            $res = $payment->json();

            if (isset($res['bkashURL'])) {
                $providerPaymentId = (string) ($res['paymentID'] ?? $res['paymentId'] ?? '');
                $attempt->forceFill([
                    'provider_reference' => $providerPaymentId !== '' ? $providerPaymentId : null,
                    'status' => $providerPaymentId !== '' ? 'pending' : 'needs_review',
                    'next_poll_at' => $providerPaymentId !== '' ? now()->addMinute() : null,
                    'last_error' => $providerPaymentId === '' ? 'bKash checkout response did not include a payment ID.' : null,
                    'provider_metadata' => ['api_type' => $apiType],
                ])->save();

                session([
                    'bkash_amount' => $amount,
                    'bkash_customer_id' => $customer->id,
                    'bkash_attempt_uuid' => $attempt->attempt_uuid,
                ]);

                return redirect()->away($res['bkashURL']);
            }

            $attempt->forceFill([
                'status' => 'failed',
                'next_poll_at' => null,
                'last_error' => 'bKash rejected the checkout session request.',
            ])->save();

            Log::error('bKash Create Payment Failed: '.json_encode($res));

            // If API fails on local development, redirect to mock page
            if ($isLocal) {
                return $this->showMockPaymentPage('bKash', $customer, $amount, 'bKash API returned error: '.($res['errorMessage'] ?? 'Unknown error'));
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'bKash payment initiation failed: '.($res['errorMessage'] ?? 'Unknown error'));

        } catch (\Exception $e) {
            $attempt->refresh();
            if ($attempt->status === 'initiated') {
                $attempt->forceFill([
                    'status' => 'needs_review',
                    'next_poll_at' => null,
                    'last_error' => 'Checkout initiation outcome is unknown; no provider payment ID was returned.',
                ])->save();
            }

            Log::error('bKash initiate exception: '.$e->getMessage());

            if ($isLocal) {
                return $this->showMockPaymentPage('bKash', $customer, $amount, 'Connection failed: '.$e->getMessage());
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'bKash service temporarily unavailable. Please try again.');
        }
    }

    public function callback(Request $request)
    {
        $status = strtolower(trim((string) $request->query('status', '')));
        $paymentID = trim((string) $request->query('paymentID', ''));
        $attempt = $paymentID !== ''
            ? PaymentGatewayAttempt::query()->where('gateway', 'bkash')->where('provider_reference', $paymentID)->first()
            : null;
        if (! $attempt && session('bkash_attempt_uuid')) {
            $attempt = PaymentGatewayAttempt::query()
                ->where('attempt_uuid', session('bkash_attempt_uuid'))
                ->where('gateway', 'bkash')
                ->first();
        }

        if ($status === 'success' && $paymentID !== '') {
            try {
                $idToken = $this->generateToken();
                $config = $this->getBkashConfig();
                $apiType = siteUrlSettings('payment_bkash_api_type') ?: 'tokenized';

                $executeUrl = ($apiType === 'tokenized')
                    ? $config['base_url'].'/tokenized/checkout/execute'
                    : $config['base_url'].'/checkout/payment/execute';

                $execution = Http::withToken($idToken)
                    ->withHeaders([
                        'X-App-Key' => $config['app_key'],
                    ])
                    ->post($executeUrl, [
                        'paymentID' => $paymentID,
                    ]);

                $res = $execution->json();

                if (isset($res['statusCode']) && $res['statusCode'] === '0000'
                    && strtolower((string) ($res['transactionStatus'] ?? '')) === 'completed') {
                    $trxID = trim((string) ($res['trxID'] ?? ''));
                    $amount = is_numeric($res['amount'] ?? null) ? (float) $res['amount'] : 0.0;

                    $customer = $attempt
                        ? CustomersInfo::where('customer_unique_id', $attempt->customer_unique_id)->first()
                        : null;

                    $customerUniqueId = (string) ($res['payerReference'] ?? '');
                    if (! $customer && $customerUniqueId !== '') {
                        $customer = CustomersInfo::where('customer_unique_id', $customerUniqueId)->first();
                    }

                    if (! $customer && session('bkash_customer_id')) {
                        $customer = CustomersInfo::find(session('bkash_customer_id'));
                    }

                    $responseInvoice = (string) ($res['merchantInvoiceNumber'] ?? $res['merchantInvoice'] ?? '');
                    $responseCurrency = strtoupper((string) ($res['currency'] ?? 'BDT'));
                    $attemptMatches = ! $attempt || (
                        abs($amount - (float) $attempt->amount) <= 0.009
                        && $customerUniqueId !== ''
                        && hash_equals((string) $attempt->customer_unique_id, $customerUniqueId)
                        && $responseInvoice !== ''
                        && hash_equals((string) $attempt->merchant_reference, $responseInvoice)
                        && $responseCurrency === 'BDT'
                        && ($attempt->provider_reference === null || hash_equals((string) $attempt->provider_reference, $paymentID))
                    );

                    if ($customer && $attemptMatches && $trxID !== '' && $amount > 0) {
                        $metadata = $attempt ? [
                            'source' => 'bkash_callback',
                            'payment_attempt_id' => $attempt->id,
                            'external_invoice_id' => $attempt->merchant_reference,
                            'verified_gateway_callback' => true,
                        ] : [];
                        $processed = $this->paymentService->processSuccessPayment($customer, $amount, 'bkash', $trxID, $metadata);

                        if (! $processed) {
                            return redirect()->route('filament.portal.pages.dashboard')
                                ->with('success', 'Payment of BDT '.$amount.' was already processed via bKash.');
                        }

                        return redirect()->route('filament.portal.pages.dashboard')
                            ->with('success', 'Payment of BDT '.$amount.' received successfully via bKash. Your account is active.');
                    }
                }

                if ($attempt && $attempt->status !== 'paid') {
                    $attempt->forceFill([
                        'status' => 'needs_review',
                        'next_poll_at' => null,
                        'last_error' => 'bKash callback response was not a verified Completed transaction or did not match the stored checkout.',
                    ])->save();
                }

                Log::warning('bKash Execution Failed or did not match the stored checkout.', [
                    'attempt_id' => $attempt?->id,
                    'status_code' => $res['statusCode'] ?? null,
                    'transaction_status' => $res['transactionStatus'] ?? null,
                ]);

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Payment verification failed. Please try again or contact support.');

            } catch (\Exception $e) {
                Log::error('bKash callback execution exception: '.$e->getMessage());

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Payment verification is temporarily unavailable. Please try again.');
            }
        }

        if ($attempt && $attempt->status !== 'paid') {
            $attempt->forceFill([
                'status' => 'cancelled',
                'next_poll_at' => null,
                'last_error' => 'Customer returned from bKash without a successful payment callback.',
            ])->save();
        }

        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', $paymentID === ''
                ? 'Payment verification request was invalid.'
                : 'Payment process cancelled or failed.');
    }

    public function mockSubmit(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers_infos,id',
            'amount' => 'required|numeric|min:1',
            'gateway' => 'required',
        ]);

        $customer = CustomersInfo::find($request->customer_id);
        $amount = (float) $request->amount;
        $gateway = strtolower($request->gateway);
        $trxID = strtoupper($gateway).'_MOCK_'.strtoupper(uniqid());

        try {
            $this->paymentService->processSuccessPayment($customer, $amount, $gateway, $trxID);

            return redirect()->route('filament.portal.pages.dashboard')
                ->with('success', 'Payment of BDT '.$amount.' simulated successfully via '.strtoupper($gateway).'. Your account is active.');
        } catch (\Exception $e) {
            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Failed to process simulated payment. Please try again.');
        }
    }

    private function showMockPaymentPage($gateway, $customer, $amount, $reason = null)
    {
        return response()->view('payment.mock_checkout', [
            'gateway' => $gateway,
            'customer' => $customer,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }
}
