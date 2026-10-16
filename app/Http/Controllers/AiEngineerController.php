<?php

namespace App\Http\Controllers;

use App\Services\AiEngineerDiagnosticService;
use Illuminate\Http\Request;

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
        return view('xlink.ai-engineer', compact('customerId', 'diagnosis', 'overview', 'dailySummary', 'unmappedOnus', 'upstreamCorrelation', 'matchingCandidates'));
    }

    public function diagnose(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $data = $request->validate(['customer_id' => ['required','string','max:100']]);
        return response()->json($engineer->diagnoseCustomer($data['customer_id']));
    }

    public function chat(Request $request, AiEngineerDiagnosticService $engineer)
    {
        $data = $request->validate(['question'=>['required','string','max:4000'],'customer_id'=>['nullable','string','max:100'],'history'=>['nullable','array']]);
        return response()->json($engineer->chat($data['question'], $data['customer_id'] ?? null, $data['history'] ?? []));
    }

    public function search(Request $request, AiEngineerDiagnosticService $engineer)
    {
        return response()->json($engineer->searchCustomers((string) $request->query('q', '')));
    }

    public function feedback(Request $request)
    {
        $data = $request->validate(['rating'=>['required','in:up,down'],'customer_id'=>['nullable','string','max:100']]);
        \Log::info('AI Engineer feedback', $data);
        return response()->json(['ok'=>true]);
    }
}
