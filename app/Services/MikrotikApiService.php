<?php

namespace App\Services;

use Exception;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class MikrotikApiService
{
    protected $client;

    public function __construct($host, $port, $username, $password, int $timeout = 5, bool $ssl = false)
    {
        $parameters = [
            'host' => $host,
            'user' => $username,
            'pass' => $password,
            'port' => $port ?? ($ssl ? 8729 : 8728),
            'ssl' => $ssl,
            'timeout' => $timeout,
            'attempts' => 1,
        ];

        try {
            $this->client = new Client(new Config($parameters));
        } catch (Exception $e) {
            // Legacy RouterOS API-SSL without a certificate needs anonymous DH.
            // Retry with OpenSSL security level 0 only for an SSL socket handshake failure.
            $isSslHandshakeFailure = $ssl
                && str_contains(strtolower($e->getMessage()), 'unable to establish socket session');

            if (!$isSslHandshakeFailure) {
                throw new Exception('Failed to connect to MikroTik: '.$e->getMessage(), 0, $e);
            }

            try {
                $parameters['ssl_options'] = array_merge(
                    Config::SSL_OPTIONS,
                    ['ciphers' => 'ADH:ALL@SECLEVEL=0']
                );
                $this->client = new Client(new Config($parameters));
            } catch (Exception $retry) {
                throw new Exception('Failed to connect to MikroTik: '.$retry->getMessage(), 0, $retry);
            }
        }
    }

    /**
     * Execute a MikroTik API command
     *
     * @param  string  $command  The RouterOS command (e.g., '/system/resource/print')
     * @param  array  $params  Optional command parameters
     * @return array|string
     */
    public function executeCommand(string $command, array $params = [], array $filters = [])
    {
        try {
            $query = new Query($command);

            // Add filters (?) first as required by RouterOS API for some commands
            foreach ($filters as $key => $value) {
                $query->where($key, $value);
            }

            // Add parameters (=)
            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }

            $response = $this->client->query($query)->read();

            return $response;
        } catch (Exception $e) {
            report($e);
            return 'Error: MikroTik API command failed.';
        }
    }
}
