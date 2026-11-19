<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

/**
 * What may be sent to a customer at this moment.
 *
 * Two values rather than a boolean, because "you cannot reply" is wrong and
 * unhelpful: outside the window the agent can still reach the customer, just
 * only through an approved template. An interface built on a boolean shows a
 * disabled box; one built on this shows a template picker.
 */
enum SendKind: string
{
    /** Inside the window. Anything may be sent. */
    case FREE_FORM = 'free_form';

    /** Outside it. Only a pre-approved template will be accepted. */
    case TEMPLATE_ONLY = 'template_only';

    public function allowsFreeText(): bool
    {
        return $this === self::FREE_FORM;
    }

    public function describe(): string
    {
        return match ($this) {
            self::FREE_FORM => 'The reply window is open; any message may be sent.',
            self::TEMPLATE_ONLY => 'The reply window has closed; only an approved template will be delivered.',
        };
    }
}
