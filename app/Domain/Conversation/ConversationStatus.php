<?php

declare(strict_types=1);

namespace App\Domain\Conversation;

/**
 * Every state a conversation can hold.
 *
 * One vocabulary for both the human reality (an agent is working on it) and the
 * mechanics (it is in the pool waiting to be picked up). Two parallel columns
 * for "status" and "queue state" drift apart, and reconciling them later means
 * deciding which one was lying.
 *
 * Only ARCHIVED is terminal. RESOLVED is not — a customer who replies to a
 * resolved conversation within the reopen window puts it straight back in the
 * queue, which is what people actually do when an answer did not work. Treating
 * RESOLVED as terminal forces that reply to become a second, contextless
 * conversation, and the agent who picks it up has to go looking for the history.
 */
enum ConversationStatus: string
{
    /** In the pool, waiting for an agent. This is what the distributor claims. */
    case QUEUED = 'queued';

    /** An agent owns it and is expected to answer. */
    case ASSIGNED = 'assigned';

    /** The agent answered; the ball is with the customer. */
    case WAITING_CUSTOMER = 'waiting_customer';

    /** An agent marked it done. Still reopenable. */
    case RESOLVED = 'resolved';

    /** The reopen window expired, or it was closed for inactivity. Terminal. */
    case ARCHIVED = 'archived';

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::ARCHIVED];
    }

    /**
     * States the distributor may take a conversation from.
     *
     * Only QUEUED. An ASSIGNED conversation already has an owner, and stealing
     * it from under a typing agent is worse than any delay it could save.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::QUEUED];
    }

    /** States that count against an agent's capacity ceiling. */
    public static function occupiesAnAgent(): array
    {
        return [self::ASSIGNED, self::WAITING_CUSTOMER];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isAssignable(): bool
    {
        return in_array($this, self::assignable(), true);
    }

    public function countsTowardCapacity(): bool
    {
        return in_array($this, self::occupiesAnAgent(), true);
    }

    /** True when the conversation is somebody's responsibility right now. */
    public function hasOwner(): bool
    {
        return $this === self::ASSIGNED || $this === self::WAITING_CUSTOMER;
    }
}
