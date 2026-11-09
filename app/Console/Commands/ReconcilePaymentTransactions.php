<?php

namespace App\Console\Commands;

use App\Services\PaymentGatewayReconciliationService;
use Illuminate\Console\Command;

class ReconcilePaymentTransactions extends Command
{
    protected $signature = 'payment:reconcile-transactions {--limit=25 : Maximum provider transactions to query per run}';
    protected $description = 'Poll pending bKash, Nagad and SSLCommerz transactions and apply only verified payments';

    public function handle(PaymentGatewayReconciliationService $service): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $result = $service->reconcile($limit);

        $this->info((string) ($result['message'] ?? 'Payment reconciliation completed.'));
        $this->line(sprintf(
            'Checked: %d | Paid: %d | Pending: %d | Failed: %d | Review: %d | Provider unavailable: %d',
            $result['checked'] ?? 0,
            $result['paid'] ?? 0,
            $result['pending'] ?? 0,
            $result['failed'] ?? 0,
            $result['needs_review'] ?? 0,
            $result['unavailable'] ?? 0
        ));

        return self::SUCCESS;
    }
}
