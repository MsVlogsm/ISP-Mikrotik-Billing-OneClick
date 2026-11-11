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

class SslCommerzPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    private function getSslConfig()
    {
        $sandbox = siteUrlSettings('payment_sslcommerz_sandbox') ?? true;
        $baseUrl = $sandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        return [
            'base_url' => $baseUrl,
            'store_id' => siteUrlSettings('payment_sslcommerz_store_id'),
            'store_passwd' => siteUrlSettings('payment_sslcommerz_store_password'),
            'sandbox' => $sandbox,
        ];
    }

    public function initiate(Request $request)
    {
        if (! (bool) siteUrlSettings('payment_sslcommerz_enabled')) {
            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'sslcommerz payments are currently unavailable.');
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
                'gateway' => 'SSLCommerz',
                'customer' => $customer,
                'amount' => $amount,
                'reason' => null,
            ]);
        }

        try {
            $config = $this->getSslConfig();

            if (empty($config['store_id']) || empty($config['store_passwd'])) {
                if ($isLocal) {
                    return response()->view('payment.mock_checkout', [
                        'gateway' => 'SSLCommerz',
                        'customer' => $customer,
                        'amount' => $amount,
                        'reason' => 'SSLCommerz store ID or password is not configured.',
                    ]);
                }

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'SSLCommerz is not properly configured.');
            }

            $tranId = 'XLK'.Str::upper(Str::random(20));
            $attempt = PaymentGatewayAttempt::create([
                'gateway' => 'sslcommerz',
                'customer_unique_id' => $customer->customer_unique_id,
                'amount' => round((float) $amount, 2),
                'currency' => 'BDT',
                'merchant_reference' => $tranId,
                'status' => 'initiated',
                'expires_at' => now()->addDay(),
                'next_poll_at' => now()->addMinute(),
            ]);

            $post_data = [
                'store_id' => $config['store_id'],
                'store_passwd' => $config['store_passwd'],
                'total_amount' => (string) round($amount, 2),
                'currency' => 'BDT',
                'tran_id' => $tranId,
                'success_url' => route('payment.sslcommerz.callback'),
                'fail_url' => route('payment.sslcommerz.callback'),
                'cancel_url' => route('payment.sslcommerz.callback'),

                // Customer Information
                'cus_name' => $customer->customer_name ?: 'ISP Customer',
                'cus_email' => $customer->email ?: 'customer@example.com',
                'cus_add1' => $customer->address ?: 'Dhaka',
                'cus_city' => 'Dhaka',
                'cus_country' => 'Bangladesh',
                'cus_phone' => $customer->mobile ?: '01700000000',

                // Non-physical goods
                'shipping_method' => 'NO',
                'product_name' => 'Internet Bill',
                'product_category' => 'Internet',
                'product_profile' => 'non-physical-goods',
            ];

            $response = Http::asForm()->post($config['base_url'].'/gwprocess/v4/api.php', $post_data);
            $res = $response->json();

            if (isset($res['status']) && $res['status'] === 'SUCCESS' && isset($res['GatewayPageURL'])) {
                $attempt->forceFill([
                    'provider_reference' => $res['sessionkey'] ?? $res['session_key'] ?? null,
                    'status' => 'pending',
                    'next_poll_at' => now()->addMinute(),
                    'provider_metadata' => ['sandbox' => (bool) $config['sandbox']],
                ])->save();

                session([
                    'ssl_amount' => $amount,
                    'ssl_customer_id' => $customer->id,
                    'ssl_attempt_uuid' => $attempt->attempt_uuid,
                ]);

                return redirect()->away($res['GatewayPageURL']);
            }

            $attempt->forceFill([
                'status' => 'failed',
                'next_poll_at' => null,
                'last_error' => 'SSLCommerz rejected the checkout session request.',
            ])->save();

            Log::error('SSLCommerz Session Init Failed: '.json_encode($res));

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'SSLCommerz',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'SSLCommerz API error: '.($res['failedreason'] ?? 'Session creation failed.'),
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'SSLCommerz session creation failed. Please try again.');

        } catch (\Exception $e) {
            if (isset($attempt) && $attempt instanceof PaymentGatewayAttempt && $attempt->status === 'initiated') {
                $attempt->forceFill([
                    'status' => 'pending',
                    'next_poll_at' => now()->addMinute(),
                    'last_error' => 'Session initiation result is uncertain; merchant transaction ID retained for status polling.',
                ])->save();
            }

            Log::error('SSLCommerz initiate exception: '.$e->getMessage());

            if ($isLocal) {
                return response()->view('payment.mock_checkout', [
                    'gateway' => 'SSLCommerz',
                    'customer' => $customer,
                    'amount' => $amount,
                    'reason' => 'Payment gateway connection failed. Please try again.',
                ]);
            }

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'SSLCommerz service temporarily unavailable. Please try again.');
        }
    }

    public function callback(Request $request)
    {
        $status = strtoupper(trim((string) $request->input('status', '')));
        $tranId = trim((string) $request->input('tran_id', ''));
        $valId = trim((string) $request->input('val_id', ''));
        $attempt = $tranId !== ''
            ? PaymentGatewayAttempt::query()->where('gateway', 'sslcommerz')->where('merchant_reference', $tranId)->first()
            : null;
        if (! $attempt && session('ssl_attempt_uuid')) {
            $attempt = PaymentGatewayAttempt::query()
                ->where('attempt_uuid', session('ssl_attempt_uuid'))
                ->where('gateway', 'sslcommerz')
                ->first();
        }

        if (! $attempt) {
            Log::warning('SSLCommerz callback rejected because no local payment attempt matched.', [
                'merchant_reference_present' => $tranId !== '',
                'validation_reference_present' => $valId !== '',
            ]);

            return redirect()->route('filament.portal.pages.pay-bill')
                ->with('error', 'Payment verification request was invalid. No matching checkout was found.');
        }

        if (in_array($status, ['VALID', 'VALIDATED', 'SUCCESS'], true) && $valId !== '') {
            try {
                $config = $this->getSslConfig();

                // Double check validity using SSLCommerz transaction validator API
                $validator = Http::connectTimeout(5)->timeout(15)->retry(1, 250)
                    ->get($config['base_url'].'/validator/api/validationserverAPI.php', [
                        'val_id' => $valId,
                        'store_id' => $config['store_id'],
                        'store_passwd' => $config['store_passwd'],
                        'v' => 1,
                        'format' => 'json',
                    ]);

                $res = $validator->json();

                $validationStatus = strtoupper((string) ($res['status'] ?? ''));
                $verifiedTranId = trim((string) ($res['tran_id'] ?? ''));
                $verifiedAmount = is_numeric($res['amount'] ?? null) ? (float) $res['amount'] : 0.0;
                $verifiedCurrency = strtoupper((string) ($res['currency'] ?? $res['currency_type'] ?? ''));
                $attemptMatches = $verifiedTranId !== ''
                    && hash_equals((string) $attempt->merchant_reference, $verifiedTranId)
                    && abs($verifiedAmount - (float) $attempt->amount) <= 0.009
                    && $verifiedCurrency === 'BDT';
                $riskRequiresReview = (string) ($res['risk_level'] ?? '0') === '1';

                if (in_array($validationStatus, ['VALID', 'VALIDATED'], true)
                    && $attemptMatches
                    && ! $riskRequiresReview) {
                    $amount = $verifiedAmount;
                    $trxID = trim((string) ($res['bank_trx_id'] ?? $res['bank_tran_id'] ?? $verifiedTranId));

                    $customer = CustomersInfo::where('customer_unique_id', $attempt->customer_unique_id)->first();

                    if ($customer && $amount > 0 && $trxID !== '') {
                        $metadata = [
                            'source' => 'sslcommerz_callback',
                            'payment_attempt_id' => $attempt->id,
                            'external_invoice_id' => $attempt->merchant_reference,
                            'verified_gateway_callback' => true,
                        ];
                        $this->paymentService->processSuccessPayment($customer, $amount, 'sslcommerz', $trxID, $metadata);

                        return redirect()->route('filament.portal.pages.dashboard')
                            ->with('success', 'Payment of BDT '.$amount.' received successfully via SSLCommerz. Your account is active.');
                    }
                }

                if ($attempt && $attempt->status !== 'paid') {
                    $attempt->forceFill([
                        'status' => $riskRequiresReview || ! $attemptMatches ? 'needs_review' : 'pending',
                        'next_poll_at' => $riskRequiresReview || ! $attemptMatches ? null : now()->addMinutes(2),
                        'last_error' => $riskRequiresReview
                            ? 'SSLCommerz marked this transaction for risk review.'
                            : 'SSLCommerz callback validation did not match the stored checkout.',
                    ])->save();
                }

                Log::error('SSLCommerz Validation Failed: '.json_encode($res));

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Transaction validation failed.');

            } catch (\Exception $e) {
                Log::error('SSLCommerz callback verification exception: '.$e->getMessage());

                return redirect()->route('filament.portal.pages.pay-bill')
                    ->with('error', 'Payment verification is temporarily unavailable. Please try again.');
            }
        }

        if ($attempt && $attempt->status !== 'paid') {
            $attempt->forceFill([
                'status' => in_array($status, ['FAILED', 'CANCELLED', 'CANCELED'], true) ? 'failed' : 'pending',
                'next_poll_at' => in_array($status, ['FAILED', 'CANCELLED', 'CANCELED'], true) ? null : now()->addMinute(),
                'last_error' => 'SSLCommerz returned callback status: '.$status,
            ])->save();
        }

        return redirect()->route('filament.portal.pages.pay-bill')
            ->with('error', 'Payment failed or cancelled. Status: '.$status);
    }
}
