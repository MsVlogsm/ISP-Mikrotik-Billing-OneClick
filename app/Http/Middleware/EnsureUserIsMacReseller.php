<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class EnsureUserIsMacReseller {
    public function handle(Request $request, Closure $next): Response {
        if (! $request->user()) return redirect()->route('login');
        $reseller = $request->user()->reseller;
        abort_unless($request->user()->hasRole('Reseller') && $reseller, 403, 'MAC Reseller account required.');
        abort_unless($reseller->isActive(), 403, 'Your reseller account is suspended.');
        abort_unless($reseller->isMacPanel(), 403, 'This account is not a MAC Reseller.');
        return $next($request);
    }
}
