<?php

namespace App\Services;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomerAdvanceTransaction;
use App\Models\CustomerAdvanceWallet;
use App\Models\CustomersInfo;
use App\Models\PaymentSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerAdvanceService
{
    public function deposit(CustomersInfo $customer, array $data): CustomerAdvanceTransaction
    {
        return DB::transaction(function () use ($customer, $data): CustomerAdvanceTransaction {
            $existing = CustomerAdvanceTransaction::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'deposit'
                    || abs((float) $existing->amount - round((float) $data['amount'], 2)) > 0.009) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different advance transaction.']);
                }
                return $existing;
            }

            BillingInfo::query()
                ->where('customer_bill_unique_id', $customer->customer_unique_id)
                ->lockForUpdate()->firstOrFail();

            $wallet = $this->lockedWallet($customer);
            $existing = CustomerAdvanceTransaction::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'deposit'
                    || abs((float) $existing->amount - round((float) $data['amount'], 2)) > 0.009) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different advance transaction.']);
                }
                return $existing;
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Advance deposit must be greater than zero.']);
            }

            if (filled($data['transaction_id'] ?? null) && CollectionSummary::query()
                ->where('transaction_id', trim((string) $data['transaction_id']))
                ->exists()) {
                throw ValidationException::withMessages(['transaction_id' => 'This receipt / transaction ID has already been recorded.']);
            }

            $paymentDate = Carbon::parse($data['payment_date'] ?? now());
            $details = array_filter([
                'purpose' => 'customer_advance_wallet_deposit',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => auth()->user()?->email,
            ], static fn ($value) => $value !== null && $value !== '');

            $collection = CollectionSummary::create([
                'customer_collection_unique_id' => $customer->customer_unique_id,
                'collection_date' => $paymentDate,
                'collection_amount' => $amount,
                'collected_by' => auth()->user()?->email ?: 'Advance Wallet Deposit',
                'payment_type' => 'advance',
                'payment_method' => $data['payment_method'],
                'transaction_id' => filled($data['transaction_id'] ?? null) ? trim((string) $data['transaction_id']) : null,
                'payment_attempt_key' => $data['idempotency_key'],
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'payment_status' => 'paid',
                'bill_month' => null,
            ]);

            $wallet->balance = round((float) $wallet->balance + $amount, 2);
            $wallet->save();

            return CustomerAdvanceTransaction::create([
                'wallet_id' => $wallet->id,
                'customer_id' => $customer->id,
                'customer_unique_id' => $customer->customer_unique_id,
                'entry_type' => 'deposit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'collection_summary_id' => $collection->id,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => auth()->id(),
                'notes' => $data['notes'] ?? null,
            ]);
        }, attempts: 3);
    }

    /** Reverse an unused portion of a recorded deposit. Ledger entries are append-only. */
    public function reverseDeposit(CustomersInfo $customer, int $depositId, float $requestedAmount, string $idempotencyKey, string $reason): CustomerAdvanceTransaction
    {
        return DB::transaction(function () use ($customer, $depositId, $requestedAmount, $idempotencyKey, $reason): CustomerAdvanceTransaction {
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id || $existing->entry_type !== 'reversal'
                    || (int) $existing->related_transaction_id !== $depositId
                    || abs((float) $existing->amount - round($requestedAmount, 2)) > 0.009) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different wallet reversal.']);
                }
                return $existing;
            }

            BillingInfo::query()->where('customer_bill_unique_id', $customer->customer_unique_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWallet($customer);
            $deposit = CustomerAdvanceTransaction::query()->whereKey($depositId)->where('customer_id', $customer->id)
                ->where('entry_type', 'deposit')->where('direction', 'credit')->lockForUpdate()->firstOrFail();
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $existing;

            $alreadyReversed = (float) CustomerAdvanceTransaction::query()->where('related_transaction_id', $deposit->id)
                ->where('entry_type', 'reversal')->sum('amount');
            $remaining = round((float) $deposit->amount - $alreadyReversed, 2);
            $amount = round($requestedAmount, 2);
            $available = round((float) $wallet->balance, 2);
            if ($amount <= 0 || $amount > $remaining || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'Reversal cannot exceed the unreversed deposit or the customer’s available wallet balance. Allocated credit cannot be refunded from the wallet.']);
            }

            $wallet->balance = round($available - $amount, 2);
            $wallet->save();

            return CustomerAdvanceTransaction::create([
                'wallet_id' => $wallet->id,
                'customer_id' => $customer->id,
                'customer_unique_id' => $customer->customer_unique_id,
                'entry_type' => 'reversal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'payment_method' => $deposit->payment_method,
                'transaction_id' => $deposit->transaction_id,
                'related_transaction_id' => $deposit->id,
                'idempotency_key' => $idempotencyKey,
                'created_by' => auth()->id(),
                'notes' => trim($reason),
            ]);
        }, attempts: 3);
    }

    public function allocateToCurrentBill(CustomersInfo $customer, float $requestedAmount, string $idempotencyKey, ?string $notes = null): CustomerAdvanceTransaction
    {
        return DB::transaction(function () use ($customer, $requestedAmount, $idempotencyKey, $notes): CustomerAdvanceTransaction {
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'allocation'
                    || abs((float) $existing->amount - round($requestedAmount, 2)) > 0.009) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different allocation.']);
                }
                return $existing;
            }

            $billing = BillingInfo::query()
                ->where('customer_bill_unique_id', $customer->customer_unique_id)
                ->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWallet($customer);
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'allocation'
                    || abs((float) $existing->amount - round($requestedAmount, 2)) > 0.009) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different allocation.']);
                }
                return $existing;
            }

            $due = max(0, round((float) $billing->due_amount, 2));
            $available = round((float) $wallet->balance, 2);
            $amount = round($requestedAmount, 2);
            if ($amount <= 0 || $amount > $due || $amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => 'Allocation must not exceed the current due or available advance balance.',
                ]);
            }

            $wallet->balance = round($available - $amount, 2);
            $wallet->save();

            $transaction = CustomerAdvanceTransaction::create([
                'wallet_id' => $wallet->id,
                'customer_id' => $customer->id,
                'customer_unique_id' => $customer->customer_unique_id,
                'entry_type' => 'allocation',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'bill_month' => now()->format('Y-m'),
                'idempotency_key' => $idempotencyKey,
                'created_by' => auth()->id(),
                'notes' => $notes ?: 'Manual advance wallet allocation to current bill.',
            ]);

            $billing->due_amount = round($due - $amount, 2);
            if ($billing->billing_type === 'prepaid') {
                $expiry = PrepaidBillingCycle::expiryAfterPayment(
                    $customer->connection_date ?: $billing->auto_disable_date,
                    $billing->auto_disable_date,
                    $due,
                    $amount,
                    (float) $billing->monthly_rent,
                );
                if ($expiry) $billing->auto_disable_date = $expiry->toDateString();
                app(PrepaidExpiryGuard::class)->saveAfterAdvanceWalletAllocation($billing, $transaction);
            } else {
                $billing->save();
            }

            $summaryDate = now()->startOfMonth()->toDateString();
            $summary = PaymentSummary::query()
                ->where('customer_payment_unique_id', $customer->customer_unique_id)
                ->whereDate('summary_date', $summaryDate)
                ->lockForUpdate()->first();
            if (! $summary) {
                $summary = PaymentSummary::create([
                    'customer_payment_unique_id' => $customer->customer_unique_id,
                    'summary_date' => $summaryDate,
                    'monthly_rent' => $billing->monthly_rent,
                    'additional_charge' => $billing->additional_charge,
                    'vat' => $billing->vat,
                    'previous_due' => $billing->previous_due,
                    'advance' => 0,
                    'discount' => $billing->discount,
                ]);
            }
            $summary->advance = round((float) $summary->advance + $amount, 2);
            $summary->save();

            return $transaction;
        }, attempts: 3);
    }

    /** Apply available wallet credit to a newly generated bill. Safe to retry for the same customer/month. */
    public function allocateForBill(CustomersInfo $customer, float $billAmount, string $billMonth): float
    {
        $billAmount = round(max(0, $billAmount), 2);
        if ($billAmount <= 0) return 0.0;

        return DB::transaction(function () use ($customer, $billAmount, $billMonth): float {
            $key = substr(hash('sha256', 'wallet-auto-'.$customer->id.'-'.$billMonth), 0, 36);
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'allocation'
                    || (string) $existing->bill_month !== $billMonth) {
                    throw ValidationException::withMessages(['advance' => 'A different wallet allocation already exists for this bill month.']);
                }
                return (float) $existing->amount;
            }

            $billing = BillingInfo::query()
                ->where('customer_bill_unique_id', $customer->customer_unique_id)
                ->lockForUpdate()->firstOrFail();
            $wallet = $this->lockedWallet($customer);
            $existing = CustomerAdvanceTransaction::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->customer_id !== (int) $customer->id
                    || $existing->entry_type !== 'allocation'
                    || (string) $existing->bill_month !== $billMonth) {
                    throw ValidationException::withMessages(['advance' => 'A different wallet allocation already exists for this bill month.']);
                }
                return (float) $existing->amount;
            }
            $amount = min(round((float) $wallet->balance, 2), $billAmount);
            $amount = round($amount, 2);
            if ($amount <= 0) return 0.0;

            $wallet->balance = round((float) $wallet->balance - $amount, 2);
            $wallet->save();

            $allocation = CustomerAdvanceTransaction::create([
                'wallet_id' => $wallet->id,
                'customer_id' => $customer->id,
                'customer_unique_id' => $customer->customer_unique_id,
                'entry_type' => 'allocation',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_after' => $wallet->balance,
                'bill_month' => $billMonth,
                'idempotency_key' => $key,
                'created_by' => auth()->id(),
                'notes' => 'Automatic advance wallet allocation during monthly bill generation.',
            ]);

            if ($billing->billing_type === 'prepaid') {
                $expiry = PrepaidBillingCycle::expiryAfterPayment(
                    $customer->connection_date ?: $billing->auto_disable_date,
                    $billing->auto_disable_date,
                    $billAmount,
                    $amount,
                    (float) $billing->monthly_rent,
                );
                if ($expiry) {
                    $billing->auto_disable_date = $expiry->toDateString();
                    app(PrepaidExpiryGuard::class)->saveAfterAdvanceWalletAllocation($billing, $allocation);
                }
            }

            return $amount;
        }, attempts: 3);
    }

    public function balanceFor(CustomersInfo $customer): float
    {
        return (float) (CustomerAdvanceWallet::query()->where('customer_id', $customer->id)->value('balance') ?? 0);
    }

    private function lockedWallet(CustomersInfo $customer): CustomerAdvanceWallet
    {
        $now = now();
        DB::table('customer_advance_wallets')->insertOrIgnore([
            'customer_id' => $customer->id,
            'customer_unique_id' => $customer->customer_unique_id,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CustomerAdvanceWallet::query()
            ->where('customer_id', $customer->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
