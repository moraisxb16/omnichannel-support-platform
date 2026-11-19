<?php

declare(strict_types=1);

return [

    /*
     | The customer-initiated reply window, in hours.
     |
     | Inside it an agent may send anything; outside, only an approved template.
     | The clock runs from the customer's LAST message, so every inbound reply
     | reopens it. 24 hours is the common shape across messaging channels.
     */
    'reply_window_hours' => (int) env('SUPPORT_REPLY_WINDOW_HOURS', 24),

    /*
     | How long a presence loss must hold before it is believed, in seconds.
     |
     | Sockets drop. Acting on every blip reclaims an agent's conversations and
     | hands them to colleagues, only for the agent to reconnect seconds later.
     | Too low and the queue churns; too high and work sits with someone who
     | genuinely left.
     */
    'presence_grace_seconds' => (int) env('SUPPORT_PRESENCE_GRACE_SECONDS', 45),

    /*
     | Default ceiling on concurrent conversations per agent.
     |
     | A hard limit, never a preference. Handing an agent a seventh conversation
     | because nobody else is free does not get it answered sooner; it gets all
     | seven answered slower.
     */
    'default_agent_capacity' => (int) env('SUPPORT_DEFAULT_CAPACITY', 5),

    'sweeps' => [
        // Close a conversation waiting on a customer who never came back.
        'waiting_customer_days' => (int) env('SUPPORT_WAITING_DAYS', 7),
        // How long a resolved conversation stays reopenable.
        'reopen_window_days' => (int) env('SUPPORT_REOPEN_DAYS', 3),
    ],

];
