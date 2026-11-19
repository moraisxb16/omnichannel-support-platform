<?php

declare(strict_types=1);

use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

/*
 | The inbox is a read-mostly view onto the queue. The two POST routes exist so
 | the scheduled work can be triggered by hand during a demonstration: the
 | distributor and the presence sweep normally run on a schedule, and waiting a
 | minute to see them act does not demonstrate much.
 |
 | There is no authentication here. This is a portfolio project with synthetic
 | data and no deployment; adding a login screen would be boilerplate that
 | demonstrates nothing. See SECURITY.md.
 */

Route::get('/', [InboxController::class, 'index'])->name('inbox');
Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->name('conversations.show');

Route::post('/route', [InboxController::class, 'route'])->name('inbox.route');
Route::post('/sweep', [InboxController::class, 'sweep'])->name('inbox.sweep');
