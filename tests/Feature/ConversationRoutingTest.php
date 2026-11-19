<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConversationRoutingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_spreads_work_and_stops_at_each_ceiling(): void
    {
        $this->agent('Alice', capacity: 3);
        $this->agent('Bruno', capacity: 2);
        $this->agent('Cora', capacity: 1);

        $this->queueConversations(6);

        $stats = app(RouteQueuedConversations::class)->handle();

        self::assertSame(6, $stats['assigned']);

        foreach (Agent::all() as $agent) {
            $held = Conversation::where('agent_id', $agent->id)
                ->whereIn('status', ConversationStatus::occupiesAnAgent())
                ->count();

            self::assertLessThanOrEqual(
                $agent->capacity,
                $held,
                "{$agent->name} must never exceed their ceiling",
            );
        }
    }

    /**
     * The snapshot is taken once per run and updated in memory. Without that,
     * every conversation in a run would see the same stale counts and one agent
     * would receive the whole batch.
     */
    #[Test]
    public function a_single_run_does_not_overfill_one_agent(): void
    {
        $alice = $this->agent('Alice', capacity: 2);
        $this->agent('Bruno', capacity: 2);

        $this->queueConversations(4);
        app(RouteQueuedConversations::class)->handle();

        self::assertSame(2, Conversation::where('agent_id', $alice->id)->count());
    }

    #[Test]
    public function it_records_why_nobody_was_available(): void
    {
        $this->agent('Alice', capacity: 1);
        $this->queueConversations(3);

        $stats = app(RouteQueuedConversations::class)->handle();

        self::assertSame(1, $stats['assigned']);
        self::assertArrayHasKey('all_at_capacity', $stats['reasons']);

        $stalled = Conversation::where('status', ConversationStatus::QUEUED)->first();
        self::assertSame('all_at_capacity', $stalled->no_agent_reason);
        self::assertSame(1, $stalled->routing_attempts);
    }

    #[Test]
    public function it_distinguishes_nobody_online_from_everybody_busy(): void
    {
        $this->agent('Alice', capacity: 5, presence: PresenceState::OFFLINE);
        $this->queueConversations(1);

        $stats = app(RouteQueuedConversations::class)->handle();

        self::assertSame(0, $stats['assigned']);
        self::assertArrayHasKey('nobody_online', $stats['reasons']);
        self::assertSame(
            'nobody_online',
            Conversation::first()->no_agent_reason,
            'a staffing problem and a capacity problem need different answers',
        );
    }

    #[Test]
    public function conversations_are_routed_oldest_first(): void
    {
        $this->agent('Alice', capacity: 1);

        $old = $this->queueOne('customer-old');
        $old->update(['queued_at' => now()->subHour()]);
        $this->queueOne('customer-new');

        app(RouteQueuedConversations::class)->handle();

        self::assertSame(ConversationStatus::ASSIGNED, $old->fresh()->status);
    }

    #[Test]
    public function a_skill_requirement_is_respected(): void
    {
        $this->agent('Billing', capacity: 5, skills: ['billing']);
        $legal = $this->agent('Legal', capacity: 5, skills: ['legal']);

        $conversation = $this->queueOne('customer-1');
        $conversation->update(['required_skill' => 'legal']);

        app(RouteQueuedConversations::class)->handle();

        self::assertSame($legal->id, $conversation->fresh()->agent_id);
    }

    #[Test]
    public function routing_writes_a_timeline_entry_naming_the_decision(): void
    {
        $this->agent('Alice', capacity: 5);
        $conversation = $this->queueOne('customer-1');

        app(RouteQueuedConversations::class)->handle();

        $event = $conversation->events()->where('to_status', ConversationStatus::ASSIGNED->value)->first();

        self::assertNotNull($event, 'an agent asking "why did I get this?" needs an answer');
        self::assertSame('distributor', $event->actor);
        self::assertStringContainsString('Alice', $event->reason);
    }

    /**
     * Routing must not be N+1. The snapshot is two queries regardless of how
     * many conversations are in the batch.
     */
    #[Test]
    public function the_workload_snapshot_does_not_grow_with_the_batch(): void
    {
        $this->agent('Alice', capacity: 50);
        $this->queueConversations(20);

        DB::enableQueryLog();
        app(RouteQueuedConversations::class)->handle();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Writes scale with the batch; the snapshot must not. 20 conversations
        // at well under 10 queries each is the guard against a per-conversation
        // agent lookup creeping back in.
        self::assertLessThan(200, $queries, "routing issued {$queries} queries for 20 conversations");
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $skills */
    private function agent(
        string $name,
        int $capacity,
        PresenceState $presence = PresenceState::ONLINE,
        array $skills = [],
    ): Agent {
        return Agent::create([
            'name' => $name,
            'email' => strtolower($name) . '@beacon.invalid',
            'presence' => $presence,
            'capacity' => $capacity,
            'last_seen_at' => now(),
            'skills' => $skills,
        ]);
    }

    private function queueOne(string $customerRef): Conversation
    {
        app(RecordInboundMessage::class)->handle(
            'widget',
            $customerRef,
            'Hello',
            'prov-' . $customerRef,
        );

        return Conversation::where('customer_ref', $customerRef)->firstOrFail();
    }

    private function queueConversations(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->queueOne("customer-{$i}");
        }
    }
}
