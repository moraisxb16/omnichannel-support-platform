# 0002 — A presence loss is provisional; everything else is immediate

**Status:** accepted

## Context

Presence comes from a socket, and sockets drop. An agent on a train produces a
dozen offline/online pairs going through one tunnel. A laptop sleeping for
fifteen seconds looks exactly like someone closing their laptop and going home.

Acting on every observation has a real cost, because "this agent is offline"
triggers a sweep that takes their conversations away and hands them to
colleagues. A flapping connection therefore produces: conversations reclaimed,
redistributed, agent reconnects, agent is idle, colleagues are now over capacity
holding threads they have no context for, and the customer who was mid-exchange
gets a different person asking them to explain again.

## Decision

`ReconciliationCircuit` debounces in one direction only.

A transition **to OFFLINE** is provisional until it has held for
`presence_grace_seconds` (45 by default). Any other transition — to ONLINE, to
AWAY — applies immediately.

The asymmetry is the whole design. Losses are inferred from absence and are
frequently wrong. Every other state is something a person did on purpose, and
obeying it immediately costs nothing: nobody loses work because an agent pressed
"away".

## Consequences

**Good.** A tunnel costs nothing. The queue stops churning. Agents stop finding
their conversations gone after a brief disconnection.

**Bad.** There is a window — up to the grace period — where an agent who really
has gone still holds conversations nobody is reading. The sweep is late by at
most that long.

**Tuning.** Too low and the churn comes back. Too high and work sits with
someone who left. 45 seconds is a starting point, not a measured optimum, and it
is configuration rather than a constant for exactly that reason.

**A heartbeat resets it.** `UpdateAgentPresence` writes `last_seen_at` on any
non-offline observation. Without that, an agent online for an hour would always
be "overdue" and the first blip after would be believed instantly.

**Related.** AWAY deliberately does not release held work — see
`PresenceState::releasesHeldWork()`. Someone at lunch comes back to their own
queue.
