<?php

namespace App\Http\Controllers;

use App\Ai\Agents\XLinkAssistant;
use App\Services\AiEngineerDiagnosticService;
use Laravel\Ai\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiEngineerController extends Controller
{
    public function index(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $customerId = trim((string) $request->query('customer', ''));
        $diagnosis = $customerId !== '' ? $engineer->diagnoseCustomer($customerId) : null;
        $overview = $engineer->overview();
        $dailySummary = $engineer->dailySummary();
        $unmappedOnus = $engineer->unmappedOnus(12);
        $upstreamCorrelation = $engineer->upstreamCorrelation(100);
        $matchingCandidates = $engineer->matchingCandidates(100);
        $billingAudit = $engineer->billingAudit(25);
        $onuMappingAudit = $engineer->onuMappingAudit(30);

        return view('xlink.ai-engineer', compact('customerId', 'diagnosis', 'overview', 'dailySummary', 'unmappedOnus', 'upstreamCorrelation', 'matchingCandidates', 'billingAudit', 'onuMappingAudit'));
    }

    public function diagnose(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $data = $request->validate(['customer_id' => ['required','string','max:100']]);

        return response()->json($engineer->diagnoseCustomer($data['customer_id']));
    }

    public function chat(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $data = $request->validate([
            'question' => ['required','string','max:4000'],
            'customer_id' => ['nullable','string','max:100'],
            'history' => ['nullable','array','max:20'],
            'conversation_id' => ['nullable','string','max:36'],
        ]);

        $provider = strtolower((string) config('services.ai.provider', 'gemini'));
        if (! in_array($provider, ['gemini', 'openai'], true)) {
            $provider = 'gemini';
        }

        $apiKey = (string) config("ai.providers.{$provider}.key", '');
        if ($apiKey !== '' && class_exists(\Laravel\Ai\Contracts\Agent::class)) {
            $history = array_slice(array_map(static fn ($message) => [
                'role' => in_array($message['role'] ?? '', ['user', 'assistant'], true) ? $message['role'] : 'user',
                'content' => mb_substr((string) ($message['content'] ?? ''), 0, 3000),
            ], array_filter($data['history'] ?? [], 'is_array')), -10);

            try {
                $agent = (new XLinkAssistant($request->user()))->continueOrStart(
                    $data['conversation_id'] ?? null,
                    $request->user()
                );

                $response = $agent->prompt(
                    mb_substr($data['question'], 0, 4000),
                    provider: $provider,
                    model: filled(config("services.{$provider}.model")) ? (string) config("services.{$provider}.model") : null,
                    timeout: 45,
                );

                return response()->json([
                    'ok' => true,
                    'configured' => true,
                    'provider' => $provider,
                    'message' => trim((string) $response->text),
                    'read_only' => true,
                    'tool_calling' => true,
                    'conversation_id' => $response->conversationId,
                    'tools' => $response->toolCalls->map(fn ($call) => $call->name)->unique()->values(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('Laravel AI Assistant failed; using existing diagnostic fallback.', [
                    'provider' => $provider,
                    'exception' => get_class($exception),
                    'message' => mb_substr($exception->getMessage(), 0, 500),
                ]);
            }
        }

        $fallback = $engineer->chat($data['question'], $data['customer_id'] ?? null, $data['history'] ?? []);

        return response()->json($fallback);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $items = Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->whereHas('messages', fn ($query) => $query->where('agent', XLinkAssistant::class))
            ->with(['messages' => fn ($query) => $query->where('agent', XLinkAssistant::class)->latest()->limit(1)])
            ->latest('updated_at')
            ->limit(30)
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['items' => $items->map(fn ($item) => [
            'id' => $item->id,
            'title' => $item->title,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ])]);
    }

    public function showHistory(Request $request, string $conversationId)
    {
        $user = $request->user();
        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->whereHas('messages', fn ($query) => $query->where('agent', XLinkAssistant::class))
            ->firstOrFail();

        $messages = $conversation->messages()
            ->where('agent', XLinkAssistant::class)
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'role', 'content', 'created_at', 'steps']);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'messages' => $messages->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String(),
                'tools' => $message->tool_calls,
            ]),
        ]);
    }

    public function search(Request $request, AiEngineerDiagnosticService $engineer)
    {
        return response()->json($engineer->searchCustomers((string) $request->query('q', '')));
    }

    public function mapOnu(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $data = $request->validate([
            'mapping_id' => ['required', 'integer', 'min:1'],
            'customer_id' => ['required', 'integer', 'exists:customers_infos,id'],
        ]);

        $result = $engineer->mapExactCandidate(
            (int) $data['mapping_id'],
            (int) $data['customer_id'],
            (int) $request->user()->id
        );

        if (! ($result['ok'] ?? false)) {
            return back()->withErrors([
                'ai_mapping' => $result['message'] ?? 'The ONU mapping could not be saved.',
            ]);
        }

        return back()->with('ai_mapping_message', $result['message'] ?? 'Exact ONU mapping saved.');
    }

    public function feedback(Request $request)
    {
        $data = $request->validate(['rating'=>['required','in:up,down'],'customer_id'=>['nullable','string','max:100']]);
        Log::info('AI Engineer feedback', $data);

        return response()->json(['ok'=>true]);
    }
}
