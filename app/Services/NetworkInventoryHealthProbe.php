<?php

namespace App\Services;

use App\Models\NetworkInventoryDevice;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\Process;

class NetworkInventoryHealthProbe
{
    public function check(NetworkInventoryDevice $device): array
    {
        $host = trim((string)($device->ip_address ?: $device->host));
        $port = (int)($device->health_port ?: $device->port ?: ($device->type === 'olt' ? 23 : 80));

        if ($host === '') return ['ok'=>false,'status'=>'not_ready','message'=>'Device host/IP is not configured.','latency_ms'=>0,'protocol'=>'none','port'=>$port];

        if ($port === 161) {
            return $this->snmp($device, $host, $port);
        }

        $started=microtime(true); $errno=0; $error='';
        $socket=@fsockopen($host,$port,$errno,$error,3);
        $latency=(int)round((microtime(true)-$started)*1000);
        if ($socket!==false) { fclose($socket); return ['ok'=>true,'status'=>'online','message'=>'TCP connectivity is healthy.','latency_ms'=>$latency,'protocol'=>'tcp','port'=>$port]; }
        return ['ok'=>false,'status'=>'down','message'=>($error ?: 'connection refused'),'latency_ms'=>$latency,'protocol'=>'tcp','port'=>$port];
    }

    private function snmp(NetworkInventoryDevice $device, string $host, int $port): array
    {
        $raw=$device->getRawOriginal('snmp_community');
        $community='';
        if ($raw) { try { $community=Crypt::decryptString($raw); } catch (\Throwable) { $community=(string)$raw; } }
        if ($community==='') return ['ok'=>false,'status'=>'not_ready','message'=>'SNMP health port 161 is reserved for SNMP monitoring, but no SNMP community is configured. TCP probing of port 161 is intentionally disabled.','latency_ms'=>0,'protocol'=>'snmp','port'=>161];

        $started=microtime(true);
        $process=new Process(['snmpget','-v',strtoupper((string)($device->snmp_version ?: '2c')),'-c',$community,'-t','2','-r','0',$host.':'.$port,'1.3.6.1.2.1.1.3.0']);
        $process->setTimeout(5);
        $process->run();
        $latency=(int)round((microtime(true)-$started)*1000);
        return ['ok'=>$process->isSuccessful(),'status'=>$process->isSuccessful()?'online':'down','message'=>$process->isSuccessful() ? 'SNMP sysUpTime responded.' : (trim($process->getErrorOutput()) ?: 'SNMP request failed.'),'latency_ms'=>$latency,'protocol'=>'snmp','port'=>161];
    }
}
