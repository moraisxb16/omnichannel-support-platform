<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Domain\Conversation\ConversationStatus;
use App\Models\Conversation;

/**
 * Returns to the queue every conversation held by an agent who is gone.
 *
 * THE LIMBO. A conversation assigned to an agent who then goes offline is in
 * the worst possible state: it is not in the queue, so the distributor will
 * never look at it, and it has an owner, so nobody else feels responsible for
 * it. Nothing in the normal flow rescues it. The customer waits, the inbox
 * looks healthy, and the conversation is found days later by someone running a
 * report.
 *
 * It is a sweep rather than an event handler on disconnect, for the same reason
 * leases beat heartbeats: an agent whose laptop lost power never emits a
 * disconnect. Something has to come looking.
 *
 * AWAY does not trigger this. Someone at lunch keeps their queue — reclaiming
 * it churns the inbox and throws away the context they had built.
 */
final class ReleaseOrphanedConversations
{
    public function __construct(
        private readonly TransitionConversation $transition,
    ) {
    }

    /** @return array{swept:int,released:int} */
    public function handle(int $limit = 100): array
    {
        $held = Conversation::query()
            ->whereIn('status', ConversationStatus::occupiesAnAgent())
            ->whereNotNull('agent_id')
            ->with('agent')
            ->limit($limit)
            ->get();

        $released = 0;

        foreach ($held as $conversation) {
            $agent = $conversation->agent;

            // A deleted agent leaves a dangling assignment; treat it the same
            // as an offline one.
            if ($agent !== null && !$agent->presence->releasesHeldWork()) {
                continue;
            }

            $this->transition->handle(
                $conversation,
                ConversationStatus::QUEUED,
                'presence-sweep',
                $agent === null
                    ? 'assigned agent no longer exists'
                    : sprintf('%s went offline while holding this conversation', $agent->name),
                ['previous_agent_id' => $conversation->agent_id],
            );

            $released++;
        }

        return ['swept' => $held->count(), 'released' => $released];
    }
}
