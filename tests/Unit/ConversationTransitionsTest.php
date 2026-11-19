<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Conversation\ConversationStatus;
use App\Domain\Conversation\ConversationTransitions;
use App\Domain\Conversation\InvalidConversationTransition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConversationTransitionsTest extends TestCase
{
    #[Test]
    #[DataProvider('legalMoves')]
    public function it_allows_legal_moves(ConversationStatus $from, ConversationStatus $to): void
    {
        self::assertTrue(ConversationTransitions::allows($from, $to));
    }

    /** @return iterable<string,array{ConversationStatus,ConversationStatus}> */
    public static function legalMoves(): iterable
    {
        yield 'picked up' => [ConversationStatus::QUEUED, ConversationStatus::ASSIGNED];
        yield 'agent replies' => [ConversationStatus::ASSIGNED, ConversationStatus::WAITING_CUSTOMER];
        yield 'customer replies' => [ConversationStatus::WAITING_CUSTOMER, ConversationStatus::ASSIGNED];
        yield 'agent resolves' => [ConversationStatus::ASSIGNED, ConversationStatus::RESOLVED];
        yield 'transferred back' => [ConversationStatus::ASSIGNED, ConversationStatus::QUEUED];
        yield 'reopened by customer' => [ConversationStatus::RESOLVED, ConversationStatus::QUEUED];
        yield 'archived after the reopen window' => [ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED];
        yield 'abandoned by customer' => [ConversationStatus::WAITING_CUSTOMER, ConversationStatus::ARCHIVED];
    }

    #[Test]
    #[DataProvider('illegalMoves')]
    public function it_refuses_illegal_moves(ConversationStatus $from, ConversationStatus $to): void
    {
        self::assertFalse(ConversationTransitions::allows($from, $to));

        $this->expectException(InvalidConversationTransition::class);
        ConversationTransitions::assert($from, $to, 'CV-1');
    }

    /** @return iterable<string,array{ConversationStatus,ConversationStatus}> */
    public static function illegalMoves(): iterable
    {
        // The customer cannot be waiting on an agent who was never given it.
        yield 'waiting without an owner' => [ConversationStatus::QUEUED, ConversationStatus::WAITING_CUSTOMER];
        yield 'resolved without an owner' => [ConversationStatus::QUEUED, ConversationStatus::RESOLVED];
        yield 'archived revived' => [ConversationStatus::ARCHIVED, ConversationStatus::QUEUED];
        yield 'archived reassigned' => [ConversationStatus::ARCHIVED, ConversationStatus::ASSIGNED];
        yield 'resolved reassigned directly' => [ConversationStatus::RESOLVED, ConversationStatus::ASSIGNED];
    }

    /**
     * The reopen path. Someone replying to say the answer did not work belongs
     * in the same thread; forcing a new conversation throws away the history
     * for whoever picks it up.
     */
    #[Test]
    public function resolved_is_not_terminal(): void
    {
        self::assertFalse(ConversationStatus::RESOLVED->isTerminal());
        self::assertContains(
            ConversationStatus::QUEUED,
            ConversationTransitions::allowedFrom(ConversationStatus::RESOLVED),
        );
    }

    #[Test]
    public function archived_is_the_only_terminal_state(): void
    {
        self::assertSame([ConversationStatus::ARCHIVED], ConversationStatus::terminal());
        self::assertSame([], ConversationTransitions::allowedFrom(ConversationStatus::ARCHIVED));

        foreach (ConversationStatus::cases() as $status) {
            if ($status === ConversationStatus::ARCHIVED) {
                continue;
            }
            self::assertNotSame(
                [],
                ConversationTransitions::allowedFrom($status),
                "{$status->value} is not terminal and needs a way out",
            );
        }
    }

    #[Test]
    public function only_queued_conversations_are_assignable(): void
    {
        self::assertSame([ConversationStatus::QUEUED], ConversationStatus::assignable());

        // Stealing a conversation from an agent mid-reply is worse than any
        // delay it could save.
        self::assertFalse(ConversationStatus::ASSIGNED->isAssignable());
        self::assertFalse(ConversationStatus::WAITING_CUSTOMER->isAssignable());
    }

    /**
     * An agent waiting on a customer is still responsible for that conversation
     * and it still counts against their ceiling.
     */
    #[Test]
    public function both_owned_states_count_toward_capacity(): void
    {
        self::assertTrue(ConversationStatus::ASSIGNED->countsTowardCapacity());
        self::assertTrue(ConversationStatus::WAITING_CUSTOMER->countsTowardCapacity());

        self::assertFalse(ConversationStatus::QUEUED->countsTowardCapacity());
        self::assertFalse(ConversationStatus::RESOLVED->countsTowardCapacity());
        self::assertFalse(ConversationStatus::ARCHIVED->countsTowardCapacity());
    }

    #[Test]
    public function the_table_has_no_dead_ends(): void
    {
        self::assertSame([], ConversationTransitions::deadEnds());
    }
}
