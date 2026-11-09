<?php

namespace Tests\Feature;

use App\Models\BillingInfo;
use App\Models\CustomerAdvanceTransaction;
use App\Models\CustomerAdvanceWallet;
use App\Models\CustomersInfo;
use App\Models\User;
use App\Http\Controllers\ScheduledTasksController;
use App\Services\CustomerAdvanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAdvanceWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00:00', 'Asia/Dhaka'));
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->actingAs($admin);
    }

    public function test_advance_deposit_is_wallet_credit_and_is_not_counted_as_monthly_bill_payment(): void
    {
        [$customer, $billing] = $this->makeCustomer('ADV-WALLET-001');
        $service = app(CustomerAdvanceService::class);
        $payload = [
            'amount' => 1500,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'notes' => 'Advance for coming months',
        ];

        $first = $service->deposit($customer, $payload);
        $retry = $service->deposit($customer, $payload);

        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('collection_summaries', 1);
        $this->assertDatabaseHas('collection_summaries', [
            'id' => $first->collection_summary_id,
            'payment_type' => 'advance',
            'collection_amount' => 1500,
            'payment_status' => 'paid',
        ]);
        $this->assertSame(1500.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(500.00, (float) $billing->fresh()->due_amount);

        $summary = \App\Models\PaymentSummary::create([
            'customer_payment_unique_id' => $customer->customer_unique_id,
            'summary_date' => now()->startOfMonth(),
            'monthly_rent' => 500,
            'additional_charge' => 0,
            'vat' => 0,
            'previous_due' => 0,
            'advance' => 0,
            'discount' => 0,
        ]);

        $this->assertSame(0.0, $summary->fresh()->paid_amount);
        $this->assertSame(500.0, $summary->fresh()->due_amount);
    }

    public function test_manual_allocation_debits_wallet_and_reduces_current_due_once(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-002');
        $service = app(CustomerAdvanceService::class);
        $service->deposit($customer, [
            'amount' => 1000,
            'payment_method' => 'bank',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $allocationKey = (string) Str::uuid();
        $first = $service->allocateToCurrentBill($customer, 300, $allocationKey, 'Apply part to November bill');
        $retry = $service->allocateToCurrentBill($customer, 300, $allocationKey, 'Apply part to November bill');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(700.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(200.00, (float) $customer->billing()->first()->due_amount);
        $this->assertDatabaseCount('customer_advance_transactions', 2);
        $this->assertDatabaseHas('payment_summaries', [
            'customer_payment_unique_id' => $customer->customer_unique_id,
            'advance' => 300,
        ]);
    }

    public function test_unused_deposit_can_be_partially_reversed_once_without_mutating_original_ledger_entry(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-REVERSAL');
        $service = app(CustomerAdvanceService::class);
        $deposit = $service->deposit($customer, [
            'amount' => 1000, 'payment_method' => 'cash', 'payment_date' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $key = (string) Str::uuid();
        $first = $service->reverseDeposit($customer, $deposit->id, 300, $key, 'Customer deposit entered in error');
        $retry = $service->reverseDeposit($customer, $deposit->id, 300, $key, 'Customer deposit entered in error');
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(700.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame('deposit', $deposit->fresh()->entry_type);
        $this->assertSame(1000.00, (float) $deposit->fresh()->amount);
        $this->assertDatabaseHas('customer_advance_transactions', ['id' => $first->id, 'entry_type' => 'reversal', 'related_transaction_id' => $deposit->id, 'amount' => 300]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->reverseDeposit($customer, $deposit->id, 701, (string) Str::uuid(), 'Exceeds remaining credit');
    }

    public function test_deposit_reversal_cannot_refund_credit_already_allocated_to_a_bill(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-REVERSAL-ALLOCATED');
        $service = app(CustomerAdvanceService::class);
        $deposit = $service->deposit($customer, [
            'amount' => 1000, 'payment_method' => 'cash', 'payment_date' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $service->allocateToCurrentBill($customer, 400, (string) Str::uuid());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->reverseDeposit($customer, $deposit->id, 700, (string) Str::uuid(), 'Attempt to reverse allocated funds');
    }

    public function test_monthly_auto_allocation_is_idempotent_and_cannot_overdraw_wallet(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-003');
        $service = app(CustomerAdvanceService::class);
        $service->deposit($customer, [
            'amount' => 450,
            'payment_method' => 'bkash',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $first = $service->allocateForBill($customer, 700, '2026-12');
        $retry = $service->allocateForBill($customer, 700, '2026-12');

        $this->assertSame(450.00, $first);
        $this->assertSame(450.00, $retry);
        $this->assertSame(0.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseCount('customer_advance_transactions', 2);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_unique_id' => $customer->customer_unique_id,
            'entry_type' => 'allocation',
            'bill_month' => '2026-12',
            'amount' => 450,
        ]);
    }

    public function test_monthly_bill_generation_uses_wallet_credit_once_and_does_not_debit_existing_summary_again(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-SCHEDULE');
        $service = app(CustomerAdvanceService::class);
        $service->deposit($customer, [
            'amount' => 300,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        app(ScheduledTasksController::class)->createMonthlyBill();

        $this->assertSame(700.00, (float) $customer->billing()->first()->due_amount);
        $this->assertSame(0.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseHas('payment_summaries', [
            'customer_payment_unique_id' => $customer->customer_unique_id,
            'summary_date' => '2026-12-01 00:00:00',
            'advance' => 300,
            'previous_due' => 500,
        ]);

        $service->deposit($customer, [
            'amount' => 100,
            'payment_method' => 'bank',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        app(ScheduledTasksController::class)->createMonthlyBill();

        $this->assertSame(100.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertDatabaseCount('payment_summaries', 1);
    }

    public function test_prepaid_expiry_extends_only_when_wallet_credit_is_allocated(): void
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => 'ADV-WALLET-PREPAID',
            'customer_name' => 'Advance Wallet Prepaid Test',
            'connection_date' => '2026-10-10',
            'status' => 'active',
            'disable_count' => 0,
        ]);
        BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'prepaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
            'auto_disable' => true,
            'auto_disable_date' => '2026-11-10',
            'auto_disable_month' => 0,
        ]);

        $service = app(CustomerAdvanceService::class);
        $service->deposit($customer, [
            'amount' => 600,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $service->allocateToCurrentBill($customer, 500, (string) Str::uuid());

        $this->assertSame(0.00, (float) $customer->billing()->first()->due_amount);
        $this->assertSame('2026-12-10', Carbon::parse($customer->billing()->first()->auto_disable_date)->toDateString());
        $this->assertSame(100.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
    }

    public function test_billing_finance_dashboard_displays_advance_wallet_metrics(): void
    {
        $response = $this->get(route('billing-finance'));

        $response->assertOk();
        $response->assertSee('Customer Advance Balance');
        $response->assertSee('Advance Deposits');
        $response->assertViewHas('collectionMonth', 0.0);
    }

    public function test_advance_deposit_is_reported_as_wallet_credit_not_earned_collection_revenue(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-REVENUE');
        app(CustomerAdvanceService::class)->deposit($customer, [
            'amount' => 650,
            'payment_method' => 'cash',
            'payment_date' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response = $this->get(route('billing-finance'));

        $response->assertOk();
        $response->assertViewHas('collectionMonth', 0.0);
        $response->assertViewHas('advanceDepositsMonth', 650.0);
        $response->assertViewHas('advanceBalanceTotal', 650.0);
    }

    public function test_deposit_and_allocation_http_actions_are_authorized_and_persisted(): void
    {
        [$customer] = $this->makeCustomer('ADV-WALLET-HTTP');

        $depositResponse = $this->post(route('billing-finance.advance-payments.deposit'), [
            'customer_id' => $customer->id,
            'amount' => 800,
            'payment_method' => 'cash',
            'payment_date' => now()->toDateTimeString(),
            'notes' => 'HTTP deposit test',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $depositResponse->assertRedirect(route('billing-finance.advance-payments', ['customer_id' => $customer->id]));
        $this->assertSame(800.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));

        $allocationResponse = $this->post(route('billing-finance.advance-payments.allocate'), [
            'customer_id' => $customer->id,
            'amount' => 250,
            'notes' => 'HTTP allocation test',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $allocationResponse->assertRedirect(route('billing-finance.advance-payments', ['customer_id' => $customer->id]));
        $this->assertSame(550.00, (float) CustomerAdvanceWallet::where('customer_id', $customer->id)->value('balance'));
        $this->assertSame(250.00, (float) $customer->billing()->first()->due_amount);
        $this->assertDatabaseCount('customer_advance_transactions', 2);
    }

    public function test_advance_wallet_page_is_available_to_authorized_finance_user(): void
    {
        $response = $this->get(route('billing-finance.advance-payments'));

        $response->assertOk();
        $response->assertSee('Advance Payment Management');
        $response->assertSee('Advance Wallet Ledger');
    }

    private function makeCustomer(string $customerCode): array
    {
        $customer = CustomersInfo::create([
            'customer_unique_id' => $customerCode,
            'customer_name' => 'Advance Wallet Test',
            'connection_date' => '2026-10-10',
            'status' => 'active',
            'disable_count' => 0,
        ]);

        $billing = BillingInfo::create([
            'customer_bill_unique_id' => $customer->customer_unique_id,
            'billing_type' => 'postpaid',
            'monthly_rent' => 500,
            'total_amount' => 500,
            'due_amount' => 500,
            'paid_amount' => 0,
            'auto_disable' => true,
            'auto_disable_date' => '2026-11-10',
            'auto_disable_month' => 0,
        ]);

        return [$customer, $billing];
    }
}
