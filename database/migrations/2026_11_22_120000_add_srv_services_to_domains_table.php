<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Erwartete SRV-Dienste („_dienst._protokoll“); fehlt einer, warnt der SRV-Check
            $table->json('srv_services')->nullable()->after('extra_ips');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('srv_services');
        });
    }
};
