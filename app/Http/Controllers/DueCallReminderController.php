<?php
namespace App\Http\Controllers;
use App\Models\BillingInfo;
use App\Models\DueCallReminderLog;
use App\Models\MainSiteData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
class DueCallReminderController extends Controller {
 public function index(){
  $settings=['enabled'=>(bool)MainSiteData::getValue('due_call_enabled',0),'api_url'=>(string)MainSiteData::getValue('due_call_api_url',''),'api_token'=>'','daily_limit'=>(int)MainSiteData::getValue('due_call_daily_limit',50),'message'=>(string)MainSiteData::getValue('due_call_message','Assalamu Alaikum {customer_name}. Your internet bill due is {due_amount} taka. Please pay at your earliest convenience.'),'payload_json'=>(string)MainSiteData::getValue('due_call_payload_json','{"to":"{phone}","message":"{message}","customer_id":"{customer_id}","amount":"{due_amount}"}')];
  $stats=['today'=>DueCallReminderLog::whereDate('reminder_date',today())->count(),'submitted'=>DueCallReminderLog::whereDate('reminder_date',today())->where('status','submitted')->count(),'failed'=>DueCallReminderLog::whereDate('reminder_date',today())->where('status','failed')->count(),'due'=>BillingInfo::where('due_amount','>',0)->count()];$logs=DueCallReminderLog::latest('id')->paginate(25);return view('xlink.due-call-reminders',compact('settings','stats','logs'));
 }
 public function save(Request $request){$d=$request->validate(['enabled'=>'nullable|boolean','api_url'=>'nullable|url|max:1000','api_token'=>'nullable|string|max:4000','daily_limit'=>'required|integer|min:1|max:500','message'=>'required|string|min:5|max:1500','payload_json'=>'required|string|max:10000']);if(!is_array(json_decode($d['payload_json'],true)))return back()->withErrors(['payload_json'=>'Payload template must be valid JSON.'])->withInput();foreach(['api_url','daily_limit','message','payload_json'] as $k)MainSiteData::setValue('due_call_'.$k,$d[$k]??'');if(trim((string)($d['api_token']??''))!=='')MainSiteData::setValue('due_call_api_token',Crypt::encryptString($d['api_token']));MainSiteData::setValue('due_call_enabled',!empty($d['enabled'])?'1':'0');Cache::forget('main_site_data_active');return redirect()->route('due-call-reminders.index')->with('call_reminder_message','Settings saved. Calls require a valid provider API endpoint and enabled setting.');}
 public function run(Request $request){abort_unless($request->user()&&($request->user()->hasRole('super_admin')||$request->user()->hasRole('admin')),403,'Only administrators can run voice reminder batches.');Artisan::call('billing:due-call-reminders');return redirect()->route('due-call-reminders.index')->with('call_reminder_message',trim(Artisan::output()));}
}
