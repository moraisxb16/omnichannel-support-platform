<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            $table->string('presence', 16)->default('offline');

            // The hard ceiling on concurrent conversations. Per agent rather
            // than global: a senior and a trainee do not absorb the same load,
            // and one number for everyone means tuning for the weakest.
            $table->unsignedSmallInteger('capacity')->default(5);

            // Round-robin tie-break. Null means never assigned anything, which
            // sorts first so a new agent gets work before anyone repeats.
            $table->timestamp('last_assigned_at')->nullable();

            // Feeds the presence circuit: a loss is only believed once the gap
            // since this timestamp exceeds the grace period.
            $table->timestamp('last_seen_at')->nullable();

            $table->json('skills')->nullable();

            $table->timestamps();

            // The distributor filters on presence, then capacity.
            $table->index(['presence', 'capacity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
