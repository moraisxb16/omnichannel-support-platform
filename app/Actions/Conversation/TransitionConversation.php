<?php

declare(strict_types=1);

namespace App\Actions\Conversation;

use App\Domain\Conversation\ConversationStatus;
use App\Domain\Conversation\ConversationTransitions;
use App\Domain\Conversation\InvalidConversationTransition;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Illuminate\Support\Facades\DB;

/**
 * The only place in this application that writes `conversations.status`.
 *
 * Everything else — the distributor, the sweeps, an agent resolving a ticket —
 * comes through here. The rule is not stylistic: a status written directly is a
 * status written without validating the move and without a timeline entry, and
 * those are precisely the writes that make an inbox impossible to explain
 * afterwards.
 *
 * Three things happen in one transaction: the row is locked, the move is
 * validated, and the timeline entry is written. An entry written after the
 * commit goes missing exactly when the process dies between the two.
 */
final class TransitionConversation
{
    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extraColumns columns to set in the same write
     *
     * @throws InvalidConversationTransition
     */
    public function handle(
        Conversation $conversation,
        ConversationStatus $to,
        string $actor,
        ?string $reason = null,
        array $context = [],
        array $extraColumns = [],
    ): Conversation {
        return DB::transaction(function () use ($conversation, $to, $actor, $reason, $context, $extraColumns): Conversation {
            // Re-read under a lock. Without it, two processes can both read
            // `queued` and both decide they may assign it — the distributor and
            // an agent clicking "take this" at the same moment.
            $fresh = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $from = $fresh->status;

            // Idempotent: asking for the state it is already in is a no-op, not
            // a failure. A retried click and a redelivered callback both produce
            // exactly that request.
            if ($from === $to) {
                return $fresh;
            }

            ConversationTransitions::assert($from, $to, $fresh->reference);

            $attributes = array_merge(['status' => $to], $extraColumns);

            // Leaving an owned state means the owner is released. A stale
            // agent_id on a queued conversation makes the capacity count wrong
            // for an agent who is not actually holding it, and the distributor
            // then under-uses them.
            if (!$to->hasOwner()) {
                $attributes['agent_id'] = null;
            }

            if ($to === ConversationStatus::QUEUED) {
                $attributes['queued_at'] = now();
            }

            if ($to === ConversationStatus::RESOLVED) {
                $attributes['resolved_at'] = now();
            }

            $fresh->update($attributes);

            ConversationEvent::create([
                'conversation_id' => $fresh->id,
                'conversation_reference' => $fresh->reference,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor' => $actor,
                'reason' => $reason,
                'context' => $context === [] ? null : $context,
                'occurred_at' => now(),
            ]);

            return $fresh->refresh();
        });
    }
}
