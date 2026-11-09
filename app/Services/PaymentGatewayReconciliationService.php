<?php

namespace App\Services;

use App\Models\CollectionSummary;
use App\Models\CustomersInfo;
use App\Models\PaymentGatewayAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentGatewayReconciliationService
{
    public function reconcile(int $limit = 25): array
    {
        $limit = min(100, max(1, $limit));
        $stats = ['checked' => 0, 'paid' => 0, 'pending' => 0, 'failed' => 0, 'needs_review' => 0, 'unavailable' => 0];

        $this->flagExpiredAttempts();

        $ids = PaymentGatewayAttempt::pollable()
            ->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->whereIn('gateway', ['bkash', 'nagad'])->whereNotNull('provider_reference');
                })->orWhere(function ($q): void {
                    $q->where('gateway', 'sslcommerz')->whereNotNull('merchant_reference');
                });
            })
            ->orderByRaw('COALESCE(next_poll_at, created_at) asc')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $attempt = $this->claimAttempt((int) $id);
            if (! $attempt) {
                continue;
            }

            $stats['checked']++;

            try {
                $result = match ($attempt->gateway) {
                    'bkash' => $this->queryBkash($attempt),
                    'nagad' => $this->queryNagad($attempt),
                    'sslcommerz' => $this->querySslCommerz($attempt),
                    default => ['status' => 'unavailable', 'message' => 'No verified transaction query adapter exists for this gateway.'],
                };

                $state = $this->applyResult($attempt, $result);
                $stats[$state] = ($stats[$state] ?? 0) + 1;
            } catch (Throwable $exception) {
                report($exception);
                $attempt->forceFill([
                    'status' => 'pending',
                    'last_error' => 'Provider query failed: '.class_basename($exception),
                    'next_poll_at' => now()->addMinutes($this->backoffMinutes((int) $attempt->poll_count)),
                ])->save();
                $stats['unavailable']++;
                Log::warning('Payment gateway reconciliation query failed.', [
                    'attempt_id' => $attempt->id,
                    'gateway' => $attempt->gateway,
                    'exception' => get_class($exception),
                ]);
            }
        }

        $stats['message'] = sprintf(
            'Payment reconciliation checked %d; paid %d; pending %d; failed %d; review %d; provider unavailable %d.',
            $stats['checked'],
            $stats['paid'],
            $stats['pending'],
            $stats['failed'],
            $stats['needs_review'],
            $stats['unavailable']
        );

        return $stats;
    }

    private function claimAttempt(int $id): ?PaymentGatewayAttempt
    {
        return DB::transaction(function () use ($id): ?PaymentGatewayAttempt {
            $attempt = PaymentGatewayAttempt::query()->whereKey($id)->lockForUpdate()->first();

            if (! $attempt
                || ! in_array($attempt->status, ['initiated', 'pending'], true)
                || $attempt->processed_at
                || ! $attempt->expires_at
                || $attempt->expires_at->lte(now())
                || ($attempt->next_poll_at && $attempt->next_poll_at->gt(now()))) {
                return null;
            }

            $attempt->forceFill([
                'last_polled_at' => now(),
                'poll_count' => (int) $attempt->poll_count + 1,
                // Claim the row before making an outbound request. The scheduler
                // also has withoutOverlapping(), but this protects manual runs too.
                'next_poll_at' => now()->addMinutes(2),
            ])->save();

            return $attempt;
        });
    }

    private function queryBkash(PaymentGatewayAttempt $attempt): array
    {
        $baseUrl = rtrim((string) (siteUrlSettings('payment_bkash_base_url') ?: config('services.bkash.base_url')), '/');
        $username = (string) (siteUrlSettings('payment_bkash_username') ?: config('services.bkash.username'));
        $password = (string) (siteUrlSettings('payment_bkash_password') ?: config('services.bkash.password'));
        $appKey = (string) (siteUrlSettings('payment_bkash_app_key') ?: config('services.bkash.app_key'));
        $appSecret = (string) (siteUrlSettings('payment_bkash_app_secret') ?: config('services.bkash.app_secret'));
        $apiType = (string) (siteUrlSettings('payment_bkash_api_type') ?: 'tokenized');

        if (! str_starts_with($baseUrl, 'https://') || $username === '' || $password === '' || $appKey === '' || $appSecret === '') {
            return ['status' => 'unavailable', 'message' => 'bKash API credentials or HTTPS endpoint are incomplete.'];
        }

        $tokenPath = $apiType === 'tokenized'
            ? '/tokenized/checkout/token/grant'
            : '/checkout/token/grant';

        $tokenResponse = Http::connectTimeout(5)->timeout(12)->retry(1, 250)
            ->withBasicAuth($username, $password)
            ->post($baseUrl.$tokenPath, ['app_key' => $appKey, 'app_secret' => $appSecret]);

        $tokenBody = $tokenResponse->json();
        $idToken = is_array($tokenBody) ? ($tokenBody['id_token'] ?? null) : null;

        if (! $tokenResponse->successful() || ! is_string($idToken) || $idToken === '') {
            return ['status' => 'unavailable', 'message' => 'bKash authentication was not accepted.'];
        }

        $request = Http::connectTimeout(5)->timeout(12)->retry(2, 300)
            ->acceptJson()
            ->withToken($idToken)
            ->withHeaders(['X-App-Key' => $appKey]);

        $url = $apiType === 'tokenized'
            ? $baseUrl.'/tokenized/checkout/payment/status?paymentID='.rawurlencode((string) $attempt->provider_reference)
            : $baseUrl.'/checkout/payment/query/'.rawurlencode((string) $attempt->provider_reference);

        $response = $request->get($url);
        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            return ['status' => 'unavailable', 'message' => 'bKash transaction status endpoint did not return a valid response.'];
        }

        $status = strtolower(trim((string) ($body['transactionStatus'] ?? $body['status'] ?? '')));
        $state = match ($status) {
            'completed', 'success', 'successful' => 'success',
            'failed', 'failure', 'cancelled', 'canceled', 'rejected' => 'failed',
            'initiated', 'pending', 'processing', 'authorized', 'authorization' => 'pending',
            default => 'unknown',
        };

        return [
            'status' => $state,
            'message' => 'bKash status: '.($status !== '' ? $status : 'unknown'),
            'amount' => $body['amount'] ?? null,
            'currency' => $body['currency'] ?? null,
            'provider_reference' => $body['paymentID'] ?? $body['paymentId'] ?? $attempt->provider_reference,
            'provider_transaction_id' => $body['trxID'] ?? null,
            'customer_reference' => $body['payerReference'] ?? null,
            'merchant_reference' => $body['merchantInvoiceNumber'] ?? $body['merchantInvoice'] ?? null,
        ];
    }

    private function queryNagad(PaymentGatewayAttempt $attempt): array
    {
        $baseUrl = rtrim((string) (siteUrlSettings('payment_nagad_base_url') ?: config('services.nagad.base_url')), '/');
        $merchantId = (string) (siteUrlSettings('payment_nagad_merchant_id') ?: config('services.nagad.merchant_id'));
        $privateKey = (string) siteUrlSettings('payment_nagad_private_key');
        $publicKey = (string) siteUrlSettings('payment_nagad_public_key');

        if (! str_starts_with($baseUrl, 'https://') || $merchantId === '' || $privateKey === '' || $publicKey === '') {
            return ['status' => 'unavailable', 'message' => 'Nagad merchant keys or HTTPS endpoint are incomplete.'];
        }

        $response = Http::connectTimeout(5)->timeout(12)->retry(2, 300)
            ->withHeaders([
                'Client-Type' => 'MERCHANT',
                'X-KM-Api-Version' => 'v1.0.0',
                'X-KM-IP-Address' => '127.0.0.1',
            ])
            ->get($baseUrl.'/payment/status/'.rawurlencode((string) $attempt->provider_reference));

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || empty($body['sensitiveData'])) {
            return ['status' => 'unavailable', 'message' => 'Nagad status endpoint did not return encrypted transaction data.'];
        }

        $decrypted = $this->decryptNagad((string) $body['sensitiveData'], $privateKey);
        if ($decrypted === null) {
            return ['status' => 'needs_review', 'message' => 'Nagad status response could not be decrypted.'];
        }

        // Nagad signs its encrypted response. Never mark a transaction paid if
        // a supplied response signature cannot be verified with the configured
        // Nagad public key.
        if (empty($body['signature']) || ! $this->verifyNagadSignature($decrypted, (string) $body['signature'], $publicKey)) {
            return ['status' => 'needs_review', 'message' => 'Nagad response signature is missing or invalid.'];
        }

        $data = json_decode($decrypted, true);
        if (! is_array($data)) {
            return ['status' => 'needs_review', 'message' => 'Nagad decrypted status payload is invalid.'];
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        $state = match ($status) {
            'success', 'successful', 'completed' => 'success',
            'failed', 'failure', 'cancelled', 'canceled' => 'failed',
            'pending', 'processing', 'in progress', 'initiated' => 'pending',
            default => 'unknown',
        };

        return [
            'status' => $state,
            'message' => 'Nagad status: '.($status !== '' ? $status : 'unknown'),
            'amount' => $data['amount'] ?? null,
            'currency' => ($data['currencyCode'] ?? null) === '050' ? 'BDT' : ($data['currency'] ?? null),
            'provider_reference' => $attempt->provider_reference,
            'provider_transaction_id' => $data['issuerPaymentRefNo'] ?? $data['paymentRefId'] ?? $attempt->provider_reference,
            'merchant_reference' => $data['orderId'] ?? null,
        ];
    }

    private function querySslCommerz(PaymentGatewayAttempt $attempt): array
    {
        $sandbox = (bool) (siteUrlSettings('payment_sslcommerz_sandbox') ?? true);
        $baseUrl = $sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
        $storeId = (string) siteUrlSettings('payment_sslcommerz_store_id');
        $storePassword = (string) siteUrlSettings('payment_sslcommerz_store_password');

        if ($storeId === '' || $storePassword === '') {
            return ['status' => 'unavailable', 'message' => 'SSLCommerz store credentials are incomplete.'];
        }

        $response = Http::connectTimeout(5)->timeout(15)->retry(2, 300)
            ->get($baseUrl.'/validator/api/merchantTransIDvalidationAPI.php', [
                'tran_id' => $attempt->merchant_reference,
                'store_id' => $storeId,
                'store_passwd' => $storePassword,
                'format' => 'json',
            ]);

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['APIConnect'] ?? null) !== 'DONE') {
            return ['status' => 'unavailable', 'message' => 'SSLCommerz transaction query did not authenticate or return a valid response.'];
        }

        $elements = $body['element'] ?? [];
        if (! is_array($elements)) {
            return ['status' => 'unknown', 'message' => 'SSLCommerz returned no transaction list.'];
        }

        $matches = array_values(array_filter($elements, static function ($row) use ($attempt): bool {
            return is_array($row)
                && hash_equals((string) $attempt->merchant_reference, (string) ($row['tran_id'] ?? ''));
        }));

        if (count($matches) !== 1) {
            return [
                'status' => count($matches) > 1 ? 'needs_review' : 'pending',
                'message' => count($matches) > 1
                    ? 'SSLCommerz returned multiple records for one merchant transaction reference.'
                    : 'SSLCommerz transaction has not appeared in the merchant query result yet.',
            ];
        }

        $row = $matches[0];
        $status = strtoupper(trim((string) ($row['status'] ?? '')));
        $state = match ($status) {
            'VALID', 'VALIDATED' => 'success',
            'PENDING' => 'pending',
            'FAILED', 'CANCELLED', 'CANCELED' => 'failed',
            default => 'unknown',
        };

        if ($state === 'success' && (string) ($row['risk_level'] ?? '0') === '1') {
            $state = 'needs_review';
        }

        return [
            'status' => $state,
            'message' => 'SSLCommerz status: '.($status !== '' ? $status : 'unknown'),
            'amount' => $row['amount'] ?? $row['currency_amount'] ?? null,
            'currency' => $row['currency'] ?? $row['currency_type'] ?? null,
            // SSLCommerz status query returns a validation ID, while the
            // stored provider_reference (when present) is its checkout session key.
            // Keep the session key stable so reference comparison remains meaningful.
            'provider_reference' => $attempt->provider_reference,
            'provider_transaction_id' => $row['bank_trx_id'] ?? $row['bank_tran_id'] ?? $attempt->merchant_reference,
            'merchant_reference' => $row['tran_id'] ?? null,
            'risk_level' => $row['risk_level'] ?? null,
        ];
    }

    private function applyResult(PaymentGatewayAttempt $attempt, array $result): string
    {
        // A provider callback may complete this same attempt while the scheduled
        // poll is waiting on the remote gateway. Never let a late poll overwrite
        // a final state written by the callback.
        $attempt->refresh();

        if ($attempt->status === 'paid' || $attempt->processed_at) {
            return 'paid';
        }

        if (! in_array($attempt->status, ['initiated', 'pending'], true)) {
            return match ($attempt->status) {
                'failed', 'cancelled' => 'failed',
                'needs_review' => 'needs_review',
                default => 'pending',
            };
        }

        $status = $result['status'] ?? 'unknown';

        if ($status === 'unavailable') {
            $attempt->forceFill([
                'status' => 'pending',
                'last_error' => (string) ($result['message'] ?? 'Provider query unavailable.'),
                'next_poll_at' => now()->addMinutes(15),
            ])->save();

            return 'unavailable';
        }

        if ($status === 'needs_review') {
            $attempt->forceFill([
                'status' => 'needs_review',
                'last_error' => (string) ($result['message'] ?? 'Provider response requires manual review.'),
                'next_poll_at' => null,
                'provider_metadata' => $this->safeMetadata($attempt, $result),
            ])->save();

            return 'needs_review';
        }

        if ($status === 'failed') {
            $attempt->forceFill([
                'status' => 'failed',
                'last_error' => (string) ($result['message'] ?? 'Provider confirmed payment failure.'),
                'next_poll_at' => null,
                'provider_metadata' => $this->safeMetadata($attempt, $result),
            ])->save();

            return 'failed';
        }

        if ($status !== 'success') {
            $attempt->forceFill([
                'status' => 'pending',
                'last_error' => (string) ($result['message'] ?? 'Provider has not confirmed a final status.'),
                'next_poll_at' => now()->addMinutes($this->backoffMinutes((int) $attempt->poll_count)),
                'provider_metadata' => $this->safeMetadata($attempt, $result),
            ])->save();

            return 'pending';
        }

        $amount = is_numeric($result['amount'] ?? null) ? round((float) $result['amount'], 2) : null;
        $currency = strtoupper(trim((string) ($result['currency'] ?? '')));
        $merchantReference = (string) ($result['merchant_reference'] ?? '');
        $providerReference = (string) ($result['provider_reference'] ?? '');
        $customerReference = (string) ($result['customer_reference'] ?? '');

        if ($amount === null || abs($amount - (float) $attempt->amount) > 0.009
            || $currency !== 'BDT'
            || $merchantReference === ''
            || ! hash_equals((string) $attempt->merchant_reference, $merchantReference)
            || ($providerReference !== '' && $attempt->provider_reference !== null
                && ! hash_equals((string) $attempt->provider_reference, $providerReference))
            || ($attempt->gateway === 'bkash'
                && ($customerReference === '' || ! hash_equals((string) $attempt->customer_unique_id, $customerReference)))
            || ($customerReference !== '' && ! hash_equals((string) $attempt->customer_unique_id, $customerReference))) {
            $attempt->forceFill([
                'status' => 'needs_review',
                'last_error' => 'Provider success response did not match the stored customer, merchant reference, amount, or BDT currency.',
                'next_poll_at' => null,
                'provider_metadata' => $this->safeMetadata($attempt, $result),
            ])->save();

            return 'needs_review';
        }

        $customer = CustomersInfo::query()
            ->where('customer_unique_id', $attempt->customer_unique_id)
            ->first();

        if (! $customer || ! $customer->billing) {
            $attempt->forceFill([
                'status' => 'needs_review',
                'last_error' => 'Confirmed provider payment has no matching customer billing account.',
                'next_poll_at' => null,
            ])->save();

            return 'needs_review';
        }

        $transactionId = trim((string) ($result['provider_transaction_id'] ?? ''));
        if ($transactionId === '') {
            $attempt->forceFill([
                'status' => 'needs_review',
                'last_error' => 'Provider confirmed success without a transaction identifier.',
                'next_poll_at' => null,
            ])->save();

            return 'needs_review';
        }

        $existing = CollectionSummary::query()
            ->where('transaction_id', $transactionId)
            ->where('payment_status', 'paid')
            ->first();

        if ($existing) {
            if ($existing->customer_collection_unique_id === $attempt->customer_unique_id
                && abs((float) $existing->collection_amount - (float) $attempt->amount) <= 0.009) {
                $attempt->forceFill([
                    'status' => 'paid',
                    'provider_transaction_id' => $transactionId,
                    'processed_at' => $existing->collection_date,
                    'next_poll_at' => null,
                    'last_error' => null,
                    'provider_metadata' => $this->safeMetadata($attempt, $result),
                ])->save();

                return 'paid';
            }

            $attempt->forceFill([
                'status' => 'needs_review',
                'last_error' => 'Provider transaction identifier is already attached to a different collection.',
                'next_poll_at' => null,
            ])->save();

            return 'needs_review';
        }

        try {
            $processed = app(PaymentService::class)->processSuccessPayment(
                $customer,
                (float) $attempt->amount,
                $attempt->gateway,
                $transactionId,
                [
                    'source' => 'scheduled_gateway_reconciliation',
                    'payment_attempt_id' => $attempt->id,
                    'external_invoice_id' => $attempt->merchant_reference,
                ]
            );

            if (! $processed) {
                $attempt->refresh();
                if ($attempt->status === 'paid' || $attempt->processed_at) {
                    return 'paid';
                }

                $attempt->forceFill([
                    'status' => 'needs_review',
                    'last_error' => 'Payment service reported an existing transaction that could not be matched safely.',
                    'next_poll_at' => null,
                ])->save();

                return 'needs_review';
            }

            $attempt->refresh();
            return $attempt->status === 'paid' ? 'paid' : 'needs_review';
        } catch (Throwable $exception) {
            report($exception);
            $attempt->refresh();

            // A callback may have committed successfully while the poller was
            // waiting for a DB lock. Do not revert that paid state on a late error.
            if ($attempt->status === 'paid' || $attempt->processed_at) {
                return 'paid';
            }

            if (! in_array($attempt->status, ['initiated', 'pending'], true)) {
                return $attempt->status === 'failed' ? 'failed' : 'needs_review';
            }

            $attempt->forceFill([
                'status' => 'pending',
                'last_error' => 'Provider confirmed success but billing processing failed: '.class_basename($exception),
                'next_poll_at' => now()->addMinutes(2),
            ])->save();

            Log::critical('Verified payment could not be applied to customer billing.', [
                'attempt_id' => $attempt->id,
                'gateway' => $attempt->gateway,
                'customer_unique_id' => $attempt->customer_unique_id,
                'provider_transaction_id' => $transactionId,
            ]);

            return 'pending';
        }
    }

    private function flagExpiredAttempts(): void
    {
        PaymentGatewayAttempt::query()
            ->whereIn('status', ['initiated', 'pending'])
            ->whereNull('processed_at')
            ->where('expires_at', '<=', now())
            ->update([
                // Stop automatic polling, but allow a later authentic provider
                // callback to settle the transaction after strict verification.
                'status' => 'expired',
                'last_error' => 'Payment attempt expired without a final provider confirmation; waiting for a verified callback or manual gateway review.',
                'next_poll_at' => null,
                'updated_at' => now(),
            ]);

        PaymentGatewayAttempt::query()
            ->whereIn('status', ['initiated', 'pending'])
            ->whereNull('processed_at')
            ->whereNull('provider_reference')
            ->where('gateway', '!=', 'sslcommerz')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => 'needs_review',
                'last_error' => 'Checkout initiation did not return a provider reference; automatic status query is not possible.',
                'next_poll_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function backoffMinutes(int $pollCount): int
    {
        return match (true) {
            $pollCount < 3 => 2,
            $pollCount < 8 => 5,
            $pollCount < 15 => 10,
            default => 15,
        };
    }

    private function safeMetadata(PaymentGatewayAttempt $attempt, array $result): array
    {
        return [
            'last_provider_status' => (string) ($result['message'] ?? ''),
            'last_polled_at' => now()->toIso8601String(),
            'gateway' => $attempt->gateway,
        ];
    }

    private function decryptNagad(string $encrypted, string $privateKey): ?string
    {
        $key = trim($privateKey);
        if (! str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PRIVATE KEY-----\n".chunk_split($key, 64, "\n")."-----END PRIVATE KEY-----";
        }

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false || ! openssl_private_decrypt($decoded, $plainText, $key)) {
            return null;
        }

        return $plainText;
    }

    private function verifyNagadSignature(string $plainText, string $signature, string $publicKey): bool
    {
        $key = trim($publicKey);
        if (! str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n")."-----END PUBLIC KEY-----";
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($plainText, $decodedSignature, $key, OPENSSL_ALGO_SHA256) === 1;
    }
}
