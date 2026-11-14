<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('trigger', 20)->default('schedule');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('changes')->nullable();
            $table->unsignedSmallInteger('changes_count')->default(0);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['domain_id', 'created_at']);
        });

        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->string('check', 40);
            $table->string('status', 20);
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->json('state')->nullable();
            $table->string('fingerprint', 64);
            $table->timestamps();

            $table->index(['domain_id', 'check']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_results');
        Schema::dropIfExists('check_runs');
    }
};
