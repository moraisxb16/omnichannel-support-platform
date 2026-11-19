<?php

declare(strict_types=1);

namespace App\Domain\Presence;

/**
 * Whether an agent can be given work right now.
 *
 * AWAY is separate from OFFLINE on purpose. Both mean "do not route to them",
 * but they differ in what should happen to the conversations the agent already
 * holds: someone at lunch will be back and should keep their queue, whereas a
 * dropped connection means nobody is reading those conversations at all.
 */
enum PresenceState: string
{
    case ONLINE = 'online';
    case AWAY = 'away';
    case OFFLINE = 'offline';

    public function canReceiveWork(): bool
    {
        return $this === self::ONLINE;
    }

    /**
     * Should the conversations this agent holds be taken back?
     *
     * Only for OFFLINE. Reclaiming from an agent who stepped away for five
     * minutes churns the queue and loses the context they had built up.
     */
    public function releasesHeldWork(): bool
    {
        return $this === self::OFFLINE;
    }
}
