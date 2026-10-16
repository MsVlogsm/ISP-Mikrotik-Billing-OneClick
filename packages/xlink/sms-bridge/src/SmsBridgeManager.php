<?php

declare(strict_types=1);

namespace XLink\SmsBridge;

use Illuminate\Support\Manager;
use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use XLink\SmsBridge\Gateways\AdnSmsGateway;
use XLink\SmsBridge\Gateways\ElitBuzzGateway;
use XLink\SmsBridge\Gateways\AlphaSmsGateway;

class SmsBridgeManager extends Manager
{
    public function getDefaultDriver()
    {
        try {
            // First attempt to get the active UI-configured default gateway
            $service = app(\XLink\SmsBridge\Services\SmsBridgeService::class);
            $defaultDb = $service->getDefaultGateway();

            if ($defaultDb) {
                return strtolower($defaultDb['name']);
            }
        } catch (\Exception $e) {
            // Fallback gracefully if service or storage is completely unavailable
        }

        // Fallback to the environment configuration or 'log' dummy driver
        return $this->config->get('sms-bridge.default', 'log');
    }

    /**
     * Override Manager's driver creation to use our centralized buildGateway method.
     *
     * @param  string  $driver
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createDriver($driver)
    {
        $method = 'create'.\Illuminate\Support\Str::studly($driver).'Driver';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        // Fallback to our internal switch statement for all known gateways
        return $this->buildGateway($driver);
    }

    /**
     * Build the gateway instance and inject credentials.
     *
     * @param string $driver
     * @return SmsGatewayInterface
     */
    protected function buildGateway(string $driver): SmsGatewayInterface
    {
        switch ($driver) {
            case 'adnsms':
                $gateway = new \XLink\SmsBridge\Gateways\AdnSmsGateway();
                break;
            case 'elitbuzz':
                $gateway = new \XLink\SmsBridge\Gateways\ElitBuzzGateway();
                break;
            case 'alphasms':
                $gateway = new \XLink\SmsBridge\Gateways\AlphaSmsGateway();
                break;
            case 'banglalink':
                $gateway = new \XLink\SmsBridge\Gateways\BanglalinkGateway();
                break;
            case 'bulksms':
                $gateway = new \XLink\SmsBridge\Gateways\BulkSmsGateway();
                break;
            case 'esms':
                $gateway = new \XLink\SmsBridge\Gateways\EsmsGateway();
                break;
            case 'lsms':
                $gateway = new \XLink\SmsBridge\Gateways\LsmsGateway();
                break;
            case 'grameenphone':
                $gateway = new \XLink\SmsBridge\Gateways\GrameenphoneGateway();
                break;
            case 'greenweb':
                $gateway = new \XLink\SmsBridge\Gateways\GreenwebGateway();
                break;
            case 'infobip':
                $gateway = new \XLink\SmsBridge\Gateways\InfobipGateway();
                break;
            case 'mimsms':
                $gateway = new \XLink\SmsBridge\Gateways\MimsmsGateway();
                break;
            case 'robi':
                $gateway = new \XLink\SmsBridge\Gateways\RobiGateway();
                break;
            case 'smsnoc':
                $gateway = new \XLink\SmsBridge\Gateways\SmsnocGateway();
                break;
            case 'ssl':
                $gateway = new \XLink\SmsBridge\Gateways\SslGateway();
                break;
            case 'log':
                $gateway = new \XLink\SmsBridge\Gateways\LogGateway();
                break;
            default:
                throw new \InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        // Connect to active UI database to fetch JSON credentials dynamically
        try {
            $service = app(\XLink\SmsBridge\Services\SmsBridgeService::class);
            $gateways = $service->getGateways(true);
            $target = null;

            foreach ($gateways as $g) {
                if (strtolower($g['name']) === strtolower($driver)) {
                    $target = $g;
                    break;
                }
            }

            if ($target) {
                $gateway->setCredentials([
                    'url' => $target['api_url'] ?? '',
                    'api_send_url' => $target['api_send_url'] ?? '',
                    'api_balance_url' => $target['api_balance_url'] ?? '',
                    'api_profile_url' => $target['api_profile_url'] ?? '',
                    'api_key' => $target['api_key'] ?? '',
                    'api_secret' => $target['api_secret'] ?? '',
                    'sender_id' => $target['sender_id'] ?? ''
                ]);
                return $gateway;
            }
        } catch (\Exception $e) {
            // DB might not exist yet during initial boot, fallback to config
        }

        // Inject the unified credentials from config as fallback
        $credentials = $this->config->get('sms-bridge.credentials', []);
        $gateway->setCredentials($credentials);

        return $gateway;
    }

    /**
     * Create an instance of the AdnSms driver.
     *
     * @return SmsGatewayInterface
     */
    public function createAdnsmsDriver(): SmsGatewayInterface
    {
        return $this->buildGateway('adnsms');
    }

    /**
     * Create an instance of the ElitBuzz driver.
     *
     * @return SmsGatewayInterface
     */
    public function createElitbuzzDriver(): SmsGatewayInterface
    {
        return $this->buildGateway('elitbuzz');
    }

    /**
     * Create an instance of the AlphaSms driver.
     *
     * @return SmsGatewayInterface
     */
    public function createAlphasmsDriver(): SmsGatewayInterface
    {
        return $this->buildGateway('alphasms');
    }

    /**
     * Check the balance of the specified or default gateway via the Facade.
     *
     * @param string|null $driver
     * @return mixed
     */
    public function balance(?string $driver = null)
    {
        return $this->driver($driver)->checkBalance();
    }

    /**
     * Check the profile of the specified or default gateway via the Facade.
     *
     * @param string|null $driver
     * @return mixed
     */
    public function profile(?string $driver = null)
    {
        return $this->driver($driver)->checkProfile();
    }
}
