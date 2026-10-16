<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;

class DiagnoseBdcomSnmp extends Command
{
    protected $signature = 'olt:diagnose-bdcom-snmp {--device=5} {--walk : Read-only VSOL/BDCOM subtree discovery}';
    protected $description = 'Read-only OLT SNMP diagnostics';

    public function handle(): int
    {
        $d=NetworkInventoryDevice::find((int)$this->option('device'));
        if(!$d){$this->error('Device not found');return self::FAILURE;}
        $raw=$d->getRawOriginal('snmp_community');
        try{$community=Crypt::decryptString($raw);}catch(\Throwable){$community=$raw;}
        $host=(string)($d->ip_address?:$d->host);
        $this->line('OLT diagnostic: device='.$d->id.' host='.$host.' vendor='.strtoupper((string)$d->vendor));
        $sys=@snmp2_get($host,$community,'1.3.6.1.2.1.1.1.0',500000,0);
        $up=@snmp2_get($host,$community,'1.3.6.1.2.1.1.3.0',500000,0);
        $this->line('  sysDescr '.($sys===false?'FAIL':'OK'));
        $this->line('  sysUpTime '.($up===false?'FAIL':'OK'));
        if($this->option('walk')){
            $roots=['ID'=>'1.3.6.1.4.1.37950.1.1.5.12.1.9.1.1','PON'=>'1.3.6.1.4.1.37950.1.1.5.12.1.9.1.2','STATUS'=>'1.3.6.1.4.1.37950.1.1.5.12.1.9.1.4','MAC'=>'1.3.6.1.4.1.37950.1.1.5.12.1.9.1.5','VSOL_LIST'=>'1.3.6.1.4.1.37950.1.1.5.12.1.9','VSOL'=>'1.3.6.1.4.1.37950.1.1.5.12.1.12','VSOL2'=>'1.3.6.1.4.1.37950.1.1.5.12.1.25'];
            foreach($roots as $name=>$root){
                $this->line($name.' root '.$root);
                $walk=@snmp2_real_walk($host,$community,$root,700000,0);
                if(!is_array($walk)){ $this->line('  WALK FAIL'); continue; }
                $n=0;
                foreach($walk as $oid=>$value){
                    $this->line('  '.$oid.' = '.$this->safeValue($value));
                    if(++$n>=120){$this->line('  ... truncated');break;}
                }
                $this->line('  entries='.$n);
            }
        }
        return self::SUCCESS;
    }

    private function safeValue(mixed $v): string
    {
        $s=trim((string)$v);
        if(strlen($s)>120)$s=substr($s,0,120).'...';
        return $s;
    }
}
