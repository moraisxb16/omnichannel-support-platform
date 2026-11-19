# Beacon Support

An omnichannel support platform in Laravel and Vue, built around the question
every support queue eventually fails at: **who should take this conversation,
and what happens when that person disappears?**

Independent portfolio project. Fictional company, synthetic data, original code
— see [Independence](#independence).

---

## The engineering problem

Routing a conversation to an agent looks like picking the least busy person. It
is not, and the gap between those two statements is where support systems break:

- **Every agent is at their limit.** Assign anyway and all their conversations
  get slower; refuse and the queue grows with no explanation attached to it.
- **An agent's connection drops for twenty seconds.** Act on it and their
  conversations are torn away and handed to colleagues who lack the context;
  ignore it and work sits with someone who went home an hour ago.
- **An agent is assigned a conversation and then goes offline.** It is not in
  the queue, so the distributor never looks at it. It has an owner, so nobody
  else feels responsible. The customer waits; the dashboard looks healthy.
- **A channel stops accepting free-form replies after 24 hours.** The agent
  types a careful answer, sends it, and the provider drops it. The agent
  believes they replied. The customer never heard from anyone.
- **A customer replies to a resolved conversation.** Treat resolved as final and
  that reply becomes a new ticket with no history, answered by someone who does
  not know the first answer already failed.

Each of those is a mechanism in this repository, with a test and a runnable
demonstration.

---

## Architecture

```
  Channel providers                    ┌──────────────────────────────┐
  (widget · email · social)            │  conversations               │
         │                             │  ──────────────────────────  │
         │  inbound webhook            │  status                      │
         ▼                             │  agent_id                    │
  ┌────────────────────┐               │  last_customer_message_at    │
  │ RecordInbound      │  UNIQUE on    │  no_agent_reason             │
  │ Message            │──provider_id──│  routing_attempts            │
  └────────────────────┘  (dedupe)     └──────────┬───────────────────┘
                                                  │
                 ┌────────────────────────────────┤
                 │                                │
       ┌─────────▼───────────┐        ┌───────────▼────────────┐
       │ RouteQueued         │        │ TransitionConversation │
       │ Conversations       │        │  (single authority)    │
       └─────────┬───────────┘        └───────────┬────────────┘
                 │                                │ writes timeline in
    ┌────────────▼─────────────┐                  │ the SAME transaction
    │ RoundRobinDistributor    │                  ▼
    │  · online only           │        ┌──────────────────────┐
    │  · least loaded BY RATIO │        │ conversation_events  │
    │  · longest since last    │        └──────────────────────┘
    │  · hard capacity ceiling │
    └──────────────────────────┘
                 ▲
                 │  snapshot (2 queries, taken once per run)
    ┌────────────┴─────────────┐      ┌─────────────────────────────┐
    │ agents                   │      │ ReleaseOrphanedConversations│
    │  presence · capacity     │◀─────│  (owner went offline)       │
    └──────────────────────────┘      └─────────────────────────────┘
                 ▲
    ┌────────────┴─────────────┐
    │ ReconciliationCircuit    │  a LOSS is provisional for 45s;
    │  (presence debounce)     │  everything else applies at once
    └──────────────────────────┘
```

Dependencies point inward. `app/Domain/` has no database, no clock, no Eloquent:

| Layer | Contents |
|---|---|
| `Domain/Conversation` | `ConversationStatus`, `ConversationTransitions` |
| `Domain/Routing` | `RoundRobinDistributor`, `AgentWorkload`, `NoAgentReason` |
| `Domain/Presence` | `PresenceState`, `ReconciliationCircuit` |
| `Domain/Messaging` | `ReplyWindow`, `SendKind` |
| `Actions/` | one use case per class, orchestrating the domain |
| `Models/` | Eloquent, converting rows into the value objects above |

`RoundRobinDistributor` is a pure function from a list of `AgentWorkload` to a
decision. That is why "six conversations, three agents, one already at their
ceiling" is a unit test rather than a staging experiment.

---

## Stack

| Choice | Why |
|---|---|
| **Laravel 13** | The scheduler, the container and Eloquent are the parts actually used. The domain does not depend on any of them. |
| **Vue 3 + Inertia** | The inbox is a server-rendered app with client interactivity, not an API with a separate SPA. Inertia removes the API layer that would exist only to serve one frontend. |
| **SQLite / MySQL** | Tests run in memory. The schema is engine-neutral. |
| **Tailwind 4** | The interface exists to show load ratios and window states, so most of its CSS is a handful of utility classes. |
| **PHPUnit** | 81 tests. Domain rules as unit tests, everything else against a real database. |

---

## What is implemented

**Distribution with a hard capacity ceiling.** Least loaded by *ratio*, not by
raw count — a senior holding 8 of 12 has more room than a trainee holding 3 of
3. Ties break on who has waited longest, then on id so the result is
deterministic and explainable.

**Refusals that say why.** `nobody_online`, `all_at_capacity`,
`no_matching_skill`, `outside_office_hours`. These are different operational
problems with different fixes, and the reason is stored on the conversation and
shown in the inbox rather than inferred from an empty queue.

**Presence debouncing.** A loss is provisional for a grace period; deliberate
states apply at once. A tunnel costs nothing.

**The orphan sweep.** Conversations held by an offline agent return to the
queue. A sweep rather than a disconnect handler, because an agent whose laptop
lost power never emits a disconnect.

**Idempotent inbound messages.** Deduplicated on the provider's message id by a
`UNIQUE` constraint. A redelivery does not re-store the message, does not
reopen the reply window and does not revive a resolved conversation twice.

**The reply window, enforced before sending.** Computed up front and surfaced in
the inbox list, so an agent sees "template only" before opening the thread
rather than after the provider silently drops their reply.

**Reopen without losing history.** `resolved` is not terminal. A customer reply
revives the same conversation. Only `archived` is terminal, and exactly one
sweep produces it.

**A single transition authority.** Nothing else writes `status`. Every change
locks the row, validates against the table, and writes its timeline entry in the
same transaction.

---

## Demonstration

```bash
php artisan support:demo
php artisan support:demo --only=limbo
```

Six scenarios, each on a throwaway database, driving the real actions and
asserting the outcome. Exits non-zero on failure, so it runs in CI.

```
== distribution Conversations spread across agents and stop at the capacity ceiling
   agents: Alice (0/3), Bruno (0/2), Cora (0/1) - total headroom 6
   routing: {"considered":6,"assigned":6,"unassigned":0,"reasons":[]}
      Alice  holds 3 of 3
      Bruno  holds 2 of 2
      Cora   holds 1 of 1
   seventh conversation: {"all_at_capacity":1}
   PASS

== limbo An agent going offline does not strand their conversations
   conversation assigned to Alice
   Alice goes offline while holding it (no disconnect event)
   is it orphaned? yes
   presence sweep: {"swept":1,"released":1}
   picked up again by Bruno
   PASS

== presence A flapping connection does not churn the queue
   socket drops at t+0  -> applied=no, believed in 45s
   reconnects at t+5    -> back online, nothing was ever reclaimed
   silent for 120s      -> applied=yes
   presses "away"       -> applied=yes (immediate)
   PASS

== window Outside the reply window, free text is refused and a template is not
   inside the window -> free_form
   25 hours later    -> template_only
   free text refused: Conversation CV-C1IFHMF5 is outside the 24-hour reply window
   approved template accepted
   PASS

== transitions An illegal state change is refused, and the timeline records every legal one
   refused: Conversation CV-2NGDP4DO cannot move from queued to waiting_customer.
   customer replies after resolution -> reopened=yes
   archived -> queued refused: yes
   timeline:
      queued           -> assigned         by distributor
      assigned         -> resolved         by agent:Alice
      resolved         -> queued           by customer
      queued           -> assigned         by distributor
      assigned         -> archived         by demo
   PASS
```

---

## Running it

Requires PHP 8.3+, Composer and Node 20+.

```bash
git clone https://github.com/moraisxb16/omnichannel-support-platform.git
cd omnichannel-support-platform

composer install
npm install
cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate --seed

npm run build
php artisan serve
```

The inbox is at `http://localhost:8000`. The seed creates five fictional agents
with deliberately uneven ceilings and eight conversations, leaving a couple
queued so the "why is this not moving" state is visible on first load.

**The scheduled work**, which normally runs on the scheduler:

```bash
php artisan schedule:work          # distributor + sweeps
```

**Tests:**

```bash
php artisan test                           # 81 tests
php artisan test --testsuite=Unit          # pure domain, no I/O
php artisan test --testsuite=Feature       # real database, real HTTP
```

---

## Technical decisions

1. [Capacity is a hard ceiling, never a preference](docs/decisions/0001-capacity-is-a-hard-ceiling.md)
2. [A presence loss is provisional; everything else is immediate](docs/decisions/0002-presence-losses-are-debounced.md)
3. [`resolved` is not terminal; only `archived` is](docs/decisions/0003-resolved-is-not-terminal.md)
4. [Routing runs on a schedule, not on arrival](docs/decisions/0004-routing-runs-on-a-schedule.md)

---

## Trade-offs and limits

**Routing latency.** A conversation waits up to one scheduler tick before it has
an owner. Triggering a run on arrival, with the loop as the safety net, would be
better and is not done here — two trigger paths would have obscured the queue,
which is the part worth reading.

**No real-time transport.** The inbox does not push updates; it reloads. Adding
broadcasting would be a dependency and some wiring, and would not have changed
any of the decisions above. The presence model is built as though the transport
exists, because that is where the design work is.

**Sending is not wired to a provider.** `SendAgentReply` enforces the window and
stores the message; there is no HTTP client behind it. The window is the
interesting half and it is fully tested.

**No authentication.** Synthetic data, no deployment. See [SECURITY.md](SECURITY.md).

**The timeline grows without bound.** Every transition is a row, forever.
Production would need partitioning or retention.

**Office hours are modelled as a refusal reason but not enforced.**
`NoAgentReason::OUTSIDE_OFFICE_HOURS` exists and nothing produces it yet.

---

## Roadmap

Not implemented. Listed to be explicit about the boundary.

- Broadcasting for live inbox updates and real presence transport
- Office-hours calendars per team
- Transfer between agents with a reason, and supervisor reassignment
- Handling-time metrics to calibrate capacity from data rather than by hand
- A provider adapter behind `SendAgentReply`

---

## Independence

Independent portfolio project, written from scratch to demonstrate techniques
that are public knowledge: work distribution, capacity ceilings, presence
debouncing, state machines, idempotent webhook handling.

It is not connected to, derived from, or a copy of any employer's system. The
company (**Beacon Support**), the channels, the agents and the customers are all
invented, and every name in the seed is fictional. No proprietary routing rule,
schema, credential or integration contract appears anywhere in this repository.

MIT licensed. See [LICENSE](LICENSE).

---

<details>
<summary><strong>Resumo em português</strong></summary>

Plataforma de atendimento omnichannel em Laravel e Vue, construída em torno da
pergunta em que toda fila de atendimento falha: **quem deve pegar esta conversa,
e o que acontece quando essa pessoa desaparece?**

- **Distribuição com teto de capacidade rígido** — menos carregado por *razão*,
  não por contagem: um sênior com 8 de 12 tem mais folga que um trainee com 3 de
  3. Empate vai para quem esperou mais, depois por id, para o resultado ser
  determinístico e explicável
- **Recusas que dizem o porquê** — "ninguém online" e "todos no teto" são
  problemas operacionais diferentes, com soluções diferentes; o motivo fica
  gravado na conversa e aparece no inbox
- **Presença com circuito anti-oscilação** — uma queda só é acreditada depois de
  45s; estados deliberados valem na hora. Um túnel não custa nada
- **Varredura de órfãs** — conversa presa com operador offline volta para a
  fila. Varredura, não handler de desconexão: um notebook sem bateria nunca
  avisa que caiu
- **Mensagens de entrada idempotentes** — dedupe por constraint UNIQUE no id do
  provedor; reentrega não reabre a janela nem revive a conversa duas vezes
- **Janela de resposta verificada antes do envio** — e exibida na lista, para o
  operador ver "só template" antes de abrir a conversa, não depois do provedor
  descartar a resposta em silêncio
- **Reabertura sem perder histórico** — `resolved` não é terminal; só `archived`
  é, e uma única varredura o produz
- **Autoridade única de transição** — nada mais escreve `status`; toda mudança
  trava a linha, valida contra a tabela e grava a timeline no mesmo commit

`php artisan support:demo` roda seis cenários. `php artisan test` roda 81 testes.

Projeto independente de portfólio, com empresa fictícia e dados sintéticos.
Não é código de nenhum empregador.

</details>
