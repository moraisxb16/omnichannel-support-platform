<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            $table->string('direction', 8); // inbound | outbound

            /**
             * The provider's own message id, and the reason this table is
             * idempotent.
             *
             * Channel providers redeliver webhooks on purpose — a delivery that
             * did not get a 200 fast enough comes again. Without this
             * constraint the customer sees their message twice in the thread
             * and the agent answers it twice.
             *
             * Nullable because outbound messages originate here and have no
             * provider id until the send is acknowledged.
             */
            $table->string('provider_message_id', 128)->nullable()->unique();

            $table->string('author')->nullable();
            $table->text('body');
            $table->string('send_kind', 16)->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
