# Monetization Plan

Status: **plan only, nothing built.** Branch `GameNight-Monetization`, drafted 2026-08-08 against v0.2064 (`db68d0e`).

This document covers the legal constraints that shape the product, the current state of the
subscription scaffolding already in the codebase, and a phased build plan. Phases 1 and 2 ship
without any payment processor; Phase 3 adds Stripe.

---

## 1. Current state

Live production numbers as of 2026-08-08:

| Metric | Count |
|---|---|
| Users | 106 |
| Leagues | 13 |
| League memberships | 272 |
| Events | 49 |
| Event invites | 276 |
| Messages sent (`sms_log`) | 1,395 |

Tier distribution: **99 Free, 6 OriginalSupporters, 1 Personal**.
League ownership: 11 distinct owners, 10 of whom own exactly one league (user 6 owns 3).
That spread is the argument for a per-user Personal tier rather than per-league pricing.

### What already exists

Subscription tier scaffolding shipped deliberately (see `CHANGELOG.md`, the "Subscription tier
scaffolding (no gating yet)" entry):

- **Schema** on `users`: `tier TEXT NOT NULL DEFAULT 'Free'`, `tier_expires_at DATETIME`,
  `tier_source TEXT`, `tier_granted_by INTEGER` (`db.php:870-877`).
- **Tiers**: `Free` (0), `Personal` (1), `League` (2), `OriginalSupporters` (honorary, normalized
  to League rank inside the helper).
- **Helpers**: `tier_rank()`, `tier_at_least()` (`db.php:3067-3089`).
- **Admin UI**: sortable Tier column with an inline dropdown in the Users grid
  (`admin_settings.php`), routing through `update_user`, stamping `tier_source='manual'` and
  `tier_granted_by`, logged to `activity_log`.
- `tier_source` already reserves the value `'stripe'`; `tier_expires_at` is documented as
  reserved for billing integration.

### What does not exist

- **`tier_at_least()` has zero call sites.** Nothing anywhere is gated.
- **`tier_expires_at` is never read.** An expiry written by any future billing code would be
  silently ignored, so a lapsed subscriber would keep full access. This must be fixed before
  billing lands, not after.
- No Stripe integration, no pricing page, no subscription records.

---

## 2. Legal constraints (not legal advice)

US-centric. Get an hour with a gaming or fintech attorney in-state before taking real volume.

### The test

Most US states define gambling as **prize + chance + consideration** together. Remove any one and
it stops being gambling. The site supplies none of the three: it schedules, reminds, times, checks
in, and records results. Players supply consideration and prize, in person, to each other. That
separation is the whole legal position and it is a strong one.

### The four ways to lose it

1. **Taking a cut of the pot.** The brightest line in state law. Statutes on operating a gambling
   business hinge on profiting *from the game* rather than participating in it. A flat software fee
   is not a rake no matter how large; a percentage is, no matter how small. **Never price as a
   percentage of buy-ins, pot size, prize pool, or per-player-per-money-game.**
2. **Holding anyone's money.** Custody of funds you do not own makes you a money transmitter:
   FinCEN MSB registration federally, plus per-state licensing with bonding and capital
   requirements. There is no small version of this. Out of scope permanently.
3. **Processing gambling payments.** UIGEA (2006) targets processors accepting funds in connection
   with unlawful internet gambling. Helps us: games are played in person, so they are not internet
   gambling. Hurts us: moving a buy-in online adds an internet payment leg to a social game, and
   processors are exactly who UIGEA reaches.
4. **Facilitating games illegal where played.** Home game legality varies by state; many have a
   social gambling exemption where nobody profits except as a player. `terms.php` should state that
   users represent their games are lawful where held, that the site never handles funds, and that
   no rake is taken.

### Decision

**Hard line: software fees only.** The site never touches buy-ins, pots, jackpot money, or payouts.
Existing payout / jackpot / bounty tracking stays **descriptive** (who owes what, who won what) and
never becomes **transactional** (no payment links, no Venmo/Cash App deep links, no custody).

Rejected middle option: a settle-up ledger with payment links. It never holds funds so its legal
risk is modest, but it puts gambling-payment flows inside the same product Stripe processes
subscriptions through, which raises account-termination risk far more than it raises legal risk.
The utility over the existing payout tracking is thin.

### The practical risk: processor account termination

More likely to actually happen than any regulatory action. Stripe's prohibited business list
includes gambling and gaming, and their risk team reads domain names. **`gamenight.poker` will get
a second look.** Mitigations:

- Apply describing the business accurately: recurring private event scheduling, member management,
  notifications, and scorekeeping for social clubs and leagues. Not "poker platform."
- Keep pot / buy-in / wager language off the pricing and checkout pages. What the app does after
  login is a far weaker signal than what the marketing pages say.
- Have a one-paragraph answer ready leading with "no funds related to gameplay ever touch our
  platform."
- Know the fallback: Paddle (merchant of record, absorbs tax) or Lemon Squeezy. Do not assume a
  processor can be swapped in a day.

### Audience differences

- **Bars and venues are the safest segment**, and they have budget. The standard bar-league model is
  free entry with venue-provided prizes: no consideration, therefore not gambling in any state.
  They want the walk-in display, cast receiver, branding, and public standings more than home hosts
  do.
- **Charity tournaments** carry separate per-state charitable-gaming licensure. That burden is on
  the organizer, not on a software vendor. Sell the tool, never advise on their compliance.
- **Home hosts** carry the residual risk, small so long as we never touch money and the terms are
  clean.

---

## 3. Build plan

### Phase 0: the governing decision

**Entitlements resolve through the resource owner, not the acting user.**

A league event's SMS blast is allowed because the *league owner* pays, not because whoever clicked
Send pays. A co-manager on Free running a paid league's game night must never hit a paywall.
Getting this backwards paywalls the wrong person and reads as a bug rather than a pitch.

| Feature lives on | Resolves via |
|---|---|
| A league (members, SMS, seasons, branding, API keys, public page) | `leagues.owner_id` then that user's tier |
| A person (leagues you may own, personal event limits) | the acting user's tier |

### Phase 1: make tier real (no Stripe, no paywall)

All in `db.php`, roughly 60 lines:

```php
effective_tier(array $user): string           // honors tier_expires_at; expired => 'Free'
league_tier(int $league_id): string           // tier of leagues.owner_id, per-request cached
entitlement(string $key, ?int $league_id): bool  // the single gate
const ENTITLEMENTS = ['sms.send' => 'League', 'league.seasons' => 'League', ...];
```

Design notes:

- **`effective_tier()` must land first.** `tier_expires_at` is currently dead, so any billing code
  that writes an expiry would be a silent no-op.
- **A named-key entitlement map beats scattered `tier_at_least()` calls.** Sixty call sites
  comparing tier strings makes repricing a grep-and-pray; one array makes moving a feature between
  tiers a one-line diff, and it doubles as the source of truth for the pricing page so copy cannot
  drift from behavior.
- Ship a shared amber-styled upgrade prompt (`_upgrade_cta.php`, warning colors per the CLAUDE.md
  UI convention) so every gate refuses identically.

**Independently useful:** with the admin comp grants that already exist, gates can be turned on and
validated against the 6 Original Supporters before any payment processor exists.

### Phase 2: choose the gates

Proposed, arguing from what costs money or signals a serious host:

| Capability | Free | Personal | League |
|---|---|---|---|
| Events, RSVPs, timer, check-in, walk-in display | yes | yes | yes |
| Email notifications | yes | yes | yes |
| **SMS / WhatsApp notifications** | no | no | **yes** |
| Leagues you may own | 0 | 1 | unlimited |
| Members per league | n/a | 12 | unlimited |
| Seasons, standings, historical stats | no | current season only | full history |
| Payout presets, bounties, jackpot ledger | no | yes | yes |
| Custom timer themes, sponsor slots, public league page | no | no | yes |
| Read-only API keys | no | no | yes |

SMS is the anchor: the only line item with a real per-message cost, and the most compelling reason
a host upgrades, because chasing RSVPs by text is the actual job.

**Open question:** SMS at League-only is the difference between a $5 and a $19 upgrade for a solo
host. Moving it to Personal widens conversion but removes the main reason to reach League.

#### Grandfathering (the actual risk in this phase)

All 106 existing users can do everything today, so every gate above takes something away from
someone currently using it. Do not handle this with a changelog promise:

- Add `users.tier_legacy INTEGER NOT NULL DEFAULT 0`.
- Stamp it on every account existing at cutover.
- `effective_tier()` treats legacy accounts as League.

One column, one condition, and the first 106 users never see a paywall. New signups land on real
tiers.

### Phase 3: Stripe

**Hosted Checkout plus the hosted Customer Portal. No Stripe.js, no card fields, no client SDK.**

Three problems solved at once: `auth.php` sets a strict CSP that embedded Elements would force us
to loosen; card data never touches the server, keeping us at PCI SAQ-A; and the Portal provides
cancellation, card updates, and invoice history for zero code. The integration is two server-side
API calls and one webhook.

**Schema** (new, in `db_init()`):

```sql
CREATE TABLE IF NOT EXISTS subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  stripe_customer_id TEXT,
  stripe_subscription_id TEXT UNIQUE,
  price_id TEXT,
  tier TEXT NOT NULL,
  status TEXT NOT NULL,
  current_period_end DATETIME,
  cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS stripe_events (
  id TEXT PRIMARY KEY,          -- Stripe event id, for idempotency
  type TEXT NOT NULL,
  received_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

`users.tier` remains the read path so every gate keeps working; the webhook writes it with
`tier_source='stripe'`.

**Files:**

| File | Role |
|---|---|
| `www/billing.php` | plan picker, current subscription state, Portal link |
| `www/billing_dl.php` | `create_checkout_session`, `create_portal_session` (POST + CSRF, standard pattern) |
| `www/stripe_webhook.php` | unauthenticated POST, signature-verified |

The webhook deliberately breaks the `_dl.php` convention (no `require_login()`, no `csrf_verify()`).
That is consistent rather than exceptional: `sms_webhook.php` and `wa_webhook.php` already have
exactly this shape.

**Correctness rules:**

- **Stripe is the source of truth for paid tiers; the DB is a cache.** On
  `checkout.session.completed`, `customer.subscription.updated`, and `customer.subscription.deleted`,
  rewrite `users.tier` and `tier_expires_at` from the event payload.
- **Never let a webhook stomp a manual grant.** Original Supporters and admin comps carry
  `tier_source='manual'`. The webhook writes only when `tier_source='stripe'` or the user has a
  `subscriptions` row. Otherwise a stray event demotes the six most loyal users on the site.
- **Idempotency is mandatory.** Stripe retries. Insert into `stripe_events` first; a duplicate
  primary key means return 200 and do nothing.
- **SQLite write contention**: webhook volume is a handful of events per day, and `busy_timeout` is
  already set. A note, not a concern.
- **No new container or service.** Outbound HTTPS plus one inbound route, which matters on a box
  with roughly 620MB free RAM.

**Tax is a loose end to close before the first sale:** several US states tax SaaS. Either enable
Stripe Tax or move to Paddle as merchant of record and hand them the problem.

### Phase 4: the funnel

- **Pricing page generated from the `ENTITLEMENTS` map**, so marketing copy cannot drift from
  behavior. Language stays "private event and league management."
- **`_landing.php`** (139 lines today) gets a real value proposition and a route into signup. This
  is a rewrite, not an edit.
- **The standalone tournament timer as top-of-funnel**, from the existing fork at
  `~/Claude/GameNight-TournamentTimer`: no signup, works on a TV, a quiet "powered by
  gamenight.poker", and a save-your-blind-structure prompt that lands on registration. Every home
  game needs a timer and most free ones are ugly, which makes this the cheapest acquisition channel
  available.

### Phase 5: metering (deferred deliberately)

Per-league message metering means threading a league id through **18 `send_notification()` call
sites and 42 direct `send_sms()` / `send_whatsapp()` calls**, or setting an ambient billing context
per request that the primitives read. Both are real work and neither pays off until one league's
volume actually costs money. `sms_log` already records every send, so "is this a problem yet?" is a
query away. Revisit when the answer is yes.

### Phase 6: the venue package

Sell by hand first, to one bar or one charity, with an admin comp grant instead of a Stripe price.
Most of what they need already exists (`walkin_display.php`, `cast_receiver.php`,
`league_public.php`, timer themes). The missing pieces are venue branding, sponsor slots on the
displays, and a public standings page worth linking to. Build those only after someone says yes.

---

## 4. Sequencing and pricing

- **Branch 1**: Phases 1 and 2. No billing dependency.
- **Branch 2**: Phase 3.
- **Phases 4 to 6**: follow the market, not the code.

Indicative pricing, to argue with rather than accept:

| Tier | Monthly | Annual |
|---|---|---|
| Personal | $5 | $48 |
| League | $19 | $180 |
| Venue (hand-sold) | $49 to $99 | negotiated |

## 5. Open decisions

1. Is the Phase 2 gate table right, especially SMS at League-only rather than Personal?
2. Is the `tier_legacy` grandfathering approach acceptable for the existing 106 accounts?
3. Stripe Tax or Paddle as merchant of record?
4. Confirm the audience order: home hosts self-serve first, venues hand-sold second.
