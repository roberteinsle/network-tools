<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Ab welchem Status die Empfänger des Kunden eine E-Mail bekommen; „all“ = bisheriges Verhalten
            $table->string('notify_level', 10)->default('all')->after('notification_emails');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('notify_level');
        });
    }
};
