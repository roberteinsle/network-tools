<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('zone')->unique();
            $table->string('type', 10)->default('ip');
            $table->boolean('enabled')->default(true);
            $table->string('info_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Kleine Auswahl kostenloser RBLs. Spamhaus bewusst NICHT hier:
        // Spamhaus nur über DQS (Schlüssel in den Einstellungen), sonst lizenzwidrig.
        $now = now();
        DB::table('blacklists')->insert([
            ['name' => 'SpamCop', 'zone' => 'bl.spamcop.net', 'type' => 'ip', 'enabled' => true, 'info_url' => 'https://www.spamcop.net/bl.shtml', 'description' => 'Kostenlos, kurzlebige Listungen.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'PSBL (Passive Spam Block List)', 'zone' => 'psbl.surriel.com', 'type' => 'ip', 'enabled' => true, 'info_url' => 'https://psbl.org/', 'description' => 'Kostenlos.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Mailspike Blacklist', 'zone' => 'bl.mailspike.net', 'type' => 'ip', 'enabled' => true, 'info_url' => 'https://mailspike.org/', 'description' => 'Kostenlos bei geringem Abfragevolumen.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'UCEPROTECT Level 1', 'zone' => 'dnsbl-1.uceprotect.net', 'type' => 'ip', 'enabled' => true, 'info_url' => 'https://www.uceprotect.net/', 'description' => 'Kostenlos bei geringem Volumen. Nur Level 1 (einzelne IP).', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Barracuda BRBL', 'zone' => 'b.barracudacentral.org', 'type' => 'ip', 'enabled' => false, 'info_url' => 'https://www.barracudacentral.org/rbl', 'description' => 'Kostenlos, aber die IP des abfragenden DNS-Servers muss bei Barracuda registriert sein.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'SURBL multi', 'zone' => 'multi.surbl.org', 'type' => 'domain', 'enabled' => true, 'info_url' => 'https://www.surbl.org/', 'description' => 'Domain-Liste, kostenlos bei geringem Volumen. Antwort 127.0.0.1 = Abfrage blockiert.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'URIBL multi', 'zone' => 'multi.uribl.com', 'type' => 'domain', 'enabled' => true, 'info_url' => 'https://uribl.com/', 'description' => 'Domain-Liste, kostenlos bei geringem Volumen. Blockiert öffentliche Resolver (Antwort 127.0.0.1).', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};
