<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Messaging\SendAgentReply;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Messaging\ReplyWindow;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ReplyWindowEnforcementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_agent_can_reply_freely_inside_the_window(): void
    {
        $conversation = $this->assignedConversation();

        $message = app(SendAgentReply::class)->handle($conversation, 'Alice', 'Here is your answer.');

        self::assertSame('free_form', $message->send_kind);
        self::assertSame(1, Message::where('direction', Message::OUTBOUND)->count());
    }

    /**
     * The reason this is enforced here rather than discovered at the provider:
     * otherwise the message is already in our thread, the agent believes they
     * answered, and the customer never heard from anyone.
     */
    #[Test]
    public function free_text_is_refused_once_the_window_closes(): void
    {
        $conversation = $this->assignedConversation();

        $this->travel(25)->hours();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the 24-hour reply window/');

        app(SendAgentReply::class)->handle($conversation->fresh(), 'Alice', 'Following up!');
    }

    #[Test]
    public function nothing_is_stored_when_a_send_is_refused(): void
    {
        $conversation = $this->assignedConversation();
        $this->travel(25)->hours();

        try {
            app(SendAgentReply::class)->handle($conversation->fresh(), 'Alice', 'Following up!');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(
            0,
            Message::where('direction', Message::OUTBOUND)->count(),
            'a refused message must not appear in the thread',
        );
    }

    /** The window restricts free text, not contact. */
    #[Test]
    public function an_approved_template_still_goes_out(): void
    {
        $conversation = $this->assignedConversation();
        $this->travel(25)->hours();

        $message = app(SendAgentReply::class)->handle(
            $conversation->fresh(),
            'Alice',
            'TEMPLATE: order_update',
            isTemplate: true,
        );

        self::assertSame('template_only', $message->send_kind);
    }

    /**
     * Keeping it ASSIGNED would make the agent look busy while they are in fact
     * waiting, and the distributor would under-use them for as long as the
     * customer takes.
     */
    #[Test]
    public function replying_moves_the_ball_to_the_customer(): void
    {
        $conversation = $this->assignedConversation();

        app(SendAgentReply::class)->handle($conversation, 'Alice', 'Answered.');

        self::assertSame(ConversationStatus::WAITING_CUSTOMER, $conversation->fresh()->status);
    }

    #[Test]
    public function an_archived_conversation_cannot_receive_a_reply(): void
    {
        $conversation = $this->assignedConversation();
        $conversation->update(['status' => ConversationStatus::ARCHIVED]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/archived/');

        app(SendAgentReply::class)->handle($conversation->fresh(), 'Alice', 'Hello?');
    }

    /**
     * The window is injected, not hard-coded, so a channel with a different
     * rule is a configuration change rather than a code change.
     */
    #[Test]
    public function the_window_length_comes_from_configuration(): void
    {
        $conversation = $this->assignedConversation();

        // Swap the singleton rather than rebuilding the application: a refresh
        // would drop the in-memory database along with it.
        $this->app->instance(ReplyWindow::class, new ReplyWindow(windowHours: 2));

        $this->travel(3)->hours();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outside the 2-hour reply window/');

        app(SendAgentReply::class)->handle($conversation->fresh(), 'Alice', 'Hello?');
    }

    private function assignedConversation(): Conversation
    {
        Agent::create([
            'name' => 'Alice',
            'email' => 'alice@beacon.invalid',
            'presence' => PresenceState::ONLINE,
            'capacity' => 5,
            'last_seen_at' => now(),
            'skills' => [],
        ]);

        app(RecordInboundMessage::class)->handle('widget', 'customer-1', 'Question', 'prov-1');
        app(RouteQueuedConversations::class)->handle();

        return Conversation::firstOrFail();
    }
}
