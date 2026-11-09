<?php

use Illuminate\Database\Migrations\Migration;

// Legacy Staging migration. The canonical PON-scoped index is maintained by
// 2026_11_08_230000_scope_olt_onu_identity_by_pon.php. Keep this marker because
// the Staging database already records this migration as applied.
return new class extends Migration {
    public function up(): void {}
    public function down(): void {}
};
