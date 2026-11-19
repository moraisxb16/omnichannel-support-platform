<?php

declare(strict_types=1);

namespace App\Actions\Presence;

use App\Domain\Presence\PresenceState;
use App\Domain\Presence\ReconciliationCircuit;
use App\Models\Agent;

/**
 * Applies an observed presence change, debounced through the circuit.
 *
 * Presence arrives from a socket, and sockets drop. A phone going through a
 * tunnel produces a dozen offline/online pairs in a minute; acting on each one
 * reclaims the agent's conversations, hands them to colleagues, and leaves the
 * agent idle and confused when they come back thirty seconds later.
 *
 * So a LOSS is provisional until it has held for the grace period. Deliberate
 * states — online, away — apply at once, because nobody loses work over them.
 */
final class UpdateAgentPresence
{
    public function __construct(
        private readonly ReconciliationCircuit $circuit,
    ) {
    }

    /**
     * @return array{applied:bool,state:PresenceState,seconds_until_believed:int}
     */
    public function handle(Agent $agent, PresenceState $observed): array
    {
        $lastSeen = $agent->last_seen_at?->toDateTimeImmutable() ?? now()->toDateTimeImmutable();
        $now = now()->toDateTimeImmutable();

        $shouldApply = $this->circuit->shouldApply($agent->presence, $observed, $lastSeen, $now);

        if (!$shouldApply) {
            // A heartbeat that confirms the agent is still there resets the
            // grace period. Without this, an agent online for an hour would
            // always be "overdue" and the first blip would be believed at once.
            if ($observed !== PresenceState::OFFLINE) {
                $agent->update(['last_seen_at' => now()]);
            }

            return [
                'applied' => false,
                'state' => $agent->presence,
                'seconds_until_believed' => $this->circuit->secondsUntilBelieved($lastSeen, $now),
            ];
        }

        $attributes = ['presence' => $observed];

        if ($observed !== PresenceState::OFFLINE) {
            $attributes['last_seen_at'] = now();
        }

        $agent->update($attributes);

        return [
            'applied' => true,
            'state' => $observed,
            'seconds_until_believed' => 0,
        ];
    }
}
