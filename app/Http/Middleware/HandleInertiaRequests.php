<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props shared with every page.
     *
     * @return array<string,mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            // A closure, not a value: it is resolved per response, so a flash
            // message set during the current request is included rather than
            // the one left over from the previous.
            'flash' => fn (): ?string => $request->session()->get('flash'),
        ]);
    }
}
