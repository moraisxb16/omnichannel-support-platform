<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

use DomainException;

/**
 * A transition the table does not allow.
 *
 * Carries both ends because the useful question during an incident is never
 * "was it rejected" but "what did it think it was doing": a conversation trying
 * to go archived -> assigned points at a different bug than one trying to go
 * queued -> waiting_customer.
 */
final class InvalidConversationTransition extends DomainException
{
    public function __construct(
        public readonly ConversationStatus $from,
        public readonly ConversationStatus $to,
        public readonly string $reference,
    ) {
        parent::__construct(sprintf(
            'Conversation %s cannot move from %s to %s.',
            $reference,
            $from->value,
            $to->value,
        ));
    }
}
