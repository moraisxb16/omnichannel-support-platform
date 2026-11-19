# 0004 — Routing runs on a schedule, not on arrival

**Status:** accepted

## Context

A conversation arrives. The obvious move is to route it right there in the
request: it feels immediate, and the customer gets an owner in milliseconds.

The problem is the empty case. When every agent is at capacity, or nobody is
online, routing on arrival has nothing to do — and now something else has to
notice that conversation later and try again. That something is a loop over the
queue, which is the thing routing-on-arrival was meant to avoid building.

So the choice is not "on arrival" versus "on a schedule". It is "one path" versus
"two paths that must agree".

## Decision

`RouteQueuedConversations` runs on a schedule and is the only thing that
assigns. Arrival puts a conversation in `QUEUED` and stops.

## Consequences

**Good.** One code path, exercised constantly rather than only during an
incident. The "nobody was available" branch is the normal branch on a busy
afternoon, so it is well tested by simply being used.

The run also takes its workload snapshot **once** and updates it in memory as it
assigns. Routing per arrival cannot do that: each request would read counts that
a concurrent request is about to change, and two conversations could be handed to
the same agent's last free slot.

**Bad.** Latency. A conversation waits up to one scheduler tick before it has an
owner. For support that is acceptable — the agent has to read it either way —
but it would not be for anything where the assignment itself is the product.

**Mitigation available, not taken.** The schedule could be supplemented by
triggering a run after an arrival, keeping the loop as the safety net. That is
genuinely better and is on the roadmap; it was left out here because the
interesting part is the queue, and two trigger paths would have obscured it.

**Related.** The same reasoning produced `ReleaseOrphanedConversations` as a
sweep rather than a disconnect handler: an agent whose laptop lost power never
emits a disconnect, so something has to come looking.
