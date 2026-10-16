<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dropdownFields = [
            'Zone',
            'District',
            'Thana / Upazila',
            'Other Thana / Upazila',
            'POP',
            'Distribution Location Point',
            'Network Location',
        ];

        foreach ($dropdownFields as $label) {
            $row = DB::table('address_fields')->where('label', $label)->first();
            if (! $row) {
                continue;
            }

            $options = json_decode($row->dropdown_list ?: '[]', true);
            if (! is_array($options)) {
                $options = [];
            }

            if ($label === 'Distribution Location Point' && empty($options)) {
                $options = ['DC', 'NOC', 'POP'];
            }

            DB::table('address_fields')->where('id', $row->id)->update([
                'input_type' => 'dropdown',
                'dropdown_list' => json_encode(array_values($options)),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Keep the configured option lists; only revert the UI type.
        DB::table('address_fields')
            ->whereIn('label', [
                'Zone', 'District', 'Thana / Upazila', 'Other Thana / Upazila',
                'POP', 'Distribution Location Point', 'Network Location',
            ])
            ->update(['input_type' => 'text', 'updated_at' => now()]);
    }
};
