<?php
namespace App\Services\Olt;
use App\Contracts\OltReadOnlyAdapter;
use App\Models\NetworkInventoryDevice;
use RuntimeException;
class OltReadOnlyAdapterManager {
    private array $adapters = [];
    public function register(OltReadOnlyAdapter $adapter): void { $this->adapters[$adapter->key()] = $adapter; }
    public function adapterFor(NetworkInventoryDevice $device): OltReadOnlyAdapter {
        $requested=data_get($this->config($device),'adapter');
        if($requested&&isset($this->adapters[$requested])&&$this->adapters[$requested]->supports($device)) return $this->adapters[$requested];
        foreach($this->adapters as $adapter) if($adapter->supports($device)) return $adapter;
        throw new RuntimeException("No read-only OLT adapter is available for device {$device->id}.");
    }
    public function read(NetworkInventoryDevice $device): array { return $this->adapterFor($device)->read($device); }
    public function config(NetworkInventoryDevice $device): array {
        $raw=$device->adapter_config;
        if(is_array($raw)) return $raw;
        if(!is_string($raw)||trim($raw)==='') return [];
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:[];
    }
}
