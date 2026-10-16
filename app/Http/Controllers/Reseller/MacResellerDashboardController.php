<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\CustomersInfo;

class MacResellerDashboardController extends Controller
{
    public function index()
    {
        $reseller = auth()->user()->reseller;
        abort_unless($reseller && $reseller->isMacPanel(), 403);

        $base = CustomersInfo::query()->where('reseller_id', $reseller->id);
        $totalCustomers = (clone $base)->count();
        $activeCustomers = (clone $base)->where('status', 'active')->count();
        $disabledCustomers = (clone $base)->where('status', 'disable')->count();
        $pendingCustomers = (clone $base)->where('status', 'pending')->count();
        $recentCustomers = (clone $base)->latest('created_at')->limit(8)->get();

        return view('mac-reseller.dashboard', compact(
            'reseller', 'totalCustomers', 'activeCustomers', 'disabledCustomers', 'pendingCustomers', 'recentCustomers'
        ));
    }
}
