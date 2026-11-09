<?php

namespace App\Http\Controllers;

use App\Models\BillingInfo;
use App\Models\CollectionSummary;
use App\Models\CustomerAdvanceWallet;
use App\Models\CustomerAdvanceTransaction;
use App\Models\CustomersInfo;
use App\Models\HotspotSale;
use App\Models\IspExpense;
use App\Models\ResellerCommission;
use Illuminate\Support\Facades\DB;

class BillingFinanceDashboardController extends Controller
{
    public function index()
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $yearStart = $now->copy()->startOfYear();
        $trendStart = $now->copy()->subMonths(5)->startOfMonth();

        // Date-only tables receive date strings; timestamp columns use full-day boundaries.
        $todayDate = $todayStart->toDateString();
        $monthStartDate = $monthStart->toDateString();
        $monthEndDate = $monthEnd->toDateString();
        $yearStartDate = $yearStart->toDateString();
        $trendStartDate = $trendStart->toDateString();
        $driver = DB::connection()->getDriverName();
        $currency = siteUrlSettings('site_currency') ?: '৳';

        // Customer advance deposits are cash received but remain customer credit,
        // not earned/current-bill revenue. Their totals are shown in separate wallet KPIs.
        $collectionToday = (float) CollectionSummary::where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$todayStart, $todayEnd])->sum('collection_amount');
        $hotspotToday = (float) HotspotSale::whereDate('sale_date', $todayDate)->sum('amount');

        $collectionMonth = (float) CollectionSummary::where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$monthStart, $monthEnd])->sum('collection_amount');
        $hotspotMonth = (float) HotspotSale::whereBetween('sale_date', [$monthStartDate, $monthEndDate])->sum('amount');
        $collectionYear = (float) CollectionSummary::where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$yearStart, $todayEnd])->sum('collection_amount');
        $hotspotYear = (float) HotspotSale::whereBetween('sale_date', [$yearStartDate, $todayDate])->sum('amount');

        $expenseMonth = (float) IspExpense::whereBetween('expense_date', [$monthStartDate, $monthEndDate])->sum('amount');
        $commissionMonth = (float) ResellerCommission::whereBetween('created_at', [$monthStart, $monthEnd])->sum('amount');

        $monthlyRent = (float) BillingInfo::join('customers_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->whereNull('customers_infos.deleted_at')
            ->where('customers_infos.status', 'active')
            ->sum('billing_infos.monthly_rent');

        $advanceBalanceTotal = (float) CustomerAdvanceWallet::sum('balance');
        $advanceWalletCustomers = CustomerAdvanceWallet::where('balance', '>', 0)->count();
        $advanceDepositsMonth = (float) CustomerAdvanceTransaction::where('entry_type', 'deposit')
            ->whereBetween('created_at', [$monthStart, $monthEnd])->sum('amount');
        $advanceUtilizedMonth = (float) CustomerAdvanceTransaction::where('entry_type', 'allocation')
            ->where('bill_month', $now->format('Y-m'))->sum('amount');

        $outstandingDue = (float) BillingInfo::join('customers_infos', 'billing_infos.customer_bill_unique_id', '=', 'customers_infos.customer_unique_id')
            ->whereNull('customers_infos.deleted_at')
            ->where('billing_infos.due_amount', '>', 0)
            ->sum('billing_infos.due_amount');

        $dueCustomersCount = CustomersInfo::whereIn('status', ['active', 'pending', 'disable', 'temporary_disable'])
            ->whereHas('billing', fn ($query) => $query->where('due_amount', '>', 0))
            ->count();

        $activeCustomersCount = CustomersInfo::where('status', 'active')->count();
        $monthRevenue = $collectionMonth + $hotspotMonth;
        $monthCosts = $expenseMonth + $commissionMonth;
        $monthNet = $monthRevenue - $monthCosts;
        $netMargin = $monthRevenue > 0 ? ($monthNet / $monthRevenue) * 100 : 0;
        $yearRevenue = $collectionYear + $hotspotYear;

        $collectionMonthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', collection_date)"
            : "DATE_FORMAT(collection_date, '%Y-%m')";
        $saleMonthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', sale_date)"
            : "DATE_FORMAT(sale_date, '%Y-%m')";
        $expenseMonthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', expense_date)"
            : "DATE_FORMAT(expense_date, '%Y-%m')";
        $commissionMonthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $collectionsByMonth = CollectionSummary::where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$trendStart, $monthEnd])
            ->selectRaw($collectionMonthExpression.' as month_key, SUM(collection_amount) as total')
            ->groupBy('month_key')->pluck('total', 'month_key')->toArray();

        $hotspotByMonth = HotspotSale::whereBetween('sale_date', [$trendStartDate, $monthEndDate])
            ->selectRaw($saleMonthExpression.' as month_key, SUM(amount) as total')
            ->groupBy('month_key')->pluck('total', 'month_key')->toArray();

        $expensesByMonth = IspExpense::whereBetween('expense_date', [$trendStartDate, $monthEndDate])
            ->selectRaw($expenseMonthExpression.' as month_key, SUM(amount) as total')
            ->groupBy('month_key')->pluck('total', 'month_key')->toArray();

        $commissionsByMonth = ResellerCommission::whereBetween('created_at', [$trendStart, $monthEnd])
            ->selectRaw($commissionMonthExpression.' as month_key, SUM(amount) as total')
            ->groupBy('month_key')->pluck('total', 'month_key')->toArray();

        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i);
            $key = $date->format('Y-m');
            $revenue = (float) ($collectionsByMonth[$key] ?? 0) + (float) ($hotspotByMonth[$key] ?? 0);
            $costs = (float) ($expensesByMonth[$key] ?? 0) + (float) ($commissionsByMonth[$key] ?? 0);
            $trend[] = [
                'label' => $date->format('M'),
                'revenue' => $revenue,
                'expenses' => $costs,
                'net' => $revenue - $costs,
            ];
        }
        $trendMax = max(1, ...array_map(fn ($row) => max($row['revenue'], $row['expenses']), $trend));

        $paymentMethods = CollectionSummary::where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$monthStart, $monthEnd])
            ->selectRaw("COALESCE(NULLIF(payment_method, ''), 'Other') as method, SUM(collection_amount) as total")
            ->groupBy('method')->orderByDesc('total')->limit(5)->get();

        $recentCollections = CollectionSummary::with('customer')
            ->where(function ($query) {
                $query->whereNull('payment_type')->orWhere('payment_type', '<>', 'advance');
            })->whereBetween('collection_date', [$monthStart, $monthEnd])
            ->latest('collection_date')->latest('id')->limit(7)->get();

        $topDueCustomers = BillingInfo::with('customer')
            ->where('due_amount', '>', 0)
            ->whereHas('customer', fn ($query) => $query->whereNotIn('status', ['inactive', 'free']))
            ->orderByDesc('due_amount')->limit(7)->get();

        $recentExpenses = IspExpense::with('addedBy')
            ->whereBetween('expense_date', [$monthStartDate, $monthEndDate])
            ->latest('expense_date')->latest('id')->limit(5)->get();

        return view('billing.finance-hub', compact(
            'now', 'currency', 'collectionToday', 'hotspotToday', 'collectionMonth', 'hotspotMonth',
            'collectionYear', 'hotspotYear', 'expenseMonth', 'commissionMonth', 'monthlyRent',
            'outstandingDue', 'dueCustomersCount', 'activeCustomersCount', 'advanceBalanceTotal',
            'advanceWalletCustomers', 'advanceDepositsMonth', 'advanceUtilizedMonth', 'monthRevenue',
            'monthCosts', 'monthNet', 'netMargin', 'yearRevenue', 'trend', 'trendMax',
            'paymentMethods', 'recentCollections', 'topDueCustomers', 'recentExpenses'
        ));
    }
}
