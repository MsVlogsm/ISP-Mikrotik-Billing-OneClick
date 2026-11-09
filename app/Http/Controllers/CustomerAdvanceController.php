<?php

namespace App\Http\Controllers;

use App\Models\CustomerAdvanceTransaction;
use App\Models\CustomerAdvanceWallet;
use App\Models\CustomersInfo;
use App\Models\MainSiteData;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerAdvanceController extends Controller
{
    private function authorizeFinance(string $permission = 'payment-collection'): void
    {
        abort_unless(hasAccess(['Super Admin', 'Admin'], [$permission]), 403, 'You do not have permission to manage customer advance balances.');
    }

    private function customerQuery()
    {
        $user = auth()->user();
        $query = CustomersInfo::query()->whereNull('deleted_at');

        if ($user?->hasRole('Reseller')) {
            abort_unless($user->reseller, 403, 'Reseller account is not linked.');
            $query->where('reseller_id', $user->reseller->id);
        }

        return $query;
    }

    private function assertOperatorDepositAllowed(): void
    {
        $user = auth()->user();
        if ($user?->hasAnyRole(['Super Admin', 'Admin'])) return;

        abort_unless(
            (bool) MainSiteData::getValue('allow_operator_add_advance_balance_to_customer_wallet', false),
            403,
            'An administrator must enable operator advance-wallet deposits in iPilot Application Settings.'
        );
    }

    public function index(Request $request)
    {
        $this->authorizeFinance();

        $customerQuery = $this->customerQuery();
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $customerQuery->where(function ($query) use ($term): void {
                $query->where('customer_unique_id', 'like', '%'.$term.'%')
                    ->orWhere('customer_name', 'like', '%'.$term.'%')
                    ->orWhere('mobile', 'like', '%'.$term.'%');
            });
        }

        $customers = (clone $customerQuery)
            ->with('billing')
            ->orderBy('customer_unique_id')
            ->limit(500)
            ->get();

        $selectedCustomer = null;
        if ($request->filled('customer_id')) {
            $selectedCustomer = $this->customerQuery()
                ->with('billing')
                ->whereKey((int) $request->query('customer_id'))
                ->first();
        }

        $customerIds = (clone $this->customerQuery())->select('id');
        $advanceBalanceTotal = (float) CustomerAdvanceWallet::query()
            ->whereIn('customer_id', clone $customerIds)
            ->sum('balance');
        $advanceCustomersCount = CustomerAdvanceWallet::query()
            ->whereIn('customer_id', clone $customerIds)
            ->where('balance', '>', 0)
            ->count();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $advanceDepositsMonth = (float) CustomerAdvanceTransaction::query()
            ->whereIn('customer_id', clone $customerIds)
            ->where('entry_type', 'deposit')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('amount');
        $advanceUtilizedMonth = (float) CustomerAdvanceTransaction::query()
            ->whereIn('customer_id', clone $customerIds)
            ->where('entry_type', 'allocation')
            ->where('bill_month', now()->format('Y-m'))
            ->sum('amount');

        $transactions = CustomerAdvanceTransaction::query()
            ->with(['customer', 'collection'])
            ->whereIn('customer_id', clone $customerIds)
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', (int) $request->query('customer_id')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $selectedBalance = $selectedCustomer
            ? app(CustomerAdvanceService::class)->balanceFor($selectedCustomer)
            : null;

        return view('billing.advance-payments', compact(
            'customers',
            'selectedCustomer',
            'selectedBalance',
            'transactions',
            'advanceBalanceTotal',
            'advanceCustomersCount',
            'advanceDepositsMonth',
            'advanceUtilizedMonth'
        ));
    }

    public function deposit(Request $request, CustomerAdvanceService $advanceService): RedirectResponse
    {
        $this->authorizeFinance('payment-collection');
        $this->assertOperatorDepositAllowed();

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers_infos,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payment_method' => ['required', Rule::in(['cash', 'bank', 'bkash', 'nagad', 'rocket', 'sslcommerz', 'other'])],
            'transaction_id' => ['required_if:payment_method,bkash,nagad,rocket,bank,sslcommerz', 'nullable', 'string', 'max:120'],
            'payment_date' => ['required', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $customer = $this->customerQuery()->whereKey((int) $data['customer_id'])->firstOrFail();
        $advanceService->deposit($customer, $data);

        return redirect()->route('billing-finance.advance-payments', ['customer_id' => $customer->id])
            ->with('advance_success', 'Advance deposit recorded and added to the customer wallet.');
    }

    public function reverse(Request $request, CustomerAdvanceService $advanceService): RedirectResponse
    {
        $this->authorizeFinance('payment-collection-edit');
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers_infos,id'],
            'deposit_id' => ['required', 'integer', 'exists:customer_advance_transactions,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $customer = $this->customerQuery()->whereKey((int) $data['customer_id'])->firstOrFail();
        $advanceService->reverseDeposit($customer, (int) $data['deposit_id'], (float) $data['amount'], $data['idempotency_key'], $data['reason']);
        return redirect()->route('billing-finance.advance-payments', ['customer_id' => $customer->id])
            ->with('advance_success', 'Unused advance deposit reversed from the customer wallet. The original ledger entry was preserved.');
    }

    public function allocate(Request $request, CustomerAdvanceService $advanceService): RedirectResponse
    {
        $this->authorizeFinance('payment-collection-edit');

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers_infos,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $customer = $this->customerQuery()->with('billing')->whereKey((int) $data['customer_id'])->firstOrFail();
        $advanceService->allocateToCurrentBill(
            $customer,
            (float) $data['amount'],
            $data['idempotency_key'],
            $data['notes'] ?? null
        );

        return redirect()->route('billing-finance.advance-payments', ['customer_id' => $customer->id])
            ->with('advance_success', 'Advance balance allocated to the current bill.');
    }
}
