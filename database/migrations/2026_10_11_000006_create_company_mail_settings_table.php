<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('company_mail_settings')) return;

        Schema::create('company_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('mailer', 20)->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 12)->default('tls');
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('from_address');
            $table->string('from_name');
            $table->string('reply_to')->nullable();
            $table->text('signature')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_mail_settings');
    }
};
