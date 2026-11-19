<?php

declare(strict_types=1);

namespace App\Domain\Routing;

/**
 * Why no agent was chosen.
 *
 * These are different operational problems with different fixes, and the
 * distinction is the whole value of not returning a bare null:
 *
 *  - NOBODY_ONLINE   -> staffing. Someone has to log in.
 *  - ALL_AT_CAPACITY -> load. Either raise ceilings or add people.
 *  - NO_MATCHING_SKILL -> routing configuration. The queue is asking for a
 *    skill nobody on shift has, and no amount of waiting fixes it.
 *  - OUTSIDE_OFFICE_HOURS -> expected. Not an incident at all.
 *
 * Recorded against the conversation so a supervisor looking at a stalled queue
 * sees the cause rather than inferring it.
 */
enum NoAgentReason: string
{
    case NOBODY_ONLINE = 'nobody_online';
    case ALL_AT_CAPACITY = 'all_at_capacity';
    case NO_MATCHING_SKILL = 'no_matching_skill';
    case OUTSIDE_OFFICE_HOURS = 'outside_office_hours';

    /** Will waiting help? */
    public function resolvesOnItsOwn(): bool
    {
        return match ($this) {
            self::ALL_AT_CAPACITY, self::OUTSIDE_OFFICE_HOURS => true,
            self::NOBODY_ONLINE, self::NO_MATCHING_SKILL => false,
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::NOBODY_ONLINE => 'No agent is online.',
            self::ALL_AT_CAPACITY => 'Every available agent is at their ceiling.',
            self::NO_MATCHING_SKILL => 'No agent on shift has the required skill.',
            self::OUTSIDE_OFFICE_HOURS => 'Outside office hours.',
        };
    }
}
