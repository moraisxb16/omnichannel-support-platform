<?php

declare(strict_types=1);

namespace App\Domain\Routing;

/**
 * The outcome of asking "who should take this?".
 *
 * A refusal carries a REASON, not just a null. "Nobody is online" and
 * "everybody is at their ceiling" look identical to the caller but mean
 * opposite things to the person running the shift: one is a staffing problem,
 * the other is a capacity problem. Returning null for both is how a queue
 * silently stops moving with nobody able to say why.
 */
final class RoutingDecision
{
    private function __construct(
        public readonly ?AgentWorkload $agent,
        public readonly ?NoAgentReason $reason,
    ) {
    }

    public static function assignTo(AgentWorkload $agent): self
    {
        return new self($agent, null);
    }

    public static function refuse(NoAgentReason $reason): self
    {
        return new self(null, $reason);
    }

    public function isAssigned(): bool
    {
        return $this->agent !== null;
    }
}
