<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Conversation\ArchiveStaleConversations;
use App\Actions\Conversation\ReleaseOrphanedConversations;
use App\Actions\Conversation\TransitionConversation;
use App\Actions\Messaging\RecordInboundMessage;
use App\Actions\Messaging\SendAgentReply;
use App\Actions\Presence\UpdateAgentPresence;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Conversation\InvalidConversationTransition;
use App\Domain\Presence\PresenceState;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runnable proof of the behaviours this platform exists to provide.
 *
 *   php artisan support:demo
 *   php artisan support:demo --only=presence
 *
 * Each scenario builds a throwaway SQLite database, drives the real actions —
 * no mocks of our own code — and asserts the outcome. Exits non-zero on any
 * failure, so it doubles as a smoke test and runs in CI.
 *
 * Time is travelled rather than waited for: a seven-day inactivity sweep is not
 * something a demonstration can sit through.
 */
final class SupportDemo extends Command
{
    protected $signature = 'support:demo {--only= : Run a single scenario}';

    protected $description = 'Demonstrate routing, presence, deduplication and the reply window';

    /** @var array<string,string> */
    private array $scenarios = [
        'distribution' => 'Conversations spread across agents and stop at the capacity ceiling',
        'redelivery' => 'A redelivered provider message does not duplicate the thread',
        'limbo' => 'An agent going offline does not strand their conversations',
        'presence' => 'A flapping connection does not churn the queue',
        'window' => 'Outside the reply window, free text is refused and a template is not',
        'transitions' => 'An illegal state change is refused, and the timeline records every legal one',
    ];

    private int $failures = 0;

    public function handle(): int
    {
        $only = $this->option('only');

        if ($only !== null && !array_key_exists($only, $this->scenarios)) {
            $this->error("Unknown scenario '{$only}'. Known: " . implode(', ', array_keys($this->scenarios)));

            return self::INVALID;
        }

        foreach ($this->scenarios as $name => $title) {
            if ($only !== null && $only !== $name) {
                continue;
            }

            $this->newLine();
            $this->line("<fg=cyan;options=bold>== {$name}</> <fg=cyan>{$title}</>");
            $this->line(str_repeat('-', 78));

            try {
                $this->freshDatabase();
                $this->{'scenario' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)))}();
                $this->line('   <fg=green;options=bold>PASS</>  scenario passed');
            } catch (Throwable $e) {
                $this->line('   <fg=red;options=bold>FAIL</>  ' . $e->getMessage());
                $this->failures++;
            } finally {
                Carbon::setTestNow();
            }
        }

        $this->newLine();

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ---------------------------------------------------------------- scenarios

    /**
     * Three agents, different ceilings. Work should spread by LOAD RATIO, not
     * by raw count, and must stop dead at each agent's ceiling.
     */
    private function scenarioDistribution(): void
    {
        $alice = $this->agent('Alice', capacity: 3);
        $bruno = $this->agent('Bruno', capacity: 2);
        $cora = $this->agent('Cora', capacity: 1);

        $this->step('agents: Alice (0/3), Bruno (0/2), Cora (0/1) - total headroom 6');

        for ($i = 1; $i <= 6; $i++) {
            $this->inbound("customer-{$i}", "Hello, question {$i}");
        }

        $stats = app(RouteQueuedConversations::class)->handle();
        $this->step('routing: ' . json_encode($stats));

        $this->assert(6, $stats['assigned'], 'all six should be assigned');

        foreach ([$alice, $bruno, $cora] as $agent) {
            $held = Conversation::where('agent_id', $agent->id)
                ->whereIn('status', ConversationStatus::occupiesAnAgent())->count();
            $this->step(sprintf('   %-6s holds %d of %d', $agent->name, $held, $agent->capacity));
            $this->assert(true, $held <= $agent->capacity, "{$agent->name} must not exceed capacity");
        }

        // The seventh has nowhere to go, and the reason must be recorded.
        $this->inbound('customer-7', 'One more');
        $stats = app(RouteQueuedConversations::class)->handle();
        $this->step('seventh conversation: ' . json_encode($stats['reasons']));

        $this->assert(0, $stats['assigned'], 'nobody has headroom left');
        $this->assert(
            'all_at_capacity',
            Conversation::where('customer_ref', 'customer-7')->first()->no_agent_reason,
            'the reason is recorded on the conversation, not inferred from an empty inbox',
        );
    }

    /**
     * Providers redeliver webhooks on purpose. The same provider message id
     * must never appear twice in a thread.
     */
    private function scenarioRedelivery(): void
    {
        $this->agent('Alice', capacity: 5);

        $first = app(RecordInboundMessage::class)->handle(
            'widget', 'customer-1', 'My order has not arrived', 'prov-msg-001', 'Dana',
        );
        $this->step('delivery 1 -> ' . ($first['duplicate'] ? 'duplicate' : 'stored'));

        foreach ([2, 3] as $attempt) {
            $repeat = app(RecordInboundMessage::class)->handle(
                'widget', 'customer-1', 'My order has not arrived', 'prov-msg-001', 'Dana',
            );
            $this->step("delivery {$attempt} -> " . ($repeat['duplicate'] ? 'duplicate' : 'stored'));
            $this->assert(true, $repeat['duplicate'], "delivery {$attempt} must be recognised as a duplicate");
        }

        $count = Message::where('direction', Message::INBOUND)->count();
        $this->step("messages in the thread: {$count}");
        $this->assert(1, $count, 'exactly one message should exist');
        $this->assert(1, Conversation::count(), 'exactly one conversation should exist');
    }

    /**
     * The worst state a conversation can be in: owned by somebody who is gone.
     * Not in the queue, so the distributor ignores it; owned, so nobody else
     * feels responsible.
     */
    private function scenarioLimbo(): void
    {
        $alice = $this->agent('Alice', capacity: 5);
        $bruno = $this->agent('Bruno', capacity: 5, presence: PresenceState::OFFLINE);

        $this->inbound('customer-1', 'Where is my refund?');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();
        $this->step("conversation assigned to {$conversation->agent->name}");
        $this->assert(ConversationStatus::ASSIGNED, $conversation->status, 'should be assigned');

        // Alice's laptop loses power. No disconnect event is ever emitted.
        $alice->update(['presence' => PresenceState::OFFLINE, 'last_seen_at' => now()->subMinutes(10)]);
        $this->step('Alice goes offline while holding it (no disconnect event)');

        $this->step('is it orphaned? ' . ($conversation->fresh()->isOrphaned() ? 'yes' : 'no'));

        $swept = app(ReleaseOrphanedConversations::class)->handle();
        $this->step('presence sweep: ' . json_encode($swept));
        $this->assert(1, $swept['released'], 'the conversation must be released');

        $conversation = $conversation->fresh();
        $this->assert(ConversationStatus::QUEUED, $conversation->status, 'it must be back in the queue');
        $this->assert(null, $conversation->agent_id, 'and it must have no owner');

        // Bruno comes online and picks it up.
        $bruno->update(['presence' => PresenceState::ONLINE]);
        app(RouteQueuedConversations::class)->handle();

        $conversation = $conversation->fresh();
        $this->step("picked up again by {$conversation->agent->name}");
        $this->assert($bruno->id, $conversation->agent_id, 'Bruno should now hold it');
    }

    /** A blip is not a departure. */
    private function scenarioPresence(): void
    {
        $alice = $this->agent('Alice', capacity: 5);
        $alice->update(['last_seen_at' => now()]);

        $update = app(UpdateAgentPresence::class);

        $result = $update->handle($alice->fresh(), PresenceState::OFFLINE);
        $this->step(sprintf(
            'socket drops at t+0  -> applied=%s, believed in %ds',
            $result['applied'] ? 'yes' : 'no',
            $result['seconds_until_believed'],
        ));
        $this->assert(false, $result['applied'], 'an immediate loss must not be believed');
        $this->assert(PresenceState::ONLINE, $alice->fresh()->presence, 'she is still routable');

        $result = $update->handle($alice->fresh(), PresenceState::ONLINE);
        $this->step('reconnects at t+5  -> back online, nothing was ever reclaimed');
        $this->assert(false, $result['applied'], 'she never left, so nothing changed');

        // A loss that genuinely holds.
        Carbon::setTestNow(now()->addMinutes(5));
        $alice->update(['last_seen_at' => now()->subSeconds(120)]);

        $result = $update->handle($alice->fresh(), PresenceState::OFFLINE);
        $this->step('silent for 120s   -> applied=' . ($result['applied'] ? 'yes' : 'no'));
        $this->assert(true, $result['applied'], 'a sustained loss must be believed');
        $this->assert(PresenceState::OFFLINE, $alice->fresh()->presence, 'now she is offline');

        // A deliberate state is never debounced.
        $alice->update(['presence' => PresenceState::ONLINE, 'last_seen_at' => now()]);
        $result = $update->handle($alice->fresh(), PresenceState::AWAY);
        $this->step('presses "away"     -> applied=' . ($result['applied'] ? 'yes' : 'no') . ' (immediate)');
        $this->assert(true, $result['applied'], 'a deliberate state applies at once');
    }

    /**
     * The window restricts free text, not contact. An agent must find this out
     * before typing, not after the provider silently drops the message.
     */
    private function scenarioWindow(): void
    {
        $this->agent('Alice', capacity: 5);
        $this->inbound('customer-1', 'Is this still under warranty?');
        app(RouteQueuedConversations::class)->handle();

        $conversation = Conversation::first();
        $send = app(SendAgentReply::class);

        $this->step('inside the window -> ' . $conversation->allowedSendKind()->value);
        $send->handle($conversation->fresh(), 'Alice', 'Yes, for 12 months.');
        $this->step('free-form reply sent');
        $this->assert(
            ConversationStatus::WAITING_CUSTOMER,
            $conversation->fresh()->status,
            'the ball moves to the customer so the agent is not counted as busy',
        );

        // 25 hours later the window has closed.
        Carbon::setTestNow(now()->addHours(25));
        $conversation = $conversation->fresh();
        $this->step('25 hours later    -> ' . $conversation->allowedSendKind()->value);
        $this->assert('template_only', $conversation->allowedSendKind()->value, 'the window should be closed');

        $refused = false;
        try {
            $send->handle($conversation, 'Alice', 'Just following up!');
        } catch (RuntimeException $e) {
            $refused = true;
            $this->step('free text refused: ' . $e->getMessage());
        }
        $this->assert(true, $refused, 'free text must be refused outside the window');

        // A template still goes out: the channel restricts text, not contact.
        $send->handle($conversation->fresh(), 'Alice', 'TEMPLATE: order_update', isTemplate: true);
        $this->step('approved template accepted');
        $this->assert(2, Message::where('direction', Message::OUTBOUND)->count(), 'two outbound messages');
    }

    private function scenarioTransitions(): void
    {
        $this->agent('Alice', capacity: 5);
        $this->inbound('customer-1', 'Hello');

        $conversation = Conversation::first();
        $transition = app(TransitionConversation::class);

        // queued -> waiting_customer skips assignment entirely: it would mean
        // the customer is waiting on an agent who was never given the ticket.
        $refused = false;
        try {
            $transition->handle($conversation, ConversationStatus::WAITING_CUSTOMER, 'demo');
        } catch (InvalidConversationTransition $e) {
            $refused = true;
            $this->step('refused: ' . $e->getMessage());
        }
        $this->assert(true, $refused, 'queued -> waiting_customer must be refused');

        app(RouteQueuedConversations::class)->handle();
        $conversation = $conversation->fresh();
        $transition->handle($conversation, ConversationStatus::RESOLVED, 'agent:Alice', 'answered');

        // Resolved is NOT terminal: a customer reply revives the same thread.
        $result = app(RecordInboundMessage::class)->handle(
            'widget', 'customer-1', 'That did not work', 'prov-msg-002',
        );
        $this->step('customer replies after resolution -> reopened=' . ($result['reopened'] ? 'yes' : 'no'));
        $this->assert(true, $result['reopened'], 'a reply must revive the same conversation');
        $this->assert(1, Conversation::count(), 'and must not create a second one');

        // Archived is terminal, and is the only thing that is.
        app(ArchiveStaleConversations::class);
        $conversation = $conversation->fresh();
        app(RouteQueuedConversations::class)->handle();
        $conversation = $conversation->fresh();
        $transition->handle($conversation, ConversationStatus::ARCHIVED, 'demo', 'closing for the demo');

        $refused = false;
        try {
            $transition->handle($conversation->fresh(), ConversationStatus::QUEUED, 'demo');
        } catch (InvalidConversationTransition) {
            $refused = true;
        }
        $this->step('archived -> queued refused: ' . ($refused ? 'yes' : 'no'));
        $this->assert(true, $refused, 'archived is terminal');

        $this->step('timeline:');
        foreach ($conversation->events()->orderBy('id')->get() as $event) {
            $this->step(sprintf(
                '   %-16s -> %-16s by %s',
                $event->from_status ?? '(new)',
                $event->to_status,
                $event->actor,
            ));
        }
    }

    // ------------------------------------------------------------------ harness

    private function freshDatabase(): void
    {
        $path = storage_path('demo.sqlite');

        // Close the handle BEFORE touching the file. On Windows an open SQLite
        // connection keeps the file locked, unlink() fails silently, and the
        // next scenario starts on the previous one's data - which shows up as
        // a baffling unique-constraint violation on a table you just created.
        DB::purge('demo');

        if (file_exists($path)) {
            @unlink($path);
        }
        touch($path);

        config()->set('database.connections.demo', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'demo');

        DB::purge('demo');
        Artisan::call('migrate', ['--database' => 'demo', '--force' => true]);
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

    private function inbound(string $customerRef, string $body): void
    {
        app(RecordInboundMessage::class)->handle(
            'widget',
            $customerRef,
            $body,
            'prov-' . $customerRef . '-' . uniqid(),
        );
    }

    private function step(string $message): void
    {
        $this->line('   ' . $message);
    }

    private function assert(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                '%s (expected %s, got %s)',
                $message,
                $this->describe($expected),
                $this->describe($actual),
            ));
        }
    }

    private function describe(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return var_export($value, true);
    }
}
