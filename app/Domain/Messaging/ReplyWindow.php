<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

use DateTimeImmutable;

/**
 * The customer-initiated reply window.
 *
 * Messaging channels generally do not let a business message whoever it likes
 * whenever it likes. The common shape: once a customer writes in, the business
 * may reply freely for a fixed period; after that it may only send a
 * pre-approved template, and a free-form send is rejected by the provider.
 *
 * This matters more than it sounds. The window is a business rule wearing a
 * technical costume — it decides whether an agent's reply reaches the customer
 * at all. Discovering it at send time means the agent has already typed the
 * message, already clicked send, and finds out it never arrived. So the window
 * is computed up front and the interface knows about it before anyone types.
 *
 * The clock runs from the customer's LAST message, not the first. Every inbound
 * message reopens the window, which is why an agent who replies promptly never
 * sees this at all, and one returning to a two-day-old conversation does.
 */
final class ReplyWindow
{
    public function __construct(
        private readonly int $windowHours = 24,
    ) {
    }

    public function isOpen(?DateTimeImmutable $lastCustomerMessageAt, DateTimeImmutable $now): bool
    {
        if ($lastCustomerMessageAt === null) {
            // The customer never wrote. Nothing to reply to, and an outbound
            // message here would be the business starting the conversation,
            // which the window does not cover.
            return false;
        }

        return $this->secondsRemaining($lastCustomerMessageAt, $now) > 0;
    }

    public function secondsRemaining(?DateTimeImmutable $lastCustomerMessageAt, DateTimeImmutable $now): int
    {
        if ($lastCustomerMessageAt === null) {
            return 0;
        }

        $closesAt = $lastCustomerMessageAt->getTimestamp() + ($this->windowHours * 3600);

        return max(0, $closesAt - $now->getTimestamp());
    }

    public function closesAt(DateTimeImmutable $lastCustomerMessageAt): DateTimeImmutable
    {
        return $lastCustomerMessageAt->modify('+' . $this->windowHours . ' hours');
    }

    /**
     * What an agent may send right now.
     *
     * Returned as a decision rather than a boolean so the interface can show
     * the right thing: a composer, a template picker, or an explanation.
     */
    public function allowedSendKind(?DateTimeImmutable $lastCustomerMessageAt, DateTimeImmutable $now): SendKind
    {
        return $this->isOpen($lastCustomerMessageAt, $now)
            ? SendKind::FREE_FORM
            : SendKind::TEMPLATE_ONLY;
    }

    /**
     * Is the window close enough to closing to warn the agent?
     *
     * Exists because the failure is silent and late. An agent reading a long
     * thread with forty minutes left should be told, not discover it on send.
     */
    public function isClosingSoon(
        ?DateTimeImmutable $lastCustomerMessageAt,
        DateTimeImmutable $now,
        int $warnWithinSeconds = 3600,
    ): bool {
        $remaining = $this->secondsRemaining($lastCustomerMessageAt, $now);

        return $remaining > 0 && $remaining <= $warnWithinSeconds;
    }

    public function windowHours(): int
    {
        return $this->windowHours;
    }
}
