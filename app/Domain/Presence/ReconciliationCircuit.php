<?php

declare(strict_types=1);

namespace App\Domain\Presence;

use DateTimeImmutable;

/**
 * Debounces presence so a flapping connection does not churn the queue.
 *
 * THE PROBLEM. Presence comes from a socket, and sockets on mobile networks
 * drop and reconnect constantly — a train tunnel produces a dozen
 * offline/online pairs in a minute. Acting on each one means the agent's
 * conversations are reclaimed, redistributed to colleagues, and then the agent
 * is back and idle while somebody else answers the customer they were mid-reply
 * to. The queue does a great deal of work and the operation gets worse.
 *
 * THE RULE. A transition to OFFLINE is only believed after it has held for
 * $graceSeconds. Anything else — going online, going away — is believed at
 * once, because those are deliberate and never destructive.
 *
 * This is a circuit in the electrical sense, not the Hystrix sense: it stays
 * open briefly to let a transient settle, rather than tripping to protect a
 * dependency.
 */
final class ReconciliationCircuit
{
    public function __construct(
        private readonly int $graceSeconds = 45,
    ) {
    }

    /**
     * Should $observed be written as the agent's presence?
     *
     * $lastSeenAt is when the agent was last confirmed present; $observedAt is
     * now. A loss is only acted on once the gap exceeds the grace period.
     */
    public function shouldApply(
        PresenceState $current,
        PresenceState $observed,
        DateTimeImmutable $lastSeenAt,
        DateTimeImmutable $observedAt,
    ): bool {
        if ($current === $observed) {
            return false;
        }

        // Deliberate states are never debounced: an agent pressing "away"
        // expects it to take effect, and nobody loses work over it.
        if ($observed !== PresenceState::OFFLINE) {
            return true;
        }

        return $this->elapsed($lastSeenAt, $observedAt) >= $this->graceSeconds;
    }

    /**
     * Seconds remaining before a loss would be believed. Zero once it is due.
     *
     * Exposed so the interface can say "reconnecting..." instead of showing an
     * agent as present when they are not, which is its own kind of lie.
     */
    public function secondsUntilBelieved(DateTimeImmutable $lastSeenAt, DateTimeImmutable $observedAt): int
    {
        return max(0, $this->graceSeconds - $this->elapsed($lastSeenAt, $observedAt));
    }

    public function graceSeconds(): int
    {
        return $this->graceSeconds;
    }

    private function elapsed(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $to->getTimestamp() - $from->getTimestamp();
    }
}
