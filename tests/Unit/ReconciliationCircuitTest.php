<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Presence\PresenceState;
use App\Domain\Presence\ReconciliationCircuit;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReconciliationCircuitTest extends TestCase
{
    private ReconciliationCircuit $circuit;
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->circuit = new ReconciliationCircuit(graceSeconds: 45);
        $this->t0 = new DateTimeImmutable('2026-01-01 12:00:00');
    }

    /**
     * The case the whole class exists for. A phone entering a tunnel emits a
     * loss; acting on it reclaims the agent's conversations and hands them to
     * colleagues, and the agent is back twenty seconds later with nothing to do.
     */
    #[Test]
    public function an_immediate_loss_is_not_believed(): void
    {
        self::assertFalse($this->circuit->shouldApply(
            PresenceState::ONLINE,
            PresenceState::OFFLINE,
            lastSeenAt: $this->t0,
            observedAt: $this->t0,
        ));
    }

    #[Test]
    public function a_loss_that_holds_past_the_grace_period_is_believed(): void
    {
        self::assertFalse($this->circuit->shouldApply(
            PresenceState::ONLINE,
            PresenceState::OFFLINE,
            $this->t0,
            $this->t0->modify('+44 seconds'),
        ));

        self::assertTrue($this->circuit->shouldApply(
            PresenceState::ONLINE,
            PresenceState::OFFLINE,
            $this->t0,
            $this->t0->modify('+45 seconds'),
        ));
    }

    /**
     * Debouncing a deliberate action would be its own bug: an agent pressing
     * "away" expects it to take effect, and nothing is lost by obeying.
     */
    #[Test]
    public function deliberate_states_apply_immediately(): void
    {
        self::assertTrue($this->circuit->shouldApply(
            PresenceState::ONLINE,
            PresenceState::AWAY,
            $this->t0,
            $this->t0,
        ));

        self::assertTrue($this->circuit->shouldApply(
            PresenceState::OFFLINE,
            PresenceState::ONLINE,
            $this->t0,
            $this->t0,
        ));
    }

    #[Test]
    public function an_unchanged_state_is_never_rewritten(): void
    {
        foreach (PresenceState::cases() as $state) {
            self::assertFalse(
                $this->circuit->shouldApply($state, $state, $this->t0, $this->t0->modify('+1 hour')),
                "{$state->value} -> {$state->value} is a no-op",
            );
        }
    }

    #[Test]
    public function it_reports_how_long_until_a_loss_would_be_believed(): void
    {
        self::assertSame(45, $this->circuit->secondsUntilBelieved($this->t0, $this->t0));
        self::assertSame(15, $this->circuit->secondsUntilBelieved($this->t0, $this->t0->modify('+30 seconds')));
        self::assertSame(0, $this->circuit->secondsUntilBelieved($this->t0, $this->t0->modify('+45 seconds')));
        self::assertSame(0, $this->circuit->secondsUntilBelieved($this->t0, $this->t0->modify('+10 minutes')));
    }

    /**
     * Only OFFLINE releases held work. Reclaiming from someone at lunch churns
     * the inbox and throws away the context they had built up.
     */
    #[Test]
    public function only_offline_releases_held_work(): void
    {
        self::assertTrue(PresenceState::OFFLINE->releasesHeldWork());
        self::assertFalse(PresenceState::AWAY->releasesHeldWork());
        self::assertFalse(PresenceState::ONLINE->releasesHeldWork());
    }

    #[Test]
    public function only_online_receives_work(): void
    {
        self::assertTrue(PresenceState::ONLINE->canReceiveWork());
        self::assertFalse(PresenceState::AWAY->canReceiveWork());
        self::assertFalse(PresenceState::OFFLINE->canReceiveWork());
    }
}
