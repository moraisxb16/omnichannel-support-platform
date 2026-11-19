# Security

A portfolio project with no deployment, no users and no real data. There is
nothing to report a vulnerability against. What follows is the reasoning behind
the security-relevant choices, and an honest account of what is deliberately
missing.

## What is deliberately missing

**There is no authentication.** The inbox is open. In a real deployment this is
the first thing that would have to exist: an agent must only see conversations
their team handles, and `/route` and `/sweep` are operational actions that
should require a supervisor.

That is a real gap and it is stated here rather than implied by a login screen
that proves nothing. The repository exists to demonstrate routing, presence and
queue mechanics, and Laravel's authentication is well-trodden ground that would
have added files without adding an idea.

**Nothing is sent anywhere.** `SendAgentReply` enforces the reply window and
stores the message; there is no provider client behind it. No outbound request
leaves the application, so there are no credentials to protect.

## What is handled

**Mass assignment.** Every model declares `$fillable`. `status` is on the
`Conversation` fillable list because the transition authority writes it through
`update()`, but no controller accepts user input into it — the only paths that
change status are `TransitionConversation` and the sweeps.

**SQL.** All queries go through Eloquent or the query builder with bindings. The
one raw fragment, `DB::raw('COUNT(*) as total')`, contains no user input.

**Status filtering from the query string.** `InboxController::index()` takes
`?status=` from the request and passes it to a `where`. It is bound, so it
cannot inject; an unknown value simply matches nothing. It is not validated
against the enum, which would be the stricter choice and is a reasonable thing
to flag in review.

**Route model binding.** `/conversations/{conversation}` resolves through
Laravel's binding, so a missing id is a 404 rather than an error page. With
authentication in place this is also where an authorization check would belong,
to stop one agent reading another team's conversation by changing the id.

**Lazy loading is prevented in local.** `Model::preventLazyLoading()` is on
outside production. In a routing system an unloaded `agent` relation reads as
"this conversation has no owner", and the orphan sweep would then reclaim work
from agents who are perfectly present. Failing loudly in development is cheaper
than that bug reaching a queue.

**The timeline is append-only by convention.** `ConversationEvent` has no update
path and `$timestamps` is disabled in favour of an explicit `occurred_at`. An
audit trail that can be edited answers nothing.

## Data

Everything in `DatabaseSeeder` is invented — agents, customers, messages and the
`@beacon.invalid` addresses, which use a reserved TLD that can never resolve.

No personal data of any real person is present in this repository, and none is
collected by it.

`.env` is gitignored. `.env.example` contains only Laravel's defaults and
placeholders.
