<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Messaging\ReplyWindow;
use App\Domain\Messaging\SendKind;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReplyWindowTest extends TestCase
{
    private ReplyWindow $window;
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->window = new ReplyWindow(windowHours: 24);
        $this->t0 = new DateTimeImmutable('2026-01-01 09:00:00');
    }

    #[Test]
    public function the_window_is_open_for_the_configured_period(): void
    {
        self::assertTrue($this->window->isOpen($this->t0, $this->t0));
        self::assertTrue($this->window->isOpen($this->t0, $this->t0->modify('+23 hours 59 minutes')));
        self::assertFalse($this->window->isOpen($this->t0, $this->t0->modify('+24 hours')));
        self::assertFalse($this->window->isOpen($this->t0, $this->t0->modify('+25 hours')));
    }

    /**
     * No inbound message means the business would be starting the conversation,
     * which the window does not cover at all.
     */
    #[Test]
    public function it_is_closed_when_the_customer_never_wrote(): void
    {
        self::assertFalse($this->window->isOpen(null, $this->t0));
        self::assertSame(0, $this->window->secondsRemaining(null, $this->t0));
        self::assertSame(SendKind::TEMPLATE_ONLY, $this->window->allowedSendKind(null, $this->t0));
    }

    #[Test]
    public function it_reports_the_time_remaining(): void
    {
        self::assertSame(86_400, $this->window->secondsRemaining($this->t0, $this->t0));
        self::assertSame(3_600, $this->window->secondsRemaining($this->t0, $this->t0->modify('+23 hours')));
        self::assertSame(0, $this->window->secondsRemaining($this->t0, $this->t0->modify('+24 hours')));
        self::assertSame(0, $this->window->secondsRemaining($this->t0, $this->t0->modify('+10 days')));
    }

    /**
     * Two values rather than a boolean: outside the window an agent can still
     * reach the customer, just only through an approved template. An interface
     * built on a boolean shows a disabled box and strands the conversation.
     */
    #[Test]
    public function the_send_kind_distinguishes_free_text_from_templates(): void
    {
        self::assertSame(SendKind::FREE_FORM, $this->window->allowedSendKind($this->t0, $this->t0));
        self::assertTrue(SendKind::FREE_FORM->allowsFreeText());

        $later = $this->t0->modify('+2 days');
        self::assertSame(SendKind::TEMPLATE_ONLY, $this->window->allowedSendKind($this->t0, $later));
        self::assertFalse(SendKind::TEMPLATE_ONLY->allowsFreeText());
    }

    /**
     * The failure is silent and late, so the agent has to be warned while there
     * is still time to act rather than on the send that gets dropped.
     */
    #[Test]
    public function it_warns_before_the_window_closes(): void
    {
        self::assertFalse($this->window->isClosingSoon($this->t0, $this->t0));
        self::assertTrue($this->window->isClosingSoon($this->t0, $this->t0->modify('+23 hours 30 minutes')));

        // Already closed is not "closing soon": there is nothing left to warn about.
        self::assertFalse($this->window->isClosingSoon($this->t0, $this->t0->modify('+25 hours')));
    }

    /** Every inbound message restarts the clock. */
    #[Test]
    public function the_clock_runs_from_the_last_customer_message(): void
    {
        $firstMessage = $this->t0;
        $now = $this->t0->modify('+30 hours');

        self::assertFalse($this->window->isOpen($firstMessage, $now));

        $latestMessage = $this->t0->modify('+29 hours');
        self::assertTrue($this->window->isOpen($latestMessage, $now));
    }

    #[Test]
    public function the_window_length_is_configurable(): void
    {
        $short = new ReplyWindow(windowHours: 2);

        self::assertTrue($short->isOpen($this->t0, $this->t0->modify('+1 hour')));
        self::assertFalse($short->isOpen($this->t0, $this->t0->modify('+3 hours')));
        self::assertSame(
            '2026-01-01 11:00:00',
            $short->closesAt($this->t0)->format('Y-m-d H:i:s'),
        );
    }
}
