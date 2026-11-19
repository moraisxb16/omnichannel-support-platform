# 0001 — Capacity is a hard ceiling, never a preference

**Status:** accepted

## Context

Every agent has a number: how many conversations they can hold at once. The
question is what happens when a conversation arrives and everyone is at that
number.

The tempting answer is to assign it anyway, to whoever is least loaded. It feels
responsive — the conversation is moving, somebody owns it, the queue is empty.
A supervisor looking at a dashboard sees nothing waiting.

## Decision

The ceiling is absolute. An agent at capacity is removed from the pool entirely,
and if that empties the pool the conversation stays queued with
`no_agent_reason = all_at_capacity` recorded against it.

## Consequences

**Why not just assign it.** Handing an agent a seventh conversation does not get
it answered sooner. It gets all seven answered more slowly, because the agent
now context-switches between seven threads instead of six. The customer who
would have waited two minutes for the next free agent waits twenty — and
meanwhile the six people already in that agent's queue wait longer too. The cost
is paid by everyone, and it is invisible, because the dashboard shows an empty
queue and seven "active" conversations.

A visible queue is better than a hidden one. A supervisor can add staff, raise a
ceiling or lower expectations when the number in the queue is real.

**The ceiling is per agent.** One global number tunes for the weakest member of
the team, which either overloads a trainee or under-uses a senior. Per agent
costs a column and makes the load comparison a ratio rather than a count — see
`AgentWorkload::loadFactor()`.

**Bad.** Someone has to set those numbers, and a wrong one is invisible until
the queue backs up. There is no automatic calibration here; it would need
handling-time data this project does not model.

**Guarded by** `RoundRobinDistributorTest::the_capacity_ceiling_is_hard` and
`ConversationRoutingTest::it_spreads_work_and_stops_at_each_ceiling`.
