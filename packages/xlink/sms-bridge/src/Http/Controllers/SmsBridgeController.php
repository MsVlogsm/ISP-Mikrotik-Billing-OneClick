<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use XLink\SmsBridge\Services\SmsBridgeService;

class SmsBridgeController extends Controller
{
    protected $smsService;

    public function __construct(SmsBridgeService $smsService)
    {
        $this->smsService = $smsService;
        $this->middleware('auth');
    }

    public function index()
    {
        $gateways = $this->smsService->getGateways();
        return view('smsBridge::gateways.index', compact('gateways'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'api_url' => 'required|url',
            'api_send_url' => 'nullable|string',
            'api_balance_url' => 'nullable|string',
            'api_profile_url' => 'nullable|string',
            'api_key' => 'required|string',
            'api_secret' => 'nullable|string',
            'sender_id' => 'required|string',
            'priority' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $data['is_active'] = $request->has('is_active');
        $data['is_default'] = $request->has('is_default');

        $exists = \XLink\SmsBridge\Models\SmsGateway::where('name', $data['name'])
            ->where('api_url', $data['api_url'])
            ->where('api_key', $data['api_key'])
            ->where('sender_id', $data['sender_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()->withErrors(['error' => 'Request Denied: A gateway with this exact Name, API URL, API Key, and Sender ID already exists in the system!'])->withInput();
        }

        $this->smsService->updateOrCreateGateway($data);

        return redirect()->route('sms-bridge.index')->with('success', 'SMS Gateway added successfully.');
    }

    public function toggle(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean'
        ]);

        $data['is_active'] = $request->has('is_active');

        $this->smsService->updateOrCreateGateway($data, $id);

        return redirect()->route('sms-bridge.index')->with('success', 'Gateway status updated successfully.');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'api_url' => 'required|url',
            'api_send_url' => 'nullable|string',
            'api_balance_url' => 'nullable|string',
            'api_profile_url' => 'nullable|string',
            'api_key' => 'required|string',
            'api_secret' => 'nullable|string',
            'sender_id' => 'required|string',
            'priority' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $data['is_active'] = $request->has('is_active');
        $data['is_default'] = $request->has('is_default');

        $this->smsService->updateOrCreateGateway($data, $id);

        return redirect()->route('sms-bridge.index')->with('success', 'SMS Gateway updated successfully.');
    }

    public function balance($id)

    {

        $result = $this->smsService->checkBalance($id);

        return response()->json($result);

    }



    public function test(Request $request, $id)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $result = $this->smsService->sendSms($request->phone, $request->message, $id);

        if ($result['success']) {
            return redirect()->back()->with('success', 'Test SMS sent successfully via ' . $result['gateway']);
        }

        return redirect()->back()->withErrors(['error' => 'Failed to send test SMS: ' . ($result['error'] ?? json_encode($result['response'] ?? 'Unknown error'))]);
    }

    public function destroy($id)
    {
        $this->smsService->deleteGateway($id);
        return redirect()->route('sms-bridge.index')->with('success', 'SMS Gateway deleted.');
    }
}
