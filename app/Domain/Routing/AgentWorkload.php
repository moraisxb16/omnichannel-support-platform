<?php

declare(strict_types=1);

namespace App\Domain\Routing;

use App\Domain\Presence\PresenceState;

/**
 * A snapshot of one agent at the moment a routing decision is made.
 *
 * A value object rather than the Eloquent model, so the distributor can be
 * tested without a database and so a routing decision is reproducible: the
 * inputs are right here, and the same inputs always give the same answer.
 */
final class AgentWorkload
{
    public function __construct(
        public readonly int $agentId,
        public readonly string $name,
        public readonly PresenceState $presence,
        /** Conversations currently counting against this agent. */
        public readonly int $openConversations,
        /** The ceiling. Beyond it, the agent is skipped. */
        public readonly int $capacity,
        /** Used to break ties fairly; null means never assigned anything. */
        public readonly ?int $lastAssignedAtTimestamp = null,
        /** @var list<string> skills this agent can take */
        public readonly array $skills = [],
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->presence->canReceiveWork() && $this->hasHeadroom();
    }

    public function hasHeadroom(): bool
    {
        return $this->openConversations < $this->capacity;
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->openConversations);
    }

    /**
     * How loaded this agent is, 0.0 to 1.0.
     *
     * A ratio and not a raw count, because agents do not all have the same
     * ceiling: a senior handling eight of ten is less pressed than a trainee
     * handling three of three, and raw counts would route to the trainee.
     */
    public function loadFactor(): float
    {
        if ($this->capacity <= 0) {
            return 1.0;
        }

        return min(1.0, $this->openConversations / $this->capacity);
    }

    public function canHandle(?string $requiredSkill): bool
    {
        if ($requiredSkill === null || $this->skills === []) {
            return $requiredSkill === null;
        }

        return in_array($requiredSkill, $this->skills, true);
    }
}
