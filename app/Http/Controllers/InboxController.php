<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Conversation\ReleaseOrphanedConversations;
use App\Actions\Routing\RouteQueuedConversations;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Messaging\ReplyWindow;
use App\Domain\Routing\RoundRobinDistributor;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inbox screen.
 *
 * It shows the three things a supervisor actually needs and most inboxes hide:
 * how loaded each agent is relative to their own ceiling, how much headroom the
 * team has left, and — when the queue is not moving — why.
 */
final class InboxController extends Controller
{
    public function index(Request $request, RoundRobinDistributor $distributor, ReplyWindow $window): Response
    {
        $counts = DB::table('conversations')
            ->select('agent_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('agent_id')
            ->whereIn('status', array_map(
                static fn (ConversationStatus $s): string => $s->value,
                ConversationStatus::occupiesAnAgent(),
            ))
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $agents = Agent::orderBy('name')->get()->map(fn (Agent $agent): array => [
            'id' => $agent->id,
            'name' => $agent->name,
            'presence' => $agent->presence->value,
            'capacity' => $agent->capacity,
            'open' => (int) ($counts[$agent->id] ?? 0),
            'load' => round($agent->toWorkload((int) ($counts[$agent->id] ?? 0))->loadFactor(), 2),
            'routable' => $agent->presence->canReceiveWork(),
        ])->all();

        $workloads = Agent::all()
            ->map(fn (Agent $a) => $a->toWorkload((int) ($counts[$a->id] ?? 0)))
            ->values()
            ->all();

        $status = $request->string('status')->toString();

        $conversations = Conversation::query()
            ->with('agent')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'queued' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Conversation $c): array => [
                'id' => $c->id,
                'reference' => $c->reference,
                'channel' => $c->channel,
                'status' => $c->status->value,
                'subject' => $c->subject,
                'customer' => $c->customer_name ?? $c->customer_ref,
                'agent' => $c->agent?->name,
                'no_agent_reason' => $c->no_agent_reason,
                'waiting_since' => $c->queued_at?->diffForHumans(),
                // Surfaced on the list, not just inside the thread: an agent
                // should see that a window is about to close before they open
                // the conversation and start typing.
                'send_kind' => $window->allowedSendKind(
                    $c->last_customer_message_at?->toDateTimeImmutable(),
                    now()->toDateTimeImmutable(),
                )->value,
                'window_seconds_left' => $window->secondsRemaining(
                    $c->last_customer_message_at?->toDateTimeImmutable(),
                    now()->toDateTimeImmutable(),
                ),
            ])->all();

        return Inertia::render('Inbox', [
            'agents' => $agents,
            'conversations' => $conversations,
            'filter' => $status,
            'stats' => [
                'queued' => Conversation::where('status', ConversationStatus::QUEUED)->count(),
                'assigned' => Conversation::where('status', ConversationStatus::ASSIGNED)->count(),
                'waiting' => Conversation::where('status', ConversationStatus::WAITING_CUSTOMER)->count(),
                'headroom' => $distributor->headroom($workloads),
            ],
        ]);
    }

    /** Run the distributor now, rather than waiting for the next scheduler tick. */
    public function route(RouteQueuedConversations $route): RedirectResponse
    {
        $stats = $route->handle();

        return back()->with('flash', sprintf(
            'Routing run: %d assigned, %d left queued.',
            $stats['assigned'],
            $stats['unassigned'],
        ));
    }

    public function sweep(ReleaseOrphanedConversations $sweep): RedirectResponse
    {
        $result = $sweep->handle();

        return back()->with('flash', sprintf(
            'Presence sweep: %d conversation(s) returned to the queue.',
            $result['released'],
        ));
    }

    public function show(Conversation $conversation, ReplyWindow $window): Response
    {
        $conversation->load(['agent', 'messages' => fn ($q) => $q->orderBy('occurred_at')]);

        return Inertia::render('Conversation', [
            'conversation' => [
                'reference' => $conversation->reference,
                'channel' => $conversation->channel,
                'status' => $conversation->status->value,
                'subject' => $conversation->subject,
                'customer' => $conversation->customer_name ?? $conversation->customer_ref,
                'agent' => $conversation->agent?->name,
                'send_kind' => $conversation->allowedSendKind($window)->value,
                'window_seconds_left' => $conversation->replyWindowSecondsRemaining($window),
            ],
            'messages' => $conversation->messages->map(fn ($m): array => [
                'id' => $m->id,
                'direction' => $m->direction,
                'author' => $m->author,
                'body' => $m->body,
                'at' => $m->occurred_at->format('d M H:i'),
            ])->all(),
            'timeline' => $conversation->events()->orderBy('id')->get()->map(fn ($e): array => [
                'from' => $e->from_status,
                'to' => $e->to_status,
                'actor' => $e->actor,
                'reason' => $e->reason,
                'at' => $e->occurred_at->format('d M H:i'),
            ])->all(),
        ]);
    }
}
