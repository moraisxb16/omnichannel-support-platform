<?php

declare(strict_types=1);

namespace App\Domain\Routing;

/**
 * Chooses which agent takes the next conversation.
 *
 * Pure: it is handed a snapshot of the agents and returns a decision. No
 * database, no clock, no side effect. That is what makes "six conversations,
 * three agents, one at their ceiling" a test rather than a staging experiment.
 *
 * THE POLICY, in order:
 *
 *  1. Drop anyone who cannot take work — not online, or already at their
 *     ceiling, or lacking the required skill.
 *  2. Of the rest, prefer the LEAST LOADED by ratio, not by raw count. Agents
 *     have different ceilings; a senior holding 8 of 12 is less pressed than a
 *     trainee holding 3 of 3, and counting raw conversations routes to the
 *     trainee.
 *  3. Break ties by who has waited longest since their last assignment. This is
 *     the round-robin part, and it is what stops the first agent in the list
 *     absorbing everything whenever the team is idle — without it, "least
 *     loaded" ties at zero and the sort order decides, forever.
 *  4. Break remaining ties by agent id, so the result is deterministic. A
 *     distributor that returns different answers for identical input cannot be
 *     tested and cannot be explained to the person who asks why they got the
 *     ticket.
 *
 * The ceiling is a hard limit, never a preference. Handing an agent a seventh
 * conversation because nobody else is free does not get it answered; it gets
 * all seven answered slowly, and the customer who would have waited two minutes
 * for the next free agent waits twenty instead.
 */
final class RoundRobinDistributor
{
    /**
     * @param list<AgentWorkload> $agents
     */
    public function choose(array $agents, ?string $requiredSkill = null): RoutingDecision
    {
        if ($agents === []) {
            return RoutingDecision::refuse(NoAgentReason::NOBODY_ONLINE);
        }

        $online = array_values(array_filter(
            $agents,
            static fn (AgentWorkload $a): bool => $a->presence->canReceiveWork(),
        ));

        if ($online === []) {
            return RoutingDecision::refuse(NoAgentReason::NOBODY_ONLINE);
        }

        $skilled = array_values(array_filter(
            $online,
            static fn (AgentWorkload $a): bool => $a->canHandle($requiredSkill),
        ));

        if ($skilled === []) {
            return RoutingDecision::refuse(NoAgentReason::NO_MATCHING_SKILL);
        }

        $withHeadroom = array_values(array_filter(
            $skilled,
            static fn (AgentWorkload $a): bool => $a->hasHeadroom(),
        ));

        if ($withHeadroom === []) {
            return RoutingDecision::refuse(NoAgentReason::ALL_AT_CAPACITY);
        }

        usort($withHeadroom, static function (AgentWorkload $a, AgentWorkload $b): int {
            // 1. Least loaded, by ratio.
            $byLoad = $a->loadFactor() <=> $b->loadFactor();
            if ($byLoad !== 0) {
                return $byLoad;
            }

            // 2. Longest since last assigned. Never assigned (null) wins
            // outright — a new agent should get work before anyone repeats.
            $aLast = $a->lastAssignedAtTimestamp ?? PHP_INT_MIN;
            $bLast = $b->lastAssignedAtTimestamp ?? PHP_INT_MIN;
            $byRecency = $aLast <=> $bLast;
            if ($byRecency !== 0) {
                return $byRecency;
            }

            // 3. Deterministic last resort.
            return $a->agentId <=> $b->agentId;
        });

        return RoutingDecision::assignTo($withHeadroom[0]);
    }

    /**
     * How many more conversations the team could take right now.
     *
     * Used by the queue dashboard. A number that reaches zero explains a
     * stalled queue at a glance, which beats a supervisor refreshing an inbox
     * and wondering whether the system is broken.
     *
     * @param list<AgentWorkload> $agents
     */
    public function headroom(array $agents): int
    {
        $total = 0;
        foreach ($agents as $agent) {
            if ($agent->presence->canReceiveWork()) {
                $total += $agent->remainingCapacity();
            }
        }

        return $total;
    }
}
