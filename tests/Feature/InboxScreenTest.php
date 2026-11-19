<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InboxScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_inbox_renders_with_agents_and_conversations(): void
    {
        $this->agent('Alice', capacity: 3);
        $this->inbound('cus-1');
        app(RouteQueuedConversations::class)->handle();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox')
                ->has('agents', 1)
                ->has('conversations', 1)
                ->where('agents.0.name', 'Alice')
                ->where('agents.0.capacity', 3)
                ->where('agents.0.open', 1)
            );
    }

    /**
     * Load is sent to the view as a RATIO, because the bar is the point: a
     * senior at 8 of 12 should look less pressed than a trainee at 3 of 3, and
     * a raw count would draw them the other way round.
     */
    #[Test]
    public function agent_load_is_sent_as_a_ratio(): void
    {
        $this->agent('Trainee', capacity: 2);
        $this->agent('Senior', capacity: 10);

        foreach (range(1, 4) as $i) {
            $this->inbound("cus-{$i}");
        }
        app(RouteQueuedConversations::class)->handle();

        // Sorted by name, so Senior comes first. The numbers are the whole
        // argument: Senior holds THREE conversations and is less loaded than
        // Trainee holding ONE, which a raw count would get exactly backwards.
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('agents.0.name', 'Senior')
            ->where('agents.0.open', 3)
            ->where('agents.0.load', 0.3)
            ->where('agents.1.name', 'Trainee')
            ->where('agents.1.open', 1)
            ->where('agents.1.load', 0.5)
            ->etc()
        );
    }

    /** Headroom is what explains a stalled queue without anyone guessing. */
    #[Test]
    public function the_header_reports_remaining_headroom(): void
    {
        $this->agent('Alice', capacity: 2);
        $this->agent('Offline', capacity: 5, presence: PresenceState::OFFLINE);

        $this->inbound('cus-1');
        app(RouteQueuedConversations::class)->handle();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.headroom', 1)   // Alice has 1 slot left; Offline contributes nothing
            ->where('stats.assigned', 1)
            ->etc()
        );
    }

    #[Test]
    public function a_stalled_conversation_carries_the_reason_to_the_screen(): void
    {
        $this->agent('Alice', capacity: 1);
        $this->inbound('cus-1');
        $this->inbound('cus-2');
        app(RouteQueuedConversations::class)->handle();

        $this->get('/?status=queued')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversations', 1)
            ->where('conversations.0.no_agent_reason', 'all_at_capacity')
            ->etc()
        );
    }

    /**
     * The window state reaches the list, not just the thread, so an agent sees
     * it before opening the conversation and starting to type.
     */
    #[Test]
    public function the_reply_window_state_reaches_the_list(): void
    {
        $this->agent('Alice', capacity: 5);
        $this->inbound('cus-1');
        app(RouteQueuedConversations::class)->handle();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.0.send_kind', 'free_form')
            ->etc()
        );

        $this->travel(25)->hours();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversations.0.send_kind', 'template_only')
            ->etc()
        );
    }

    #[Test]
    public function a_conversation_screen_shows_its_messages_and_timeline(): void
    {
        $this->agent('Alice', capacity: 5);
        $this->inbound('cus-1');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();

        $this->get("/conversations/{$conversation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Conversation')
                ->has('messages', 1)
                // Opened by the customer, then assigned by the distributor.
                ->has('timeline', 2)
                ->where('timeline.0.actor', 'customer')
                ->where('timeline.1.actor', 'distributor')
                ->etc()
            );
    }

    #[Test]
    public function the_distributor_can_be_triggered_from_the_screen(): void
    {
        $this->agent('Alice', capacity: 5);
        $this->inbound('cus-1');

        $this->post('/route')->assertRedirect();

        self::assertNotNull(Conversation::first()->agent_id);
    }

    #[Test]
    public function the_presence_sweep_can_be_triggered_from_the_screen(): void
    {
        $alice = $this->agent('Alice', capacity: 5);
        $this->inbound('cus-1');
        app(RouteQueuedConversations::class)->handle();

        $alice->update(['presence' => PresenceState::OFFLINE]);

        $this->post('/sweep')->assertRedirect();

        self::assertNull(Conversation::first()->agent_id);
    }

    private function agent(string $name, int $capacity, PresenceState $presence = PresenceState::ONLINE): Agent
    {
        return Agent::create([
            'name' => $name,
            'email' => strtolower($name) . '@beacon.invalid',
            'presence' => $presence,
            'capacity' => $capacity,
            'last_seen_at' => now(),
            'skills' => [],
        ]);
    }

    private function inbound(string $ref): void
    {
        app(RecordInboundMessage::class)->handle('widget', $ref, 'Hello', 'prov-' . $ref);
    }
}
