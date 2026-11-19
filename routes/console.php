<?php

declare(strict_types=1);

use App\Actions\Conversation\ArchiveStaleConversations;
use App\Actions\Conversation\ReleaseOrphanedConversations;
use App\Actions\Routing\RouteQueuedConversations;
use Illuminate\Support\Facades\Schedule;

/*
 | The three background jobs, on deliberately different cadences.
 |
 | They are separated because they cost different amounts and matter on
 | different timescales. Bundling them into one tick would make the cheap,
 | urgent one wait for the expensive, patient one - which is how a queue ends
 | up stalled behind a sweep that had nothing to do.
 */

// The queue. Every minute is the shortest the scheduler offers, and routing is
// two queries plus one write per assignment, so this is cheap.
Schedule::call(fn () => app(RouteQueuedConversations::class)->handle())
    ->everyMinute()
    ->name('route-queued-conversations')
    // A run that overlaps itself would take a second workload snapshot while
    // the first is still assigning from a stale one, and two conversations
    // could land in the same agent's last free slot.
    ->withoutOverlapping();

// Reclaiming work from agents who have gone. Not every minute: the presence
// circuit already holds a loss for its grace period, so a sweep more frequent
// than that would mostly find nothing.
Schedule::call(fn () => app(ReleaseOrphanedConversations::class)->handle())
    ->everyFiveMinutes()
    ->name('release-orphaned-conversations')
    ->withoutOverlapping();

// Archiving. Nothing here is urgent by the time it qualifies - the thresholds
// are measured in days - so it runs once at a quiet hour.
Schedule::call(fn () => app(ArchiveStaleConversations::class)->handle(
    waitingCustomerDays: (int) config('support.sweeps.waiting_customer_days', 7),
    reopenWindowDays: (int) config('support.sweeps.reopen_window_days', 3),
))
    ->dailyAt('03:30')
    ->name('archive-stale-conversations')
    ->withoutOverlapping();
