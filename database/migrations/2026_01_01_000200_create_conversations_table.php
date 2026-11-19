<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();

            // Our own public identifier, shown to customers and agents. Unique
            // so a duplicated inbound thread collides here instead of creating
            // a second conversation for the same customer on the same channel.
            $table->string('reference', 32)->unique();

            $table->string('channel', 24);
            $table->string('status', 24);

            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();

            $table->string('customer_ref', 64);
            $table->string('customer_name')->nullable();
            $table->string('subject')->nullable();
            $table->string('required_skill', 32)->nullable();

            // Drives the reply window. The clock runs from the customer's LAST
            // message, not the first: every inbound message reopens it.
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_agent_message_at')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Why the last routing attempt found nobody. Stored so a stalled
            // queue explains itself instead of being inferred from an empty
            // inbox: "nobody online" and "everyone at capacity" are different
            // problems with different fixes.
            $table->string('no_agent_reason', 32)->nullable();
            $table->unsignedInteger('routing_attempts')->default(0);

            $table->timestamps();

            // The distributor's query: queued conversations, oldest first.
            $table->index(['status', 'queued_at']);
            // The sweeps: find conversations held by a given agent.
            $table->index(['agent_id', 'status']);
            // Inactivity sweep.
            $table->index(['status', 'last_customer_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
