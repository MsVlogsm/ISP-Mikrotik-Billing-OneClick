<?php

namespace App\Ai\Tools;

use App\Models\CollectionSummary;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RevenueSummary implements Tool
{
    public function __construct(private readonly User $user) {}

    public function name(): string
    {
        return 'revenue_summary';
    }

    public function description(): Stringable|string
    {
        return 'Read today\'s recorded customer collections in BDT. Requires payment-collection-report or amount-collection-report permission.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->can('payment-collection-report') && ! $this->user->can('amount-collection-report')) {
            return json_encode(['ok'=>false,'error'=>'forbidden','message'=>'আপনার কালেকশন রিপোর্ট দেখার অনুমতি নেই।'], JSON_UNESCAPED_UNICODE);
        }

        $today = now()->toDateString();
        $query = CollectionSummary::query()->whereDate('collection_date', $today);

        return json_encode([
            'ok' => true,
            'metric' => 'today_collection',
            'date' => $today,
            'timezone' => config('app.timezone'),
            'currency' => 'BDT',
            'total' => round((float) (clone $query)->sum('collection_amount'), 2),
            'transactions' => (int) (clone $query)->count(),
            'source' => 'collection_summaries.collection_date/collection_amount',
            'read_only' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
