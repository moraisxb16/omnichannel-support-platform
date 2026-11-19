<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Domain\Conversation\ConversationStatus;
use App\Models\Conversation;

/**
 * Closes conversations nothing is going to happen to.
 *
 * Two different kinds of stale, with deliberately different timeouts:
 *
 *  - WAITING_CUSTOMER with no reply for $waitingCustomerDays. The agent did
 *    their part. Holding it open forever keeps it counting against their
 *    capacity, so the distributor quietly under-uses them.
 *  - RESOLVED past the reopen window. Once a customer can no longer revive it,
 *    the conversation is genuinely finished and may be archived.
 *
 * ARCHIVED is the only terminal state here, and this is the only thing that
 * produces it. That is on purpose: archiving is the one irreversible act in the
 * system, so exactly one piece of code performs it and the timeline always says
 * which sweep did.
 */
final class ArchiveStaleConversations
{
    public function __construct(
        private readonly TransitionConversation $transition,
    ) {
    }

    /** @return array{waiting_closed:int,resolved_archived:int} */
    public function handle(
        int $waitingCustomerDays = 7,
        int $reopenWindowDays = 3,
        int $limit = 200,
    ): array {
        $waitingClosed = 0;
        $resolvedArchived = 0;

        $abandoned = Conversation::query()
            ->where('status', ConversationStatus::WAITING_CUSTOMER)
            ->where(function ($query) use ($waitingCustomerDays): void {
                $query->where('last_customer_message_at', '<', now()->subDays($waitingCustomerDays))
                    ->orWhereNull('last_customer_message_at');
            })
            ->limit($limit)
            ->get();

        foreach ($abandoned as $conversation) {
            $this->transition->handle(
                $conversation,
                ConversationStatus::ARCHIVED,
                'inactivity-sweep',
                sprintf('no customer reply for %d days', $waitingCustomerDays),
            );
            $waitingClosed++;
        }

        $resolved = Conversation::query()
            ->where('status', ConversationStatus::RESOLVED)
            ->where('resolved_at', '<', now()->subDays($reopenWindowDays))
            ->limit($limit)
            ->get();

        foreach ($resolved as $conversation) {
            $this->transition->handle(
                $conversation,
                ConversationStatus::ARCHIVED,
                'inactivity-sweep',
                sprintf('reopen window of %d days expired', $reopenWindowDays),
            );
            $resolvedArchived++;
        }

        return [
            'waiting_closed' => $waitingClosed,
            'resolved_archived' => $resolvedArchived,
        ];
    }
}
