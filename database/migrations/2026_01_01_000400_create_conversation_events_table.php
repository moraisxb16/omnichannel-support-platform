<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The timeline. One row per accepted state change, written in the SAME
     * transaction as the change itself.
     *
     * Same transaction is the point. A timeline entry written after the commit
     * goes missing exactly when the process dies between the two — which is
     * precisely the case anyone investigating actually cares about.
     *
     * It is also the answer to "why did I get this ticket?", which is the most
     * common question an agent asks about a routing system, and one that is
     * impossible to answer from the current state alone.
     */
    public function up(): void
    {
        Schema::create('conversation_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_reference', 32);

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);

            // "customer", "agent:7", "distributor", "inactivity-sweep",
            // "presence-sweep".
            $table->string('actor', 64);
            $table->string('reason')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('occurred_at');

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');
    }
};
