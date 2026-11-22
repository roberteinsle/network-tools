<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Wo die Domain gekauft wurde / wo das DNS verwaltet wird; leer = automatisch (RDAP bzw. Nameserver)
            $table->string('registrar', 100)->nullable()->after('customer_id');
            $table->string('dns_provider', 100)->nullable()->after('registrar');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['registrar', 'dns_provider']);
        });
    }
};
