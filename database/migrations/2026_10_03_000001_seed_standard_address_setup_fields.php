<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $fields = [
            ['label' => 'Present Address', 'input_type' => 'textarea', 'dropdown_list' => null, 'order' => 10],
            ['label' => 'Floor / Flat', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 20],
            ['label' => 'House', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 30],
            ['label' => 'Road', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 40],
            ['label' => 'Area', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 50],
            ['label' => 'Zone', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 60],
            ['label' => 'District', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 70],
            ['label' => 'Thana / Upazila', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 80],
            ['label' => 'Other Thana / Upazila', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 90],
            ['label' => 'POP', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 100],
            ['label' => 'Distribution Location Point', 'input_type' => 'dropdown', 'dropdown_list' => json_encode(['DC', 'NOC', 'POP']), 'order' => 110],
            ['label' => 'Network Location', 'input_type' => 'text', 'dropdown_list' => null, 'order' => 120],
        ];

        foreach ($fields as $field) {
            DB::table('address_fields')->updateOrInsert(
                ['label' => $field['label']],
                [
                    'input_type' => $field['input_type'],
                    'dropdown_list' => $field['dropdown_list'],
                    'required' => false,
                    'print_preview' => false,
                    'complain_preview' => false,
                    'order' => $field['order'],
                    'receipt_order' => $field['order'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('address_fields')
            ->whereIn('label', [
                'Floor / Flat', 'House', 'Road', 'Area', 'Zone', 'District',
                'Thana / Upazila', 'Other Thana / Upazila', 'POP',
                'Distribution Location Point',
            ])
            ->delete();
    }
};
