<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Conversation\TransitionConversation;
use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InboundMessageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_redelivered_message_is_stored_once(): void
    {
        $record = app(RecordInboundMessage::class);

        $first = $record->handle('widget', 'customer-1', 'Where is my order?', 'prov-1');
        self::assertFalse($first['duplicate']);

        foreach (range(1, 3) as $_) {
            $repeat = $record->handle('widget', 'customer-1', 'Where is my order?', 'prov-1');
            self::assertTrue($repeat['duplicate']);
        }

        self::assertSame(1, Message::count());
        self::assertSame(1, Conversation::count());
    }

    /**
     * A duplicate must not re-run the side effects either. Reopening the window
     * on a redelivery would silently extend it past what the channel allows.
     */
    #[Test]
    public function a_duplicate_does_not_reopen_the_reply_window(): void
    {
        $record = app(RecordInboundMessage::class);
        $record->handle('widget', 'customer-1', 'Hello', 'prov-1');

        $original = Conversation::first()->last_customer_message_at;

        $this->travel(2)->hours();
        $record->handle('widget', 'customer-1', 'Hello', 'prov-1');

        self::assertTrue(
            $original->equalTo(Conversation::first()->last_customer_message_at),
            'the window must not move on a redelivery',
        );
    }

    #[Test]
    public function messages_from_the_same_customer_join_the_same_conversation(): void
    {
        $record = app(RecordInboundMessage::class);

        $record->handle('widget', 'customer-1', 'First', 'prov-1');
        $record->handle('widget', 'customer-1', 'Second', 'prov-2');

        self::assertSame(1, Conversation::count());
        self::assertSame(2, Message::count());
    }

    #[Test]
    public function the_same_customer_on_a_different_channel_is_a_different_conversation(): void
    {
        $record = app(RecordInboundMessage::class);

        $record->handle('widget', 'customer-1', 'From the website', 'prov-1');
        $record->handle('email', 'customer-1', 'And by email', 'prov-2');

        self::assertSame(2, Conversation::count());
    }

    /**
     * Someone replying to say the answer did not work belongs in the same
     * thread. A new conversation leaves whoever picks it up without the history.
     */
    #[Test]
    public function a_reply_after_resolution_reopens_the_same_conversation(): void
    {
        $this->agent('Alice');
        $record = app(RecordInboundMessage::class);

        $record->handle('widget', 'customer-1', 'My order is late', 'prov-1');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();
        app(TransitionConversation::class)->handle(
            $conversation, ConversationStatus::RESOLVED, 'agent:Alice', 'answered',
        );

        $result = $record->handle('widget', 'customer-1', 'That did not help', 'prov-2');

        self::assertTrue($result['reopened']);
        self::assertSame(1, Conversation::count());
        self::assertSame(ConversationStatus::QUEUED, $conversation->fresh()->status);
    }

    /**
     * The ball comes back to the agent who already owns it rather than to the
     * pool: they have the context, and bouncing it to whoever is free throws
     * that away.
     */
    #[Test]
    public function a_reply_while_waiting_returns_to_the_same_agent(): void
    {
        $alice = $this->agent('Alice');
        $record = app(RecordInboundMessage::class);

        $record->handle('widget', 'customer-1', 'Question', 'prov-1');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();
        app(TransitionConversation::class)->handle(
            $conversation, ConversationStatus::WAITING_CUSTOMER, 'agent:Alice',
        );

        $record->handle('widget', 'customer-1', 'Following up', 'prov-2');

        $conversation = $conversation->fresh();
        self::assertSame(ConversationStatus::ASSIGNED, $conversation->status);
        self::assertSame($alice->id, $conversation->agent_id);
    }

    #[Test]
    public function an_inbound_message_reopens_the_reply_window(): void
    {
        $record = app(RecordInboundMessage::class);
        $record->handle('widget', 'customer-1', 'First', 'prov-1');

        $this->travel(20)->hours();
        self::assertSame('free_form', Conversation::first()->allowedSendKind()->value);

        $this->travel(10)->hours();
        self::assertSame('template_only', Conversation::first()->fresh()->allowedSendKind()->value);

        $record->handle('widget', 'customer-1', 'Still waiting', 'prov-2');
        self::assertSame(
            'free_form',
            Conversation::first()->fresh()->allowedSendKind()->value,
            'the clock runs from the LAST customer message',
        );
    }

    private function agent(string $name): Agent
    {
        return Agent::create([
            'name' => $name,
            'email' => strtolower($name) . '@beacon.invalid',
            'presence' => PresenceState::ONLINE,
            'capacity' => 5,
            'last_seen_at' => now(),
            'skills' => [],
        ]);
    }
}
