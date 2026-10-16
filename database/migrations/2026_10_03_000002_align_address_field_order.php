<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $order = [
            'Present Address' => 10,
            'Floor / Flat' => 20,
            'Road' => 30,
            'House' => 40,
            'Area' => 50,
            'Zone' => 60,
            'District' => 70,
            'Thana / Upazila' => 80,
            'Other Thana / Upazila' => 90,
            'POP' => 100,
            'Distribution Location Point' => 110,
            'Network Location' => 120,
        ];

        foreach ($order as $label => $position) {
            DB::table('address_fields')
                ->where('label', $label)
                ->update([
                    'order' => $position,
                    'receipt_order' => $position,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Ordering-only migration; no destructive rollback required.
    }
};
