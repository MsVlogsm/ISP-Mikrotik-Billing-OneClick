<?php

namespace App\Livewire;

use App\Models\SmsTemplate;
use App\Models\CustomersInfo;
use XLink\SmsBridge\Facades\SmsBridge;
use Livewire\Component;

class SMSSetup extends Component
{
    public $smsTempList = [];

    public $profile;

    public $balance;

    public $search = '';

    // Bulk SMS Properties
    public $activeTab = 'templates'; // 'templates' or 'bulk'

    public $recipientGroup = 'active'; // 'all', 'active', 'inactive', 'due', 'due_date', 'specific'

    public $targetDate;

    public $selectedTemplateId = '';

    public $bulkMessage = '';

    public $searchCustomer = '';

    public $selectedCustomerIds = [];

    public $bulkSearchList = [];

    public $excludedCustomerIds = [];

    public $previewSearch = '';

    // Load SMS templates initially
    public function mount()
    {
        if (! hasAccess(['Super Admin'], ['sms-setup'])) {
            abort(403, 'Unauthorized action.');
        }

        try {
            // The LSMS driver currently supports sending only; fetch account details
            // directly from its documented configured endpoints instead of returning null.
            $gatewayService = app(\XLink\SmsBridge\Services\SmsBridgeService::class);
            $activeGateway = $gatewayService->getDefaultGateway();
            $credentials = config('sms-bridge.credentials', []);
            $activeDriver = strtolower((string) ($activeGateway['name'] ?? SmsBridge::getDefaultDriver()));
            if ($activeDriver === 'lsms') {
                // Prefer credentials saved in the SMS Gateway UI, then environment config.
                $credentials = array_merge($credentials, array_filter([
                    'url' => $activeGateway['api_url'] ?? null,
                    'api_key' => $activeGateway['api_key'] ?? null,
                    'api_balance_url' => $activeGateway['api_balance_url'] ?? null,
                    'api_profile_url' => $activeGateway['api_profile_url'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));
            }
            if ($activeDriver === 'lsms' && ! empty($credentials['url']) && ! empty($credentials['api_key'])) {
                $baseUrl = rtrim((string) $credentials['url'], '/');
                $baseUrl = preg_replace('#/api/v1(?:/smsapi)?$#i', '', $baseUrl);
                $apiKey = (string) $credentials['api_key'];
                $balancePath = trim((string) ($credentials['api_balance_url'] ?? 'balance'), '/');
                $profilePath = trim((string) ($credentials['api_profile_url'] ?? 'user-info'), '/');
                if (! str_contains($balancePath, '://') && ! str_starts_with($balancePath, 'api/v1/')) {
                    $balancePath = 'api/v1/'.$balancePath;
                }
                if (! str_contains($profilePath, '://') && ! str_starts_with($profilePath, 'api/v1/')) {
                    $profilePath = 'api/v1/'.$profilePath;
                }
                $balanceUrl = str_contains($balancePath, '://') ? $balancePath : $baseUrl.'/'.$balancePath;
                $profileUrl = str_contains($profilePath, '://') ? $profilePath : $baseUrl.'/'.$profilePath;
                $balanceResponse = \Illuminate\Support\Facades\Http::timeout(10)->acceptJson()->get($balanceUrl, ['api_key' => $apiKey]);
                $profileResponse = \Illuminate\Support\Facades\Http::timeout(10)->acceptJson()->get($profileUrl, ['api_key' => $apiKey]);
                $balanceData = $balanceResponse->json();
                $profileData = $profileResponse->json();
                $balanceOk = $balanceResponse->successful() && is_array($balanceData)
                    && ! filter_var($balanceData['error'] ?? true, FILTER_VALIDATE_BOOLEAN) && isset($balanceData['balance']);
                $profileOk = $profileResponse->successful() && is_array($profileData)
                    && ! filter_var($profileData['error'] ?? true, FILTER_VALIDATE_BOOLEAN) && is_array($profileData['user'] ?? null);
                $this->balance = $balanceOk ? [
                    'status' => 'success',
                    'data' => ['remaining_balance' => $balanceData['balance'], 'message' => $balanceData['message'] ?? null],
                ] : ['status' => 'error'];
                if ($profileOk) {
                    $user = $profileData['user'];
                    $user['first_name'] = $user['first_name'] ?? ($user['name'] ?? '');
                    $user['last_name'] = $user['last_name'] ?? '';
                    $this->profile = ['status' => 'success', 'data' => $user];
                } else {
                    $this->profile = ['status' => 'error'];
                }
            } else {
                $this->profile = SmsBridge::profile();
                $this->balance = SmsBridge::balance();
            }
        } catch (\Throwable $e) {
            $this->profile = null;
            $this->balance = null;
            report($e);
            flash()->warning('SMS Gateway connection warning. Please check the gateway settings.');
        }
        
        // Map SMS template content for Livewire binding
        $this->smsTempList = SmsTemplate::pluck('template', 'id')->toArray();
        
        // Reference-parity links can open either the template or bulk tab.
        $this->activeTab = request()->query('tab') === 'bulk' ? 'bulk' : 'templates';

        // Default target date to today
        $this->targetDate = now()->format('Y-m-d');
    }

    // Toggle SMS template active status
    public function setSmsActive($id)
    {
        $smsTemplate = SmsTemplate::find($id);

        if ($smsTemplate) {
            $smsTemplate->is_active = ! $smsTemplate->is_active;
            $smsTemplate->save();
            flash()->success('SMS template status updated successfully.');
        }
    }

    // Update SMS template message
    public function updateSms($id)
    {
        $smsTemplate = SmsTemplate::find($id);

        if ($smsTemplate) {
            // Update with new content
            $smsTemplate->template = $this->smsTempList[$id] ?? $smsTemplate->template;
            $smsTemplate->save();
            flash()->success('SMS template updated successfully.');
        } else {
            flash()->error('SMS template not found.');
        }
    }

    // Set active tab and reset campaign variables
    public function switchTab($tab)
    {
        $this->activeTab = $tab;
        
        // Reset state when switching tabs
        $this->recipientGroup = 'active';
        $this->excludedCustomerIds = [];
        $this->selectedCustomerIds = [];
        $this->previewSearch = '';
        $this->searchCustomer = '';
        $this->bulkSearchList = [];
        $this->bulkMessage = '';
        $this->selectedTemplateId = '';
    }

    // Reset exclusions/selections when target group changes
    public function updatedRecipientGroup($value)
    {
        $this->excludedCustomerIds = [];
        $this->selectedCustomerIds = [];
        $this->previewSearch = '';
        $this->searchCustomer = '';
        $this->bulkSearchList = [];
    }

    // Handle template dropdown selection changes
    public function updatedSelectedTemplateId($value)
    {
        if ($value) {
            $template = SmsTemplate::find($value);
            if ($template) {
                $this->bulkMessage = $template->template;
            }
        } else {
            $this->bulkMessage = '';
        }
    }

    // Handle specific customer search
    public function updatedSearchCustomer($value)
    {
        if (strlen($value) >= 2) {
            $this->bulkSearchList = CustomersInfo::query()
                ->where('status', 'active')
                ->where(function($q) use ($value) {
                    $q->where('customer_name', 'like', '%' . $value . '%')
                      ->orWhere('customer_unique_id', 'like', '%' . $value . '%')
                      ->orWhere('mobile', 'like', '%' . $value . '%')
                      ->orWhereHas('pppUser', function ($sq) use ($value) {
                          $sq->where('username', 'like', '%' . $value . '%');
                      });
                })
                ->limit(8)
                ->get();
        } else {
            $this->bulkSearchList = [];
        }
    }

    // Add manual customer to recipient list
    public function addCustomerToSelection($id)
    {
        if (!in_array($id, $this->selectedCustomerIds)) {
            $this->selectedCustomerIds[] = $id;
        }
        $this->searchCustomer = '';
        $this->bulkSearchList = [];
    }

    // Remove customer from manual selection list
    public function removeCustomerFromSelection($id)
    {
        $this->selectedCustomerIds = array_values(array_diff($this->selectedCustomerIds, [$id]));
    }

    // Exclude customer from campaign
    public function excludeCustomer($id)
    {
        if (!in_array($id, $this->excludedCustomerIds)) {
            $this->excludedCustomerIds[] = $id;
        }
        $this->previewSearch = '';
    }

    // Restore excluded customer
    public function restoreCustomer($id)
    {
        $this->excludedCustomerIds = array_values(array_diff($this->excludedCustomerIds, [$id]));
    }

    // Helper to compile recipient query
    private function getRecipientsQuery()
    {
        $query = CustomersInfo::query()->with(['pppUser', 'billing']);

        if (!empty($this->excludedCustomerIds)) {
            $query->whereNotIn('id', $this->excludedCustomerIds);
        }

        switch ($this->recipientGroup) {
            case 'all':
                // All customers
                break;
            case 'active':
                $query->where('status', 'active');
                break;
            case 'inactive':
                $query->where('status', 'inactive');
                break;
            case 'due':
                $query->whereHas('billing', function ($q) {
                    $q->where('due_amount', '>', 0);
                });
                break;
            case 'due_date':
                $query->whereHas('billing', function ($q) {
                    $q->where('due_amount', '>', 0)
                      ->whereDate('auto_disable_date', $this->targetDate);
                });
                break;
            case 'specific':
                $query->whereIn('id', $this->selectedCustomerIds);
                break;
        }

        return $query;
    }

    // Send the Bulk SMS by dispatching a queued background job
    public function sendBulkSms()
    {
        if (! hasAccess(['Super Admin'], ['sms-setup'])) {
            abort(403, 'Unauthorized action.');
        }

        $this->validate([
            'recipientGroup' => 'required|in:all,active,inactive,due,due_date,specific',
            'bulkMessage' => 'required|string|min:5',
            'targetDate' => 'required_if:recipientGroup,due_date',
            'selectedCustomerIds' => 'required_if:recipientGroup,specific|array|min:1',
        ], [
            'bulkMessage.required' => 'SMS message content is required.',
            'selectedCustomerIds.required_if' => 'Please select at least one customer to send SMS.',
        ]);

        $recipientQuery = $this->getRecipientsQuery();
        $customerIds = $recipientQuery->pluck('id')->toArray();

        if (empty($customerIds)) {
            flash()->error('No recipient customers found matching your selected filters.');
            return;
        }

        // Dispatch background job
        \App\Jobs\SendBulkSmsJob::dispatch(
            $customerIds,
            $this->bulkMessage,
            auth()->id()
        );

        flash()->success('Bulk SMS campaign has been scheduled in the background for ' . count($customerIds) . ' recipient(s).');

        // Reset composer inputs
        $this->bulkMessage = '';
        $this->selectedTemplateId = '';
        $this->selectedCustomerIds = [];
        $this->searchCustomer = '';
        $this->bulkSearchList = [];
        $this->excludedCustomerIds = [];
        $this->previewSearch = '';
    }

    public function render()
    {
        $smsTemps = SmsTemplate::when($this->search, function ($q) {
            $q->where('template_name', 'like', '%' . $this->search . '%')
              ->orWhere('template', 'like', '%' . $this->search . '%');
        })->get();

        // Bulk SMS variables
        $activeTemplates = SmsTemplate::where('is_active', true)->get();
        $recipientsCount = $this->getRecipientsQuery()->count();
        
        $previewQuery = $this->getRecipientsQuery();
        if (!empty($this->previewSearch)) {
            $previewQuery->where(function($q) {
                $q->where('customer_name', 'like', '%' . $this->previewSearch . '%')
                  ->orWhere('customer_unique_id', 'like', '%' . $this->previewSearch . '%')
                  ->orWhere('mobile', 'like', '%' . $this->previewSearch . '%')
                  ->orWhereHas('pppUser', function ($sq) {
                      $sq->where('username', 'like', '%' . $this->previewSearch . '%');
                  });
            });
        }
        $recipientsPreview = $previewQuery->limit(8)->get();
        
        $selectedCustomers = [];
        if (!empty($this->selectedCustomerIds)) {
            $selectedCustomers = CustomersInfo::whereIn('id', $this->selectedCustomerIds)->with(['pppUser'])->get();
        }

        $excludedCustomers = [];
        if (!empty($this->excludedCustomerIds)) {
            $excludedCustomers = CustomersInfo::whereIn('id', $this->excludedCustomerIds)->get();
        }

        return view('livewire.s-m-s-setup', compact(
            'smsTemps',
            'activeTemplates',
            'recipientsCount',
            'recipientsPreview',
            'selectedCustomers',
            'excludedCustomers'
        ))->layout('layouts.app');
    }
}

