<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Services;

use XLink\SmsBridge\Models\SmsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsBridgeService
{
    public function __construct()
    {
        $this->ensureLogGatewayExists();
    }

    /**
     * Ensure the dummy 'log' gateway always exists in the database.
     */
    protected function ensureLogGatewayExists(): void
    {
        try {
            if (!SmsGateway::where('name', 'log')->exists()) {
                SmsGateway::create([
                    'name' => 'log',
                    'api_url' => '',
                    'api_key' => '',
                    'api_secret' => '',
                    'sender_id' => '',
                    'priority' => 0,
                    'is_active' => SmsGateway::count() === 0,
                    'is_default' => SmsGateway::count() === 0,
                ]);
            }
        } catch (\Exception $e) {
            // DB might not be migrated yet
        }
    }

    /**
     * Retrieve all gateways, optionally active only.
     */
    public function getGateways(bool $activeOnly = false): array
    {
        try {
            $query = SmsGateway::orderBy('priority', 'asc');
            if ($activeOnly) {
                $query->where('is_active', true);
            }
            return $query->get()->toArray();
        } catch (\Exception $e) {
            return []; // Return empty if tables don't exist yet
        }
    }

    /**
     * Get a specific gateway by ID.
     */
    public function getGateway(string|int $id): ?array
    {
        try {
            return SmsGateway::find($id)?->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the default gateway, fallback to highest priority.
     */
    public function getDefaultGateway(): ?array
    {
        try {
            $default = SmsGateway::where('is_default', true)->where('is_active', true)->first();
            if ($default) {
                return $default->toArray();
            }

            $highestPriority = SmsGateway::where('is_active', true)->orderBy('priority', 'asc')->first();
            return $highestPriority?->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save/Update a single gateway
     */
    public function updateOrCreateGateway(array $data, string|int|null $id = null)
    {
        // Handle uniqueness of 'is_default'
        if (isset($data['is_default']) && $data['is_default']) {
            SmsGateway::where('id', '!=', $id ?? 0)->update(['is_default' => false]);
        }

        // Handle logical constraints on the 'log' gateway
        if (isset($data['is_active']) && $data['is_active']) {
            if ($data['name'] === 'log') {
                SmsGateway::where('name', '!=', 'log')->update(['is_active' => false]);
            } else {
                SmsGateway::where('name', 'log')->update(['is_active' => false]);
            }
        }

        if ($id) {
            $gateway = SmsGateway::findOrFail($id);
            $gateway->update($data);
        } else {
            SmsGateway::create($data);
        }
    }

    /**
     * Delete Gateway
     */
    public function deleteGateway(string|int $id)
    {
        $gateway = SmsGateway::find($id);
        if ($gateway && $gateway->name !== 'log') {
            $gateway->delete();
        }
    }

    /**
     * Check the balance for a specific gateway.
     */
    public function checkBalance(string|int $gatewayId): array
    {
        $gateway = $this->getGateway($gatewayId);
        if (!$gateway) {
            return ['success' => false, 'error' => 'Gateway not found.'];
        }

        try {
            $manager = app(\XLink\SmsBridge\SmsBridgeManager::class);
            $driver = $manager->driver(strtolower($gateway['name']));

            $driver->setCredentials([
                'url' => $gateway['api_url'] ?? '',
                'api_send_url' => $gateway['api_send_url'] ?? '',
                'api_balance_url' => $gateway['api_balance_url'] ?? '',
                'api_profile_url' => $gateway['api_profile_url'] ?? '',
                'api_key' => $gateway['api_key'] ?? '',
                'api_secret' => $gateway['api_secret'] ?? '',
                'sender_id' => $gateway['sender_id'] ?? '',
            ]);

            $balance = $driver->checkBalance();

            if ($balance !== null) {
                if (is_array($balance)) {
                    $balance = json_encode($balance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                } else {
                    $balance = (string)$balance;
                }
                return ['success' => true, 'balance' => $balance, 'gateway' => $gateway['name']];
            } else {
                return ['success' => false, 'error' => 'Balance query not supported or failed for this gateway.'];
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $errData = $e->response->json() ?? [];
            $msg = $errData['error']['error_message'] ?? $errData['api_response_message'] ?? $errData['message'] ?? $errData['error_message'] ?? $e->getMessage();
            return ['success' => false, 'error' => 'API Error: ' . $msg];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Send SMS with Failover Logic or via a specific gateway if ID is provided
     */
    public function sendSms(string $phone, string $message, string|int|null $gatewayId = null): array
    {
        if ($gatewayId) {
            $gateway = $this->getGateway($gatewayId);
            if (!$gateway) {
                return ['success' => false, 'error' => 'Gateway not found.'];
            }
            $gateways = [$gateway]; // Test only this specific gateway
        } else {
            $gateways = $this->getGateways(true);

            if (empty($gateways)) {
                return ['success' => false, 'error' => 'No active SMS gateways available.'];
            }

            // Move default gateway to top
            $defaultIndex = array_search(true, array_column($gateways, 'is_default'));
            if ($defaultIndex !== false && $defaultIndex > 0) {
                $default = $gateways[$defaultIndex];
                unset($gateways[$defaultIndex]);
                array_unshift($gateways, $default);
            }
        }

        foreach ($gateways as $gateway) {
            try {
                $manager = app(\XLink\SmsBridge\SmsBridgeManager::class);
                $driver = $manager->driver(strtolower($gateway['name']));

                // Override with JSON DB credentials
                $driver->setCredentials([
                    'url' => $gateway['api_url'] ?? '',
                    'api_send_url' => $gateway['api_send_url'] ?? '',
                    'api_balance_url' => $gateway['api_balance_url'] ?? '',
                    'api_profile_url' => $gateway['api_profile_url'] ?? '',
                    'api_key' => $gateway['api_key'] ?? '',
                    'api_secret' => $gateway['api_secret'] ?? '',
                    'sender_id' => $gateway['sender_id'] ?? ''
                ]);

                // The driver's processSend() returns an SmsResponse wrapper
                $response = $driver->send($phone, $message);

                if (!$response->isSuccessful()) {
                    // Treat non-success responses as failures to trigger failover
                    throw new \Exception("Gateway returned unsuccessful status: " . $response->getMessage());
                }

                return [
                    'success' => true,
                    'gateway' => $gateway['name'],
                    'response' => $response->toArray()
                ];
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $errData = $e->response->json() ?? [];
                $msg = $errData['error']['error_message'] ?? $errData['api_response_message'] ?? $errData['message'] ?? $errData['error_message'] ?? $e->getMessage();

                if ($gatewayId) {
                    return [
                        'success' => false,
                        'gateway' => $gateway['name'],
                        'error' => 'API Error: ' . $msg
                    ];
                }

                Log::error("SmsBridge: API Exception on {$gateway['name']}.", [
                    'error' => 'API Error: ' . $msg
                ]);
            } catch (\Exception $e) {
                if ($gatewayId) {
                    // For direct tests, return the error immediately
                    return [
                        'success' => false,
                        'gateway' => $gateway['name'],
                        'error' => $e->getMessage()
                    ];
                }

                Log::error("SmsBridge: Exception on {$gateway['name']}.", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return ['success' => false, 'error' => 'All configured SMS gateways failed.'];
    }
}
