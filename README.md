# ToolboxPizza

The home for features that belong to none of the other Pizza services. First
slice: a **self-service break tracker**. A later slice will add internal
tickets.

Headless JSON API, like every sibling service — `resources/` is the untouched
Laravel skeleton. The UI lives in `b-dashboard-pizza`.

---

## What the breaks module does

An employee times their own breaks, corrects or back-fills entries, annotates
them, sees where the day's break time went by category, and produces a clean day
breakdown to send to their manager — against a limit and milestone thresholds
**they set themselves**.

There is no manager, admin, or employee role in this service. The only actor is
a `User`, and the person taking the breaks sets the budget they are measured
against. **The system reports; it does not police.**

### The rules that shape everything

| | |
|---|---|
| **Work day** | Runs from `TOOLBOX_WORK_DAY_CUTOFF_HOUR` (default 06:00) UTC to the same hour next day. A break belongs to the day it **started** in — a 23:00→07:00 break counts **entirely** against the day it started, never split. |
| **Limit** | Soft. One break at a time; it keeps running past the allowance; the overage is recorded and shown. |
| **Excluded types** | "Special breaks" (General Manager, Maintenance, Specialists, Management, Hiring, Finance) are recorded but never counted against the allowance. |
| **Milestones** | Thresholds in **minutes used**. Each fires at most once per work day. |
| **Edits** | Overwrite in place. **There is deliberately no revision history** — see "Known limitations". |
| **Retention** | 30 days, then deleted outright by `breaks:prune`. Once pruned, a day cannot be re-exported. |

---

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan test
```

`--seed` loads the 15-row break catalog. It is idempotent on `slug`, so
re-running only updates wording.

Users are **not** created here. They arrive over NATS from pizzasys
(`auth.v1.user.*`), so `php artisan nats:consume` must run supervised — a user
who has not replicated yet gets `401 Unauthorized: user not synced yet`.

### Before any endpoint answers

1. `AUTH_SERVER_*` must point at pizzasys, and pizzasys must hold an auth-rule
   row for each path — otherwise `ext.authorized` is false and everything 403s.
2. `AUTH_SERVER_SERVICE_NAME` (default `Toolbox`) must match the `service`
   string the dashboard passes to `canAccessRoute({ service: ... })`.
3. Streams and durable consumers are created **manually** via the NATS CLI; the
   app never calls `create()`. The exact commands are in `config/nats.php`.

---

## API

Base: `/api/v1`. Everything sits behind `auth.token.store`, which verifies the
caller's bearer token against pizzasys and then `Auth::login()`s the mirrored
user. Success bodies are `{"data": ...}`; errors are
`{"message": ..., "error": {"code": ..., ...}}`.

**Updates are `POST`, not `PUT`/`PATCH`** — the house convention across these
services.

| Method | Path | What |
|---|---|---|
| `GET` | `health` | Liveness + the user the token resolved to |
| `GET` | `break-types` | The picker: active types in display order |
| `GET` | `break-settings` | Allowance + thresholds. **Creates the row on first read** |
| `POST` | `break-settings` | `{daily_allowance_minutes}` |
| `GET` | `break-milestones` | The user's thresholds, ascending |
| `POST` | `break-milestones` | `{thresholds: [20,40,50]}` — whole-list replace; `[]` turns them off |
| `DELETE` | `break-milestones/{id}` | Remove one threshold |
| `GET` | `breaks/active` | The running break + live `duration_seconds`, or `null` |
| `POST` | `breaks/start` | `{break_type_id, other_label?}` → 201 |
| `POST` | `breaks/{id}/stop` | End the running break |
| `GET` | `breaks` | Paginated history. Filters: `from`, `to` (**work** dates), `source`, `counts_toward_limit`, `break_type_ids[]`, `per_page` |
| `POST` | `breaks` | The forgot-to-time entry: `{break_type_id, started_at, ended_at, other_label?}` → 201 |
| `GET` | `breaks/{id}` | One break with its notes |
| `POST` | `breaks/{id}` | Correct it — any of `break_type_id`, `other_label`, `started_at`, `ended_at` |
| `DELETE` | `breaks/{id}` | 204 |
| `POST` | `breaks/{id}/notes` | `{body}` → 201. Allowed while running **and** long after it ended |
| `GET` | `breaks/day` | The day breakdown. `?date=YYYY-MM-DD`, defaults to the current work day |
| `GET` | `breaks/day/export` | Same payload **plus** `data.text`, the paste-ready summary |

Another user's break answers **404, never 403** — a 403 would confirm the id
exists.

### Error codes

`ALREADY_ON_BREAK` (409) · `BREAK_OVERLAP` (409, carries `error.conflicts`) ·
`BREAK_NOT_RUNNING` (409) · `BREAK_ENDS_BEFORE_START` (422) ·
`BREAK_STARTS_IN_FUTURE` (422) · `BREAK_OUTSIDE_RETENTION_WINDOW` (422) ·
`BREAK_CUSTOM_LABEL_REQUIRED` (422) · `BREAK_CUSTOM_LABEL_NOT_ALLOWED` (422) ·
`BREAK_TYPE_INACTIVE` (422)

### Two notes for whoever builds the UI

- **`GET breaks/active` and `GET breaks/day` (while the day is still open) WRITE
  rows.** They evaluate milestones, because a stateless API has no in-process
  timer and a client asking is what notices a crossing. Do not prefetch,
  HEAD-probe, or CDN-cache them. There is no rate limit yet; add `throttle:`
  before shipping a UI that polls per second — though with realtime on you
  should not need to poll at all: fetch once, then follow the socket.
- **Read `counts_toward_limit` on the entry, not the `group` on the type.** The
  entry carries a snapshot taken when it was written, so a summary already sent
  to a manager cannot be rewritten by a later reclassification.

---

## Realtime / notifications

Two independent flags, both **off** by default:

| | |
|---|---|
| `TOOLBOX_REALTIME_ENABLED` | Live break state pushed to the user's socket. |
| `TOOLBOX_NOTIFICATIONS_ENABLED` | Milestone / allowance entries in the notification bell. |

They're separate because they're different products — live sync without toasts
is a reasonable thing to want, and they fail independently. A milestone crossing
is recorded in `break_milestone_firings` regardless; the flags only decide what
goes on the wire.

**No Reverb runs here.** This service publishes to NATS; NotificationsPizza owns
the only Reverb in the estate and does the broadcasting. Nothing about Reverb,
Echo or Pusher belongs in ToolboxPizza.

Subjects published: `toolbox.v1.break.milestone_reached`,
`toolbox.v1.break.allowance_exceeded`, `notifications.v1.notification.send`,
`notifications.v1.broadcast.send`.

### Live break state

Break events ride the **same** `private-users.{id}` channel the dashboard
already subscribes to for notifications — one socket per browser session, no
second Echo connection, no extra CSP origin.

The transport is `notifications.v1.broadcast.send`, a **transient** path that
broadcasts *without* writing an `InAppNotification` row — otherwise a running
timer would fill the bell with rows nobody wants to read.

Events (Echo custom names, so listen with a **leading dot**):

| Event | When | Payload |
|---|---|---|
| `.break.started` | A timer started | `break_id`, `work_date`, `entry`, `totals` |
| `.break.stopped` | The running break ended | same |
| `.break.updated` | Created by hand, or edited | same, plus `previous_work_date` + `previous_totals` when an edit moved it across the cutoff |
| `.break.deleted` | Removed | `break_id`, `work_date`, `entry: null`, `totals` |
| `.break.milestone` | A threshold crossed | `kind`, `threshold_minutes`, `crossed_at`, `noticed_at`, `totals` |

```js
echo.private(`users.${userId}`)
    .listen('.break.started', (e) => { /* e.data.entry, e.data.totals */ })
    .listen('.break.updated', (e) => { /* upsert by e.data.break_id */ })
```

**The server never broadcasts the ticking clock.** Only state *changes* go out;
between them the client ticks locally from `entry.started_at`. That's why every
payload carries the entry and the day totals rather than a countdown — and it's
what lets the UI stop polling `GET breaks/active`.

Broadcasts go through the same transactional outbox as everything else, so one
can never fire for a write that rolled back. The cost: the queue worker and
`nats:consume` must both be running, or live updates stop until the five-minute
sweeper catches up.

### ⚠ Deploy ordering

`notifications.v1.broadcast.send` is handled by `BroadcastSendHandler` in
**NotificationsPizza**, added alongside this work. Enabling
`TOOLBOX_REALTIME_ENABLED` *before* that service is deployed makes its consumer
throw `No handler for subject` and park the messages. **Deploy
NotificationsPizza first, then flip the flag.**

**Turning notifications on must also be paired with scheduling
`breaks:evaluate-milestones`** — see the commented block in
`routes/console.php`. Until then, a milestone crossed while the tab is closed is
only noticed when the user next asks. (With realtime on, that matters less: the
crossing reaches any *connected* tab immediately.)

---

## Commands

| | |
|---|---|
| `nats:consume` | Replicate users from `auth.v1.user.*`. Run supervised. |
| `outbox:publish-pending` | Sweeper for the transactional outbox (scheduled, every 5 min). |
| `breaks:prune [--days=] [--dry-run]` | Retention (scheduled daily 07:00 UTC, an hour after the cutoff so a day is never pruned while running). |
| `breaks:evaluate-milestones [--dry-run]` | Notice crossings without waiting for a request. **Written but not scheduled.** |

---

## Known limitations

1. **No edit audit trail.** A user can retro-edit a day inside the 30-day window
   with no trace. The export is therefore **self-reported, not evidence** — it
   carries `self_reported: true` and says so in the text. If it ever needs to be
   evidence, an append-only revision table is an additive migration, but the
   history before it is unrecoverable.
2. **Changing the cutoff hour is not retroactive.** `work_date` is stored, so a
   summary spanning the change is internally inconsistent. With 30-day retention
   it self-heals in a month — change it on a quiet day.
3. **UTC-only work day.** The day flips at 00:00 local for a user at UTC−6 and
   08:00 local at UTC+2. Hard to retrofit once 30 days of rows exist.
4. **The direct-to-user notification shape is verified against
   NotificationsPizza's consumer, not observed in the wild.** Every other
   producer in the estate uses the `.role.send` variant; Toolbox would be the
   first direct-to-user one. Verify end to end before flipping the flag.
5. **Breaks here are not payroll.** OperationsPizza's `employee_clock_states`
   and TCP Manager+ already track breaks that *close a worked segment*. This is
   a self-service courtesy timer on the `users` id space, deliberately disjoint
   from the `employees` id space.
6. **A forgotten break accrues without limit.** `elapsedSeconds()` has no cap,
   so a break the user never stops keeps growing against its work day
   indefinitely — and it holds that day "open" for milestone evaluation.
   Soft-by-design covers overage *within* a day; this is a data-quality gap
   across days, and today it self-heals only when `breaks:prune` eventually
   deletes the still-running entry. A `stale_after_minutes` flag on the
   presenter (so a client can prompt "still on break?") is the cheap fix, and is
   deliberately not built in this slice.
