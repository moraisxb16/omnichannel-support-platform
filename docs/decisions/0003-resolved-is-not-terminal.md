# 0003 — `resolved` is not terminal; only `archived` is

**Status:** accepted

## Context

An agent answers and marks the conversation resolved. Two days later the
customer writes back: "that did not work."

If `resolved` is terminal, that reply cannot join the conversation. It becomes a
new one, and whoever picks it up starts from nothing — no history, no previous
answer, no knowledge that this is the second attempt. The customer explains
everything again, and the new agent may well give the same answer that already
failed.

## Decision

`ConversationStatus::RESOLVED` has an outgoing transition back to `QUEUED`. An
inbound message on a resolved conversation revives it in place, keeping the
thread and the history.

`ARCHIVED` is the only terminal state, and `ArchiveStaleConversations` is the
only thing that produces it — after the reopen window has expired, or after a
customer has been silent too long.

## Consequences

**Good.** The reopen is the natural shape of support: a customer following up is
continuing one exchange, not starting two.

**Bad.** "Resolved" stops being a reliable count. A conversation resolved today
may be open again tomorrow, so any metric built on resolutions has to be defined
over a window, not as a running total.

**Why a reopen window at all.** Without one, a conversation from eight months ago
could be revived by a stray reply and land in the queue with context nobody
remembers. The window bounds that — three days by default — and after it the
conversation archives and a reply starts a genuinely new one.

**One place archives.** Archiving is the only irreversible act in the system, so
exactly one action performs it and the timeline always names which sweep did.
That means "why is this closed?" has an answer, which is not true of a status
that several code paths can set.
