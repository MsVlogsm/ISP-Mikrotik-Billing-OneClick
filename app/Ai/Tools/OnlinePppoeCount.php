<?php

namespace App\Ai\Tools;

use App\Http\Controllers\MikrotikController;
use App\Models\RouterList;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class OnlinePppoeCount implements Tool
{
    public function __construct(
        private readonly User $user,
        private readonly ?MikrotikController $mikrotik = null,
    ) {}

    public function name(): string
    {
        return 'online_pppoe_count';
    }

    public function description(): Stringable|string
    {
        return 'Count active PPPoE sessions by reading /ppp/active on every connected MikroTik router. Report partial/unavailable routers separately; never treat a failed read as zero.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->can('network-inventory')) {
            return json_encode(['ok'=>false,'error'=>'forbidden','message'=>'আপনার নেটওয়ার্ক সেশন দেখার অনুমতি নেই।'], JSON_UNESCAPED_UNICODE);
        }

        $controller = $this->mikrotik ?: app(MikrotikController::class);
        $routers = RouterList::query()->where('action', 'connected')->orderBy('router_name')->get();
        $results = [];
        $total = 0;
        $successful = 0;

        foreach ($routers as $router) {
            try {
                $rows = $controller->singleRead(
                    $router->router_name,
                    '/ppp/active/print',
                    '/ppp active print without-paging terse',
                    [],
                    true,
                    true
                );
                $count = is_array($rows) ? count($rows) : 0;
                $total += $count;
                $successful++;
                $results[] = ['router'=>$router->router_name, 'status'=>'ok', 'active_sessions'=>$count];
            } catch (\Throwable $e) {
                \Log::warning('AI Assistant PPPoE read failed', ['router'=>$router->router_name, 'error'=>$e->getMessage()]);
                $results[] = ['router'=>$router->router_name, 'status'=>'unavailable'];
            }
        }

        return json_encode([
            'ok' => $successful > 0 && count($results) === $successful,
            'metric' => 'live_active_pppoe_sessions',
            'total' => $successful > 0 ? $total : null,
            'routers_connected' => $routers->count(),
            'routers_read_successfully' => $successful,
            'routers_unavailable' => count($results) - $successful,
            'by_router' => $results,
            'checked_at' => now()->toIso8601String(),
            'read_only' => true,
            'note' => $successful === 0 ? 'No connected router returned a successful live session read.' : (count($results) !== $successful ? 'Partial result: one or more connected routers could not be read.' : 'All connected routers returned live session data.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
