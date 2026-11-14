<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('check_interval_minutes')->default(360);
            $table->boolean('inherit_dkim_providers')->default(true);
            $table->json('dkim_providers')->nullable();
            $table->json('dkim_selectors')->nullable();
            $table->json('web_hosts')->nullable();
            $table->json('extra_ips')->nullable();
            $table->boolean('check_mx_tls')->default(false);
            $table->boolean('notify_ip_changes')->default(false);
            $table->json('disabled_checks')->nullable();
            $table->json('notification_emails')->nullable();
            $table->text('notes')->nullable();
            $table->string('last_status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
