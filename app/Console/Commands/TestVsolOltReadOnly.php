<?php
namespace App\Console\Commands;
use App\Models\NetworkInventoryDevice;
use App\Services\Olt\OltReadOnlyAdapterManager;
use Illuminate\Console\Command;
class TestVsolOltReadOnly extends Command {
    protected $signature='olt:vsol-readonly-test {--device= : OLT device ID}';
    protected $description='Safely test the VSOL read-only adapter without performing any OLT write operation';
    public function handle(OltReadOnlyAdapterManager $manager): int {
        $q=NetworkInventoryDevice::query()->where('type','olt'); if($this->option('device')) $q->whereKey((int)$this->option('device'));
        $devices=$q->get(); if($devices->isEmpty()){ $this->warn('No OLT device matched.'); return self::SUCCESS; }
        foreach($devices as $device){ $this->line("OLT #{$device->id} {$device->name} ({$device->vendor} {$device->model})");
            try{$adapter=$manager->adapterFor($device); $this->line('Adapter: '.$adapter->key()); $result=$adapter->read($device);
                $this->line('Status: '.($result['status']??'unknown')); $this->line('Message: '.($result['message']??''));
                $this->line('Read-only: '.((bool)data_get($result,'meta.read_only')?'YES':'NO')); $this->line('Writes performed: '.((bool)data_get($result,'meta.writes_performed')?'YES':'NO'));
                $this->line('Configured OIDs read: '.(int)data_get($result,'meta.oid_count',0));
                $this->line('ONU discovery configured: '.((bool)data_get($result,'meta.onu_discovery_configured')?'YES':'NO'));
                $this->line('ONU rows discovered: '.(int)data_get($result,'meta.onu_count',0));
                if(!empty(data_get($result,'meta.failed_oids'))) $this->warn('Some configured OIDs failed to read.');
            }catch(\Throwable $e){$this->warn('Adapter unavailable: '.$e->getMessage());}
        } return self::SUCCESS;
    }
}
