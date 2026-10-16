<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('company_service_options')) {
            Schema::create('company_service_options', function (Blueprint $table) {
                $table->id();
                $table->string('category', 40);
                $table->string('code', 80);
                $table->string('name', 120);
                $table->string('description', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->timestamps();
                $table->unique(['category','code']);
                $table->index(['category','is_active']);
            });
        }

        $now=now();
        $rows=[];
        $defaults=[
            'connection_type'=>[
                ['fiber','Fiber (FTTH)','Fiber-to-the-home optical connection.'],
                ['wired','Cat-6 / LAN','Structured UTP Ethernet connection.'],
                ['wireless','Wireless','Radio point-to-point or point-to-multipoint connection.'],
                ['hybrid','Hybrid','Optical backhaul with copper or wireless distribution.'],
            ],
            'protocol'=>[
                ['pppoe','PPPoE','Username/password subscriber authentication.'],
                ['static_ip','Static IP','Manually assigned subscriber IP address.'],
                ['dhcp','DHCP','Dynamic IP assignment.'],
                ['hotspot','Hotspot','Captive portal authentication.'],
                ['radius','RADIUS','Centralized AAA authentication.'],
            ],
            'customer_type'=>[
                ['home','Home','Residential subscriber.'],
                ['commercial','Commercial','Commercial premises.'],
                ['Corporate','Corporate','Corporate customer.'],
                ['business','Business','Business subscriber.'],
                ['reseller','Reseller','Wholesale/reseller customer.'],
            ],
            'billing_status'=>[
                ['pending','Pending','New service awaiting activation.'],
                ['active','Active','Service currently active.'],
                ['inactive','Inactive','Service disabled or inactive.'],
                ['expired','Expired','Billing validity expired.'],
                ['left','Left Customer','Customer service permanently closed.'],
            ],
            'bandwidth_type'=>[
                ['shared','Shared','Shared access bandwidth.'],
                ['dedicated','Dedicated','Dedicated bandwidth service.'],
            ],
        ];
        foreach($defaults as $category=>$options) foreach($options as [$code,$name,$description]) $rows[]=[
            'category'=>$category,'code'=>$code,'name'=>$name,'description'=>$description,
            'is_active'=>true,'is_system'=>true,'created_at'=>$now,'updated_at'=>$now,
        ];
        DB::table('company_service_options')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_service_options');
    }
};
