<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Conversation\ArchiveStaleConversations;
use App\Actions\Conversation\ReleaseOrphanedConversations;
use App\Actions\Conversation\TransitionConversation;
use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Presence\UpdateAgentPresence;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sweeps, and the limbo they exist to prevent: a conversation owned by an
 * agent who is gone is invisible to the distributor and unclaimed by anyone.
 */
final class PresenceAndSweepsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_conversation_held_by_an_offline_agent_returns_to_the_queue(): void
    {
        $alice = $this->agent('Alice');
        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();
        self::assertSame($alice->id, $conversation->agent_id);

        $alice->update(['presence' => PresenceState::OFFLINE]);

        $result = app(ReleaseOrphanedConversations::class)->handle();

        self::assertSame(1, $result['released']);
        $conversation = $conversation->fresh();
        self::assertSame(ConversationStatus::QUEUED, $conversation->status);
        self::assertNull($conversation->agent_id);
    }

    /**
     * AWAY is not OFFLINE. Reclaiming from someone at lunch churns the inbox
     * and throws away the context they had built up.
     */
    #[Test]
    public function an_away_agent_keeps_their_conversations(): void
    {
        $alice = $this->agent('Alice');
        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $alice->update(['presence' => PresenceState::AWAY]);

        $result = app(ReleaseOrphanedConversations::class)->handle();

        self::assertSame(0, $result['released']);
        self::assertSame($alice->id, Conversation::first()->agent_id);
    }

    #[Test]
    public function a_released_conversation_is_picked_up_by_somebody_else(): void
    {
        $alice = $this->agent('Alice');
        $bruno = $this->agent('Bruno', presence: PresenceState::OFFLINE);

        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $alice->update(['presence' => PresenceState::OFFLINE]);
        $bruno->update(['presence' => PresenceState::ONLINE]);

        app(ReleaseOrphanedConversations::class)->handle();
        app(RouteQueuedConversations::class)->handle();

        self::assertSame($bruno->id, Conversation::first()->agent_id);
    }

    #[Test]
    public function the_sweep_records_why_it_took_the_conversation_back(): void
    {
        $alice = $this->agent('Alice');
        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $alice->update(['presence' => PresenceState::OFFLINE]);
        app(ReleaseOrphanedConversations::class)->handle();

        $event = Conversation::first()->events()->latest('id')->first();

        self::assertSame('presence-sweep', $event->actor);
        self::assertStringContainsString('offline', $event->reason);
        self::assertSame($alice->id, $event->context['previous_agent_id']);
    }

    #[Test]
    public function a_transient_disconnection_does_not_release_anything(): void
    {
        $alice = $this->agent('Alice');
        $alice->update(['last_seen_at' => now()]);

        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $result = app(UpdateAgentPresence::class)->handle($alice->fresh(), PresenceState::OFFLINE);

        self::assertFalse($result['applied']);
        self::assertSame(PresenceState::ONLINE, $alice->fresh()->presence);

        // And so the sweep finds nothing to do.
        self::assertSame(0, app(ReleaseOrphanedConversations::class)->handle()['released']);
        self::assertSame(ConversationStatus::ASSIGNED, Conversation::first()->status);
    }

    #[Test]
    public function a_sustained_disconnection_is_believed_and_then_swept(): void
    {
        $alice = $this->agent('Alice');
        $alice->update(['last_seen_at' => now()]);

        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        $this->travel(5)->minutes();

        $result = app(UpdateAgentPresence::class)->handle($alice->fresh(), PresenceState::OFFLINE);
        self::assertTrue($result['applied']);

        self::assertSame(1, app(ReleaseOrphanedConversations::class)->handle()['released']);
        self::assertSame(ConversationStatus::QUEUED, Conversation::first()->status);
    }

    /**
     * An abandoned conversation keeps counting against its agent's ceiling, so
     * the distributor quietly under-uses them until something closes it.
     */
    #[Test]
    public function a_conversation_waiting_on_a_silent_customer_is_eventually_archived(): void
    {
        $this->agent('Alice');
        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        app(TransitionConversation::class)->handle(
            Conversation::first(), ConversationStatus::WAITING_CUSTOMER, 'agent:Alice',
        );

        self::assertSame(0, app(ArchiveStaleConversations::class)->handle()['waiting_closed']);

        $this->travel(8)->days();

        $result = app(ArchiveStaleConversations::class)->handle();
        self::assertSame(1, $result['waiting_closed']);
        self::assertSame(ConversationStatus::ARCHIVED, Conversation::first()->status);
    }

    #[Test]
    public function a_resolved_conversation_is_archived_once_it_can_no_longer_be_reopened(): void
    {
        $this->agent('Alice');
        $this->inbound('customer-1');
        app(RouteQueuedConversations::class)->handle();

        app(TransitionConversation::class)->handle(
            Conversation::first(), ConversationStatus::RESOLVED, 'agent:Alice',
        );

        self::assertSame(0, app(ArchiveStaleConversations::class)->handle()['resolved_archived']);

        $this->travel(4)->days();

        self::assertSame(1, app(ArchiveStaleConversations::class)->handle()['resolved_archived']);
        self::assertSame(ConversationStatus::ARCHIVED, Conversation::first()->status);
    }

    private function agent(string $name, PresenceState $presence = PresenceState::ONLINE): Agent
    {
        return Agent::create([
            'name' => $name,
            'email' => strtolower($name) . '@beacon.invalid',
            'presence' => $presence,
            'capacity' => 5,
            'last_seen_at' => now(),
            'skills' => [],
        ]);
    }

    private function inbound(string $customerRef): void
    {
        app(RecordInboundMessage::class)->handle('widget', $customerRef, 'Hello', 'prov-' . $customerRef);
    }
}
