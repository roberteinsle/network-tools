<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->default('tls');
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();
            $table->json('notification_emails')->nullable();
            $table->boolean('notify_on_recovery')->default(true);
            $table->json('dns_resolvers')->nullable();
            $table->json('rbl_resolvers')->nullable();
            $table->text('spamhaus_dqs_key')->nullable();
            $table->unsignedSmallInteger('cert_warning_days')->default(21);
            $table->unsignedSmallInteger('cert_error_days')->default(7);
            $table->unsignedSmallInteger('domain_warning_days')->default(30);
            $table->unsignedSmallInteger('domain_error_days')->default(7);
            $table->unsignedInteger('default_interval_minutes')->default(360);
            $table->unsignedSmallInteger('retention_days')->default(180);
            $table->timestamps();
        });

        Schema::create('magic_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_login_tokens');
        Schema::dropIfExists('app_settings');
    }
};
