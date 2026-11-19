<?php

declare(strict_types=1);

namespace App\Actions\Routing;

use App\Actions\Conversation\TransitionConversation;
use App\Domain\Conversation\ConversationStatus;
use App\Domain\Routing\RoundRobinDistributor;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Assigns queued conversations to agents, oldest first.
 *
 * Runs on a schedule rather than on arrival. Routing on arrival sounds more
 * responsive but handles the empty case badly: a conversation that arrives when
 * everyone is at capacity has to be retried by something, and that something is
 * this loop. Having one path instead of two means the "nobody was available"
 * case is exercised constantly rather than only during an incident.
 *
 * The workload snapshot is taken ONCE per run and updated in memory as
 * assignments are made. Re-querying per conversation would be N+1, and worse,
 * would let an agent receive two conversations in the same run because the
 * count behind the first had not been committed yet.
 */
final class RouteQueuedConversations
{
    public function __construct(
        private readonly RoundRobinDistributor $distributor,
        private readonly TransitionConversation $transition,
    ) {
    }

    /**
     * @return array{considered:int,assigned:int,unassigned:int,reasons:array<string,int>}
     */
    public function handle(int $limit = 50): array
    {
        $queued = Conversation::query()
            ->where('status', ConversationStatus::QUEUED)
            ->orderBy('queued_at')
            ->limit($limit)
            ->get();

        $stats = ['considered' => $queued->count(), 'assigned' => 0, 'unassigned' => 0, 'reasons' => []];

        if ($queued->isEmpty()) {
            return $stats;
        }

        $workloads = $this->snapshotWorkloads();

        foreach ($queued as $conversation) {
            $decision = $this->distributor->choose(
                array_values($workloads),
                $conversation->required_skill,
            );

            if (!$decision->isAssigned()) {
                $reason = $decision->reason->value;
                $stats['unassigned']++;
                $stats['reasons'][$reason] = ($stats['reasons'][$reason] ?? 0) + 1;

                // Recorded on the conversation so a stalled queue explains
                // itself. Without this, a supervisor sees an inbox that is not
                // moving and has to guess whether it is staffing or capacity.
                $conversation->update([
                    'no_agent_reason' => $reason,
                    'routing_attempts' => $conversation->routing_attempts + 1,
                ]);

                // Every remaining conversation would get the same answer: the
                // snapshot does not change by trying again. The only exception
                // is a skill mismatch, where a different conversation might
                // still match somebody.
                if ($decision->reason->resolvesOnItsOwn()) {
                    break;
                }

                continue;
            }

            $agent = $decision->agent;

            $this->transition->handle(
                $conversation,
                ConversationStatus::ASSIGNED,
                'distributor',
                sprintf('routed to %s', $agent->name),
                [
                    'agent_id' => $agent->agentId,
                    'load_factor' => round($agent->loadFactor(), 2),
                ],
                [
                    'agent_id' => $agent->agentId,
                    'assigned_at' => now(),
                    'no_agent_reason' => null,
                ],
            );

            Agent::query()->whereKey($agent->agentId)->update(['last_assigned_at' => now()]);

            // Reflect the assignment in the snapshot so the next conversation
            // in this run sees the agent as one busier.
            $workloads[$agent->agentId] = new \App\Domain\Routing\AgentWorkload(
                agentId: $agent->agentId,
                name: $agent->name,
                presence: $agent->presence,
                openConversations: $agent->openConversations + 1,
                capacity: $agent->capacity,
                lastAssignedAtTimestamp: now()->getTimestamp(),
                skills: $agent->skills,
            );

            $stats['assigned']++;
        }

        return $stats;
    }

    /**
     * Every agent, with their current load, in two queries.
     *
     * @return array<int,\App\Domain\Routing\AgentWorkload>
     */
    private function snapshotWorkloads(): array
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

        $workloads = [];
        foreach (Agent::all() as $agent) {
            $workloads[$agent->id] = $agent->toWorkload((int) ($counts[$agent->id] ?? 0));
        }

        return $workloads;
    }
}
