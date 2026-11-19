<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Messaging\ReplyWindow;
use App\Domain\Presence\ReconciliationCircuit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The two domain policies are built from configuration and have no
        // dependencies of their own, so they are registered as singletons and
        // injected rather than instantiated at each call site. That is what
        // lets a test swap in a two-hour window without touching the callers.
        $this->app->singleton(
            ReplyWindow::class,
            static fn (): ReplyWindow => new ReplyWindow(
                (int) config('support.reply_window_hours', 24),
            ),
        );

        $this->app->singleton(
            ReconciliationCircuit::class,
            static fn (): ReconciliationCircuit => new ReconciliationCircuit(
                (int) config('support.presence_grace_seconds', 45),
            ),
        );
    }

    public function boot(): void
    {
        // Fail loudly on a missing relationship instead of silently returning
        // null. In a routing system an unloaded `agent` relation reads as "this
        // conversation has no owner", and the sweep then reclaims work from
        // agents who are perfectly present.
        Model::preventLazyLoading($this->app->isLocal());
        Model::preventAccessingMissingAttributes($this->app->isLocal());
    }
}
