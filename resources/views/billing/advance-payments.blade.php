<x-app-layout>
    <x-slot name="header">Customer Advance Payments</x-slot>

    @php
        $reverseKey = (string) \Illuminate\Support\Str::uuid();
        $currencyLabel = siteUrlSettings('site_currency') ?: '৳';
        $money = fn ($value) => number_format((float) $value, 2).' '.$currencyLabel;
        $depositKey = (string) \Illuminate\Support\Str::uuid();
        $allocationKey = (string) \Illuminate\Support\Str::uuid();
    @endphp

    <div class="container-fluid px-3 px-xl-4 pb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h2 class="h4 mb-1">Advance Payment Management</h2>
                <p class="text-muted small mb-0">Customer credit wallet, deposits and bill allocations.</p>
            </div>
            <a href="{{ route('billing-finance') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Billing &amp; Finance</a>
        </div>

        @if(session('advance_success'))
            <div class="alert alert-success">{{ session('advance_success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger">
                <strong>Could not complete the advance action.</strong>
                <ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Total Available Advance</div><div class="fs-4 fw-bold text-primary mt-1">{{ $money($advanceBalanceTotal) }}</div></div></div></div>
            <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Customers with Credit</div><div class="fs-4 fw-bold mt-1">{{ number_format($advanceCustomersCount) }}</div></div></div></div>
            <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Deposited This Month</div><div class="fs-4 fw-bold text-success mt-1">{{ $money($advanceDepositsMonth) }}</div></div></div></div>
            <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Allocated This Month</div><div class="fs-4 fw-bold mt-1">{{ $money($advanceUtilizedMonth) }}</div></div></div></div>
        </div>

        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div><h3 class="h6 mb-1">Customer selection</h3><p class="small text-muted mb-0">Search by customer ID, name or mobile number.</p></div>
                    @if($selectedCustomer)
                        <span class="badge text-bg-light border">{{ $selectedCustomer->customer_unique_id }}</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('billing-finance.advance-payments') }}" class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small">Search customer</label>
                        <input type="text" class="form-control" name="q" value="{{ request('q') }}" placeholder="ID / Name / Mobile">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">Select customer</label>
                        <select class="form-select" name="customer_id">
                            <option value="">All customers / select below</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected((int) request('customer_id') === (int) $customer->id)>
                                    {{ $customer->customer_unique_id }} · {{ $customer->customer_name }} · Due {{ number_format((float) ($customer->billing?->due_amount ?? 0), 2) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-grid"><button class="btn btn-primary">Load customer</button></div>
                </form>
                @if($selectedCustomer)
                    <div class="row g-3 mt-2">
                        <div class="col-md-4"><div class="rounded-3 bg-light p-3"><div class="small text-muted">Customer</div><div class="fw-semibold">{{ $selectedCustomer->customer_name }}</div><div class="small text-muted">{{ $selectedCustomer->customer_unique_id }}</div></div></div>
                        <div class="col-md-4"><div class="rounded-3 bg-light p-3"><div class="small text-muted">Current Bill Due</div><div class="fw-semibold text-danger">{{ $money($selectedCustomer->billing?->due_amount ?? 0) }}</div></div></div>
                        <div class="col-md-4"><div class="rounded-3 bg-light p-3"><div class="small text-muted">Available Advance Wallet</div><div class="fw-semibold text-success">{{ $money($selectedBalance) }}</div></div></div>
                    </div>
                @endif
            </div>
        </section>

        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3"><h3 class="h6 mb-0">Add Advance Deposit</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('billing-finance.advance-payments.deposit') }}" class="row g-3">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ $depositKey }}">
                            <div class="col-12">
                                <label class="form-label small">Customer</label>
                                <select name="customer_id" class="form-select" required>
                                    <option value="">Choose customer</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}" @selected($selectedCustomer && $selectedCustomer->id === $customer->id)>
                                            {{ $customer->customer_unique_id }} · {{ $customer->customer_name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Amount received</label>
                                <input name="amount" type="number" min="0.01" max="999999999" step="0.01" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Payment method</label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="cash">Cash</option><option value="bank">Bank</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="rocket">Rocket</option><option value="sslcommerz">SSLCommerz</option><option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Receipt / Transaction ID</label>
                                <input name="transaction_id" type="text" maxlength="120" class="form-control" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Received date</label>
                                <input name="payment_date" type="datetime-local" value="{{ now()->format('Y-m-d\\TH:i') }}" max="{{ now()->format('Y-m-d\\TH:i') }}" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Notes</label>
                                <input name="notes" type="text" maxlength="2000" class="form-control" placeholder="Optional deposit note">
                            </div>
                            <div class="col-12 d-grid"><button class="btn btn-success"><i class="bi bi-wallet2 me-1"></i>Record Advance Deposit</button></div>
                        </form>
                        <p class="small text-muted mt-3 mb-0">Deposit is recorded as cash received and credited to the wallet. It does not reduce the current bill until allocated.</p>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3"><h3 class="h6 mb-0">Allocate Advance to Current Bill</h3></div>
                    <div class="card-body">
                        @if($selectedCustomer)
                            <form method="POST" action="{{ route('billing-finance.advance-payments.allocate') }}" class="row g-3">
                                @csrf
                                <input type="hidden" name="idempotency_key" value="{{ $allocationKey }}">
                                <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                                <div class="col-12">
                                    <label class="form-label small">Customer</label>
                                    <input class="form-control" value="{{ $selectedCustomer->customer_unique_id }} · {{ $selectedCustomer->customer_name }}" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Current due</label>
                                    <input class="form-control" value="{{ $money($selectedCustomer->billing?->due_amount ?? 0) }}" disabled>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Available advance</label>
                                    <input class="form-control" value="{{ $money($selectedBalance) }}" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Amount to allocate</label>
                                    <input name="amount" type="number" min="0.01" max="{{ min((float) ($selectedBalance ?? 0), (float) ($selectedCustomer->billing?->due_amount ?? 0)) }}" step="0.01" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Notes</label>
                                    <input name="notes" type="text" maxlength="2000" class="form-control" placeholder="Optional allocation note">
                                </div>
                                <div class="col-12 d-grid">
                                    <button class="btn btn-primary" @disabled((float) ($selectedBalance ?? 0) <= 0 || (float) ($selectedCustomer->billing?->due_amount ?? 0) <= 0)>
                                        <i class="bi bi-arrow-down-circle me-1"></i>Allocate to Current Bill
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="text-center text-muted py-5"><i class="bi bi-person-search fs-2 d-block mb-2"></i>Select a customer above to allocate advance against their current bill.</div>
                        @endif
                        <p class="small text-muted mt-3 mb-0">Unused wallet balance is automatically considered during monthly bill generation. Prepaid expiry is extended only against a recorded wallet allocation.</p>
                    </div>
                </section>
            </div>
        </div>

        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><h3 class="h6 mb-1">Advance Wallet Ledger</h3><p class="small text-muted mb-0">Append-only deposits, allocations and deposit reversals with balance snapshots.</p></div>
                <span class="badge text-bg-light border">{{ $transactions->total() }} entries</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Customer</th><th>Transaction</th><th>Method / Bill</th><th class="text-end">Amount</th><th class="text-end">Balance After</th><th>Notes</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td class="text-nowrap">{{ $transaction->created_at?->format('d M Y H:i') }}</td>
                            <td><strong>{{ $transaction->customer?->customer_name ?: $transaction->customer_unique_id }}</strong><div class="small text-muted">{{ $transaction->customer_unique_id }}</div></td>
                            <td><span class="badge {{ $transaction->direction === 'credit' ? 'text-bg-success' : 'text-bg-primary' }}">{{ ucfirst(str_replace('_', ' ', $transaction->entry_type)) }}</span></td>
                            <td>{{ ucfirst($transaction->payment_method ?: 'Wallet') }}@if($transaction->bill_month)<div class="small text-muted">Bill {{ $transaction->bill_month }}</div>@endif</td>
                            <td class="text-end fw-semibold {{ $transaction->direction === 'credit' ? 'text-success' : 'text-primary' }}">{{ $transaction->direction === 'credit' ? '+' : '−' }}{{ $money($transaction->amount) }}</td>
                            <td class="text-end">{{ $money($transaction->balance_after) }}</td>
                            <td class="small text-muted">{{ $transaction->notes ?: ($transaction->transaction_id ?: '—') }}</td>
                            <td>
                                @if($selectedCustomer && $transaction->entry_type === 'deposit' && (float) $selectedBalance > 0 && (float) $transaction->amount > 0)
                                    <form method="POST" action="{{ route('billing-finance.advance-payments.reverse') }}" class="d-flex flex-wrap gap-1">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="{{ $reverseKey }}">
                                        <input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">
                                        <input type="hidden" name="deposit_id" value="{{ $transaction->id }}">
                                        <input name="amount" type="number" min="0.01" max="{{ min((float) $transaction->amount, (float) $selectedBalance) }}" step="0.01" class="form-control form-control-sm" style="max-width:110px" placeholder="Amount" required>
                                        <input name="reason" type="text" minlength="5" maxlength="1000" class="form-control form-control-sm" style="max-width:150px" placeholder="Reason" required>
                                        <button class="btn btn-outline-danger btn-sm" onclick="return confirm('Reverse this unused wallet deposit amount? The original deposit will remain in the ledger.')">Reverse</button>
                                    </form>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-5">No advance wallet transactions recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">{{ $transactions->links() }}</div>
        </section>
    </div>
</x-app-layout>
