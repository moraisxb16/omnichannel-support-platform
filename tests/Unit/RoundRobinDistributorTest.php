<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Presence\PresenceState;
use App\Domain\Routing\AgentWorkload;
use App\Domain\Routing\NoAgentReason;
use App\Domain\Routing\RoundRobinDistributor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The distributor is pure, so none of this needs a database. That is the point
 * of it being pure: "six conversations, three agents, one at their ceiling" is
 * a test rather than a staging experiment.
 */
final class RoundRobinDistributorTest extends TestCase
{
    private RoundRobinDistributor $distributor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->distributor = new RoundRobinDistributor();
    }

    #[Test]
    public function it_prefers_the_least_loaded_agent(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Busy', open: 4, capacity: 5),
            $this->agent(2, 'Free', open: 1, capacity: 5),
            $this->agent(3, 'Middling', open: 3, capacity: 5),
        ]);

        self::assertTrue($decision->isAssigned());
        self::assertSame(2, $decision->agent->agentId);
    }

    /**
     * The subtlety that raw counts get wrong. A senior holding 8 of 12 has more
     * room than a trainee holding 3 of 3, and counting conversations instead of
     * ratios routes the next one to the trainee, who is already full.
     */
    #[Test]
    public function load_is_compared_by_ratio_not_by_raw_count(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Trainee', open: 2, capacity: 3),   // 0.67
            $this->agent(2, 'Senior', open: 6, capacity: 12),   // 0.50
        ]);

        self::assertSame(2, $decision->agent->agentId, 'the senior has more headroom despite holding more');
    }

    #[Test]
    public function the_capacity_ceiling_is_hard(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Full', open: 5, capacity: 5),
            $this->agent(2, 'Also full', open: 3, capacity: 3),
        ]);

        self::assertFalse($decision->isAssigned());
        self::assertSame(NoAgentReason::ALL_AT_CAPACITY, $decision->reason);
    }

    /**
     * Without a recency tie-break, "least loaded" ties at zero whenever the
     * team is idle and the sort order decides — so the first agent in the list
     * absorbs everything, forever.
     */
    #[Test]
    public function ties_go_to_whoever_has_waited_longest(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Just got one', open: 0, capacity: 5, lastAssigned: 2_000),
            $this->agent(2, 'Waiting a while', open: 0, capacity: 5, lastAssigned: 1_000),
        ]);

        self::assertSame(2, $decision->agent->agentId);
    }

    #[Test]
    public function an_agent_who_never_received_anything_goes_first(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Veteran', open: 0, capacity: 5, lastAssigned: 1_000),
            $this->agent(2, 'Brand new', open: 0, capacity: 5, lastAssigned: null),
        ]);

        self::assertSame(2, $decision->agent->agentId);
    }

    #[Test]
    public function the_result_is_deterministic_for_identical_input(): void
    {
        $agents = [
            $this->agent(7, 'G', open: 0, capacity: 5, lastAssigned: 1_000),
            $this->agent(3, 'C', open: 0, capacity: 5, lastAssigned: 1_000),
            $this->agent(5, 'E', open: 0, capacity: 5, lastAssigned: 1_000),
        ];

        // Identical on every criterion, so the id breaks the tie. A distributor
        // that answered differently each time could not be explained to the
        // agent asking why they got the ticket.
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(3, $this->distributor->choose($agents)->agent->agentId);
        }
    }

    /**
     * Four refusals that look identical to the caller but mean opposite things
     * to whoever is running the shift.
     */
    #[Test]
    public function refusals_say_why(): void
    {
        self::assertSame(
            NoAgentReason::NOBODY_ONLINE,
            $this->distributor->choose([])->reason,
        );

        self::assertSame(
            NoAgentReason::NOBODY_ONLINE,
            $this->distributor->choose([
                $this->agent(1, 'Away', open: 0, capacity: 5, presence: PresenceState::AWAY),
                $this->agent(2, 'Gone', open: 0, capacity: 5, presence: PresenceState::OFFLINE),
            ])->reason,
        );

        self::assertSame(
            NoAgentReason::NO_MATCHING_SKILL,
            $this->distributor->choose(
                [$this->agent(1, 'Generalist', open: 0, capacity: 5, skills: ['billing'])],
                requiredSkill: 'legal',
            )->reason,
        );
    }

    #[Test]
    public function a_skill_requirement_narrows_the_pool(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Billing', open: 0, capacity: 5, skills: ['billing']),
            $this->agent(2, 'Legal', open: 2, capacity: 5, skills: ['legal']),
        ], requiredSkill: 'legal');

        self::assertSame(2, $decision->agent->agentId, 'the busier agent wins because only they qualify');
    }

    #[Test]
    public function away_and_offline_agents_never_receive_work(): void
    {
        $decision = $this->distributor->choose([
            $this->agent(1, 'Away', open: 0, capacity: 5, presence: PresenceState::AWAY),
            $this->agent(2, 'Online', open: 4, capacity: 5),
        ]);

        self::assertSame(2, $decision->agent->agentId);
    }

    #[Test]
    public function headroom_counts_only_agents_who_can_take_work(): void
    {
        $headroom = $this->distributor->headroom([
            $this->agent(1, 'Online', open: 2, capacity: 5),                               // 3
            $this->agent(2, 'Full', open: 5, capacity: 5),                                  // 0
            $this->agent(3, 'Away', open: 0, capacity: 5, presence: PresenceState::AWAY),   // 0
        ]);

        self::assertSame(3, $headroom);
    }

    #[Test]
    public function an_agent_with_zero_capacity_is_treated_as_fully_loaded(): void
    {
        $agent = $this->agent(1, 'Suspended', open: 0, capacity: 0);

        self::assertSame(1.0, $agent->loadFactor());
        self::assertFalse($agent->hasHeadroom());
        self::assertSame(NoAgentReason::ALL_AT_CAPACITY, $this->distributor->choose([$agent])->reason);
    }

    /** @param list<string> $skills */
    private function agent(
        int $id,
        string $name,
        int $open,
        int $capacity,
        ?int $lastAssigned = null,
        PresenceState $presence = PresenceState::ONLINE,
        array $skills = [],
    ): AgentWorkload {
        return new AgentWorkload($id, $name, $presence, $open, $capacity, $lastAssigned, $skills);
    }
}
