<?php
namespace App\Http\Controllers;
use App\Models\NetworkInventoryDevice;
use App\Models\OltProvisioningAudit;
use App\Services\Olt\VsolCliProvisioningService;
use App\Services\Olt\VsolModelDetector;
use Illuminate\Http\Request;
use Throwable;
class OltProvisioningController extends Controller {
 public function index(NetworkInventoryDevice $device){ abort_unless(hasAccess(['Super Admin'], ['network-inventory','mikrotik-setup']),403,'Unauthorized OLT access.'); abort_unless($device->type==='olt',404); $profiles=config('olt_vsol.profiles',[]); $audits=OltProvisioningAudit::where('olt_device_id',$device->id)->latest()->paginate(25); $selected=data_get($this->adapter($device),'vsol_model_profile'); return view('xlink.olt-provisioning',compact('device','profiles','audits','selected')); }
 public function discover(Request $request, NetworkInventoryDevice $device)
 {
  abort_unless(hasAccess(['Super Admin'], ['mikrotik-setup']),403,'Only Super Admin can change OLT discovery configuration.');
  abort_unless($device->type==='olt',404);
  $audit=OltProvisioningAudit::create(['olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>'discover_model','target'=>'read-only','model_profile'=>'discovery','transport'=>'ssh','request_payload'=>['command'=>'show version'],'status'=>'pending','source_ip'=>$request->ip()]);
  $start=microtime(true);
  try {
   $s=new VsolCliProvisioningService(); $s->connectReadOnly($device); $result=$s->discoverVersion();
   $detection=(new VsolModelDetector())->detect($result);
   $cfg=$this->adapter($device);
   if ($detection['status']==='detected') {
    $cfg['vsol_model_profile']=$detection['profile'];
    $device->adapter_config=$cfg;
    $device->save();
   }
   $audit->update([
    'status'=>'success',
    'result'=>$this->redact($result),
    'request_payload'=>['command'=>'show version','detection'=>$detection],
    'model_profile'=>$detection['profile'] ?? 'discovery',
    'target'=>$detection['model'] ?? 'read-only',
    'duration_ms'=>(int)round((microtime(true)-$start)*1000)
   ]);
   $message=$detection['status']==='detected'
    ? 'VSOL model detected: '.$detection['model'].'. Matching command profile has been selected automatically.'
    : 'VSOL version discovery completed, but no unique exact model profile was detected. Manual model selection is required.';
   return back()->with('discovery_message',$message);
  } catch (Throwable $e) {
   $audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]);
   return back()->withErrors(['discovery'=>$e->getMessage()]);
  }
 }

 public function execute(Request $request, NetworkInventoryDevice $device){
  abort_unless(hasAccess(['Super Admin'], ['mikrotik-setup']),403,'Only Super Admin can execute OLT provisioning commands.');
  abort_unless($device->type==='olt',404); $profiles=config('olt_vsol.profiles',[]); $profileKey=(string)$request->input('profile'); abort_unless(isset($profiles[$profileKey]),422,'Unknown VSOL model profile.'); $profile=$profiles[$profileKey];
  $action=(string)$request->input('action'); $rules=['profile'=>'required|string','action'=>'required|in:authorize_mac,remove_mac,configure_pppoe,configure_static_ip,disable_onu,enable_onu'];
  if(in_array($action,['authorize_mac','remove_mac'],true))$rules+=['pon'=>'required|regex:/^\d+\/\d+$/','mac'=>'required|regex:/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/i'];
  if(in_array($action,['configure_pppoe','configure_static_ip','disable_onu','enable_onu'],true))$rules+=['pon'=>'required|regex:/^\d+\/\d+$/','onu'=>'required|integer|min:1|max:65535'];
  if($action==='configure_pppoe')$rules+=['username'=>'required|string|max:128','password'=>'required|string|max:128','server'=>'nullable|string|max:128'];
  if($action==='configure_static_ip')$rules+=['ip'=>'required|ip','netmask'=>'required|ip','gateway'=>'required|ip','dns1'=>'required|ip','dns2'=>'required|ip'];
  $data=$request->validate($rules); $audit=OltProvisioningAudit::create(['olt_device_id'=>$device->id,'user_id'=>auth()->id(),'action'=>$action,'target'=>($data['pon']??'').'/'.($data['onu']??($data['mac']??'')),'model_profile'=>$profileKey,'transport'=>$profile['transport']??'ssh','request_payload'=>array_diff_key($data,['password'=>true]),'status'=>'pending','source_ip'=>$request->ip()]); $start=microtime(true);
  $model=trim((string)$device->model);
  if ($model==='' || !collect($profile['models'] ?? [])->contains(fn($m)=>strcasecmp(trim($m),$model)===0)) {
   $message='OLT model must be set and exactly match the selected VSOL command profile. No command was sent.';
   $audit->update(['status'=>'failed','error'=>$message,'duration_ms'=>0]);
   return back()->withErrors(['provisioning'=>$message]);
  }
  try{ $cfg=$this->adapter($device); $cfg['vsol_model_profile']=$profileKey; $cfg['commands']=$profile['commands']; $cfg['write_transport']=$profile['transport']??'ssh'; $device->adapter_config=$cfg; $s=new VsolCliProvisioningService(); $s->connect($device);
   $result=match($action){'authorize_mac'=>$s->authorizeByMac($data['pon'],$data['mac']),'remove_mac'=>$s->removeByMac($data['pon'],$data['mac']),'configure_pppoe'=>$s->configurePppoe($data['pon'],$data['onu'],$data['username'],$data['password'],$data['server']??''),'configure_static_ip'=>$s->configureStaticIp($data['pon'],$data['onu'],$data['ip'],$data['netmask'],$data['gateway'],$data['dns1'],$data['dns2']),'disable_onu'=>$s->disableOnu($data['pon'],$data['onu']),'enable_onu'=>$s->enableOnu($data['pon'],$data['onu'])};
   $audit->update(['status'=>'success','result'=>$this->redact((string)$result),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]); return back()->with('provisioning_message','Provisioning command completed. Audit ID #'.$audit->id);
  }catch(Throwable $e){$audit->update(['status'=>'failed','error'=>$e->getMessage(),'duration_ms'=>(int)round((microtime(true)-$start)*1000)]); return back()->withErrors(['provisioning'=>$e->getMessage()]);}
 }
 private function adapter(NetworkInventoryDevice $d):array{$r=$d->adapter_config;if(is_array($r))return $r;$v=json_decode((string)$r,true);return is_array($v)?$v:[];}
 private function redact(string $s):string{return preg_replace('/(pwd\s+|password[=: ]+)[^\s;]+/i','$1[REDACTED]',$s);}
}