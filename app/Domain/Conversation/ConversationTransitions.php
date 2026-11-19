<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

/**
 * The transition table, and nothing else. No database, no clock, no events.
 *
 * Whether a resolved conversation may go back in the queue is a question about
 * how support works, and answering it should not require a connection. The
 * authority that performs a transition — validating here, writing the row and
 * the timeline entry in one transaction — is ConversationRepository.
 */
final class ConversationTransitions
{
    /** @return array<string, list<ConversationStatus>> */
    private static function table(): array
    {
        return [
            // Waiting in the pool. It gets picked up, or nobody ever comes.
            ConversationStatus::QUEUED->value => [
                ConversationStatus::ASSIGNED,
                ConversationStatus::ARCHIVED,
            ],

            // An agent owns it. Back to QUEUED covers transfer and the sweep
            // that reclaims conversations from agents who went offline.
            ConversationStatus::ASSIGNED->value => [
                ConversationStatus::WAITING_CUSTOMER,
                ConversationStatus::QUEUED,
                ConversationStatus::RESOLVED,
                ConversationStatus::ARCHIVED,
            ],

            // The customer replies and it is live again; or nobody replies and
            // it ages out.
            ConversationStatus::WAITING_CUSTOMER->value => [
                ConversationStatus::ASSIGNED,
                ConversationStatus::QUEUED,
                ConversationStatus::RESOLVED,
                ConversationStatus::ARCHIVED,
            ],

            // The reopen path. A customer saying "that did not work" belongs in
            // the same conversation, not a new one.
            ConversationStatus::RESOLVED->value => [
                ConversationStatus::QUEUED,
                ConversationStatus::ARCHIVED,
            ],

            // ARCHIVED is absent as a key. That absence is what terminal means,
            // so there is no second list to keep in step with
            // ConversationStatus::terminal().
        ];
    }

    /** @return list<ConversationStatus> */
    public static function allowedFrom(ConversationStatus $from): array
    {
        return self::table()[$from->value] ?? [];
    }

    public static function allows(ConversationStatus $from, ConversationStatus $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /** @throws InvalidConversationTransition */
    public static function assert(ConversationStatus $from, ConversationStatus $to, string $reference): void
    {
        if (!self::allows($from, $to)) {
            throw new InvalidConversationTransition($from, $to, $reference);
        }
    }

    /**
     * States that are reachable but have no way out. Used by the test suite.
     *
     * Adding a status, wiring it as a target and forgetting its outgoing
     * transitions produces a conversation that silently cannot move. Without
     * this check the symptom shows up in production as an agent complaining
     * that a ticket is stuck.
     *
     * @return list<string>
     */
    public static function deadEnds(): array
    {
        $table = self::table();
        $found = [];

        foreach ($table as $targets) {
            foreach ($targets as $target) {
                if (!$target->isTerminal() && !array_key_exists($target->value, $table)) {
                    $found[$target->value] = true;
                }
            }
        }

        return array_keys($found);
    }
}
