# ToolboxPizza

The home for features that belong to none of the other Pizza services. Two
slices so far: a **self-service break tracker** and an **internal ticketing
system**.

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

## What the tickets module does

Any part of the dashboard can raise a ticket and have it reach the right people.
The routing key is a **section** — a stable string the frontend tags each area
with (`hiring.page`, `inventory.main-dashboard`, `screens.menu`). Several boxes
on one screen can share a section; each page can have its own.

Sections are grouped by **levels**, and levels **nest**. A person assigned a
level receives everything beneath it, so an ops lead assigned `operations` picks
up every section under every level below it without being named on any of them.

### The rule everything else serves

**A ticket only reaches someone who has the store.** Being assigned to the
hiring section is not enough — if the ticket is for a store that person cannot
access, they are not notified, the ticket is not in their inbox, and its URL
answers **404** to them.

| | |
|---|---|
| **Sections** | Admin catalog with a stable `key`. Retired via `active`, never deleted — a section in use cannot be removed. |
| **Levels** | Nest, and a section may sit under **several** at once. Assignment to a level covers everything beneath it. |
| **Assignment** | To a section **or** a level, never both on one row. Stores are never named per assignment: each row carries a `store_scoped` boolean, and one user matching several rows is **unscoped if any of them is** (the broader grant wins). |
| **Deactivating a level** | **Severs the chain above it.** `Section → L3 → L2(inactive) → L1` resolves to `{L3}` only. Deliberate, and a footgun — see "Known limitations". |
| **Status** | `pending → in_progress → fixed/closed`, with one reopen edge back to `pending` out of either terminal state. A **reason is required** to reopen. Every transition writes a `ticket_status_changes` row, including the ticket's own creation. |
| **Visibility** | Reporter + resolved assignees, plus explicitly added **readers** (follow only), **responders** (reply, no status) and **exception assignees** (full powers on that one ticket though the section is not theirs). |
| **Attachments** | Public disk, `url` in the JSON, served off the `storage:link` symlink. **The bytes are unauthenticated** — see "Known limitations". |

### Who can do what

Highest power wins, so a reporter who also owns the queue keeps status powers.

| Action | Reporter | Assignee | Responder | Reader | Unrelated |
|---|---|---|---|---|---|
| View | ✓ | ✓ | ✓ | ✓ | **404** |
| Respond / attach / note | ✓ | ✓ | ✓ | 403 | 404 |
| Change status, reopen | 403 | ✓ | 403 | 403 | 404 |
| Add / remove participants | 403 | ✓ | 403 | 403 | 404 |
| Edit title/description | ✓ **while `pending`** | ✓ any status | 403 | 403 | 404 |
| Re-section | 403 | ✓ | 403 | 403 | 404 |

The reporter's edit window closes at `pending` because once somebody has picked
the ticket up, silently rewriting the description invalidates what they acted
on. The reporter cannot add participants either: routing belongs to the people
who own the queue.

**View is the only ability that 404s.** Everything else 403s with
`TICKET_FORBIDDEN`, because by then the caller can already see the thing.

**A ticket that resolves to zero recipients is not an error.** It is created and
the response carries `data.warnings: ["no_recipients"]` — refusing it would
throw away someone's report because of an admin's configuration gap.

---

## Setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan test
```

`--seed` loads the 15-row break catalog and the ticket **section** catalog, both
idempotent on their key columns — re-running only updates wording, and never
un-retires a row somebody deactivated.

**Levels and assignments are deliberately not seeded.** They are org structure,
not catalog, and inventing a reporting line is worse than an empty one. Until an
admin creates them, every new ticket is created successfully and comes back with
`data.warnings: ["no_recipients"]`.

**`php artisan storage:link` is required**, not optional: attachment URLs are
served off that symlink and 404 without it. (MaintenancePizza needs it too and
never says so.)

Users are **not** created here. They arrive over NATS from pizzasys
(`auth.v1.user.*`), so `php artisan nats:consume` must run supervised — a user
who has not replicated yet gets `401 Unauthorized: user not synced yet`.

**Stores and store roles are replicated the same way** (`auth.v1.store.*`,
`auth.v1.assignment.user_role_store.*`). Tickets need both: the store to resolve
a path, and `user_store_roles` to answer *"can this person see store X"* at
**recipient-resolution** time, when there is no request from that person to ask
pizzasys about. A store that has not replicated yet answers `404
STORE_NOT_FOUND`.

### Before any endpoint answers

1. `AUTH_SERVER_*` must point at pizzasys, and pizzasys must hold an auth-rule
   row for each path — otherwise `ext.authorized` is false and everything 403s.
   **Run `php artisan db:seed --class=ToolboxServiceSeeder` in the pizzasys
   repo** (not this one). It registers the `Toolbox` service client — printing
   `AUTH_SERVER_CALL_TOKEN` **once**, put it straight into this service's `.env`
   — creates `view tickets` / `manage tickets` / `administer tickets`, writes 30
   rules covering both modules, and bumps `authz:ver` so they go live at once.
   It does **not** attach the permissions to any role: `view`/`manage tickets`
   are checked **per store**, so they must come from the role a user holds *for
   that store*, while `administer tickets` is checked globally.
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

### Tickets

Store-scoped routes carry the **store code** in the path — `03795-00001`, the
same string the URL shows, not the numeric pk NATS events use.

| Method | Path | What |
|---|---|---|
| `GET` | `tickets` | **The cross-store inbox.** Filters: `statuses[]`, `section_keys[]`, `section_ids[]`, `stores[]` (codes), `reported_by`, `search`, `per_page` |
| `GET` | `stores/{storeId}/tickets` | One store's tickets, same filters minus `stores[]` |
| `POST` | `stores/{storeId}/tickets` | `{section_key, title, description, participants[]?, files[]?, notes[]?}` → 201 |
| `GET` | `stores/{storeId}/tickets/{id}` | One ticket with responses, notes, attachments and the status history |
| `POST` | `stores/{storeId}/tickets/{id}` | Correct it — `title`, `description`, `section_key` |
| `POST` | `…/{id}/status` | `{status, reason?}` |
| `POST` | `…/{id}/reopen` | `{reason}` — **required** |
| `POST` | `…/{id}/responses` | `{body, files[]?}` → 201 |
| `POST` | `…/{id}/notes` | `{body, files[]?}` → 201 |
| `POST` | `…/{id}/attachments` | `{files[]}` → 201 |
| `GET` | `…/{id}/participants` | Readers, responders and exception assignees |
| `POST` | `…/{id}/participants` | `{user_id, role}` — re-adding changes the role rather than stacking |
| `DELETE` | `…/{id}/participants/{userId}` | 204 |
| `GET` | `…/{id}/recipients` | **Who this reaches, and why** — each candidate with `via: "section" \| "level:{id}"` |

Admin catalog, all cross-store:

| Method | Path | What |
|---|---|---|
| `GET`/`POST` | `ticket-sections` · `ticket-sections/{id}` | `DELETE` deactivates, never deletes. `?include_inactive=1` to see retired rows |
| `GET`/`POST` | `ticket-levels` · `ticket-levels/{id}` | `GET` is **always the nested tree**. `DELETE` deactivates and returns `assignments_affected` — how many people stop receiving |
| `POST` | `ticket-levels/{id}/sections` | Whole-list replace; `[]` detaches all |
| `GET`/`POST` | `ticket-assignments` · `ticket-assignments/{id}` | Filters `user_ids[]`, `section_ids[]`, `level_ids[]`. `DELETE` is real — an assignment is configuration, not history |

`notes[]` and `files[]` are multipart and **paired by index**:
`notes[0][files][0]` lands on the first note, top-level `files[0]` on the ticket
itself. Uploads are capped per request and checked against a **sniffed** mime
allowlist (`TOOLBOX_ATTACHMENT_*`); `text/html` and `image/svg+xml` are absent
on purpose, because both execute script on a publicly-served origin.

There are **no bulk endpoints**, anywhere, by choice.

#### Ticket error codes

`TICKET_FORBIDDEN` (403, carries `ability` + `role`) ·
`TICKET_ILLEGAL_TRANSITION` (409, carries `allowed`) ·
`TICKET_ALREADY_IN_STATUS` (409) · `TICKET_NOT_REOPENABLE` (409) ·
`TICKET_REOPEN_REASON_REQUIRED` (422) · `TICKET_SECTION_INACTIVE` (422) ·
`TICKET_LEVEL_CYCLE` (422, carries `path`) ·
`TICKET_ASSIGNMENT_TARGET_REQUIRED` (422) · `TICKET_ASSIGNMENT_TARGET_AMBIGUOUS`
(422) · `TICKET_ASSIGNMENT_DUPLICATE` (409) · `TICKET_PARTICIPANT_REDUNDANT`
(422) · `STORE_NOT_FOUND` (404)

`allowed_transitions` is on every presented ticket, so the client renders the
buttons the server would actually honour instead of discovering a 409.

---

## Realtime / notifications

Four independent flags. **Tickets have their own**, so the module can ship
without also flipping break toasts.

| | Default | |
|---|---|---|
| `TOOLBOX_REALTIME_ENABLED` | off | Live break state pushed to the user's socket. |
| `TOOLBOX_NOTIFICATIONS_ENABLED` | off | Milestone / allowance entries in the notification bell. |
| `TOOLBOX_TICKET_REALTIME_ENABLED` | off | Live ticket state — same deploy-ordering reason as breaks. |
| `TOOLBOX_TICKET_NOTIFICATIONS_ENABLED` | **on** | Ticket notifications. A ticket nobody is told about is not a ticket, unlike a break toast. |

Breaks and tickets are separate because they're different products — live sync
without toasts is a reasonable thing to want, and they fail independently. A
milestone crossing is recorded in `break_milestone_firings` regardless; the
flags only decide what goes on the wire.

**No Reverb runs here.** This service publishes to NATS; NotificationsPizza owns
the only Reverb in the estate and does the broadcasting. Nothing about Reverb,
Echo or Pusher belongs in ToolboxPizza.

Subjects published: `toolbox.v1.break.milestone_reached`,
`toolbox.v1.break.allowance_exceeded`, `toolbox.v1.ticket.{created,responded,
status_changed,participant_added}`, `notifications.v1.notification.send`,
`notifications.v1.broadcast.send`.

The `toolbox.v1.ticket.*` domain events each record `recipient_user_ids` — who
was told **at the time**. Assignment resolves dynamically, so the live answer
moves with the org chart and the outbox is the only honest record of who was
actually notified.

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

### Live ticket state

Same channel, same transient transport. Events (leading dot again):

| Event | When | Payload |
|---|---|---|
| `.ticket.created` | Raised | `ticket_id`, `ticket` |
| `.ticket.updated` | Title, description or section changed | same |
| `.ticket.responded` | A reply landed | plus `response` |
| `.ticket.status_changed` | Any transition | plus `from`, `to`, `is_reopen` |
| `.ticket.participants_changed` | Somebody added or removed | `ticket_id`, `ticket` |

Two differences from the notifier, both deliberate:

- **The audience includes the actor.** Their other tabs and devices need to
  converge too. The notifier always *removes* them — nobody is told about their
  own action.
- **The embedded ticket is viewer-neutral: there is no `viewer` key.** One
  envelope reaches many people, so embedding one recipient's capabilities would
  show everyone the most-privileged person's buttons — a reader would see a
  Close button the server then refuses. A test guards its absence. Fetch
  `GET …/tickets/{id}` if you need `viewer.can`.

### ⚠ Deploy ordering

`notifications.v1.broadcast.send` is handled by `BroadcastSendHandler` in
**NotificationsPizza**, added alongside this work. Enabling
`TOOLBOX_REALTIME_ENABLED` or `TOOLBOX_TICKET_REALTIME_ENABLED` *before* that
service is deployed makes its consumer throw `No handler for subject` and park
the messages. **Deploy NotificationsPizza first, then flip the flags.**

**Ticket notifications default to ON**, so the pizzasys auth rules and the
`Toolbox` service seeder must be in place before the routes are exposed —
without them every ticket endpoint 403s (`allow_if_no_rule` defaults false).

**Turning notifications on must also be paired with scheduling
`breaks:evaluate-milestones`** — see the commented block in
`routes/console.php`. Until then, a milestone crossed while the tab is closed is
only noticed when the user next asks. (With realtime on, that matters less: the
crossing reaches any *connected* tab immediately.)

---

## Commands

| | |
|---|---|
| `nats:consume` | Replicate users, stores and store roles from `auth.v1.*`. Run supervised. |
| `outbox:publish-pending` | Sweeper for the transactional outbox (scheduled, every 5 min). |
| `breaks:prune [--days=] [--dry-run]` | Retention (scheduled daily 07:00 UTC, an hour after the cutoff so a day is never pruned while running). |
| `breaks:evaluate-milestones [--dry-run]` | Notice crossings without waiting for a request. **Written but not scheduled.** |
| `attachments:prune [--days=] [--grace-hours=] [--dry-run]` | Three passes: expired soft-deleted rows, rows whose owner was force-deleted, and files no row claims (scheduled daily 07:15 UTC). |

`attachments:prune`'s third pass **skips anything modified inside the grace
window** — that is a staged upload for a request still in flight, and deleting
it would break a live write. It is also what covers a process killed between
`stage()` and `commit()`, where the controller's `catch` never ran.

---

## Known limitations

### Tickets

1. **Attachment bytes are unauthenticated.** Public disk + `storage:link` means
   anyone holding the URL reads the file — no bearer token, no pizzasys check.
   It is the one part of this API not covered by `ext.authorized`, chosen
   knowingly for consistency with MaintenancePizza. The mime allowlist narrows
   the blast radius but does not close it. Moving to a private disk later is a
   file migration plus one streaming endpoint; the per-row `disk` column exists
   so files written before such a move still resolve.
2. **`user_store_roles` is only as fresh as `nats:consume`, and it fails
   quietly.** A newly-granted user simply is not on the recipient list — unlike
   an unsynced *user*, where the middleware 401s loudly. There is no
   reconciliation job and pizzasys emits no snapshot subject.
3. **Assignment is resolved dynamically, never snapshotted.** An org change
   moves who can act on an existing ticket, and `viewer.can` shifts under the
   user mid-session. The outbox records `recipient_user_ids` so notification
   history stays honest; exception assignees are the way to pin one person to
   one ticket.
4. **Deactivating a mid-tree level silently stops tickets reaching everyone
   above it.** A defensible reading of `active`, and a footgun for an admin who
   thinks they are only hiding a row from a picker.
5. **No ticket retention.** Breaks prune at 30 days; tickets and their
   attachments are kept forever. Probably right, but nobody has agreed a
   ceiling.
6. **No rate limit on any route, `POST …/attachments` included.** 10 MB × 10
   files with no throttle is a cheap way to fill a disk. Add `throttle:` before
   exposing it publicly.
7. **The pizzasys auth rules live in another repo** (`ToolboxServiceSeeder`).
   Without them every endpoint 403s; with a scoped rule that declares *no*
   permissions, every store is authorized. Both failure modes are invisible from
   here.
8. **`GET /tickets` and every break route are ungated at pizzasys** —
   `store_scope_mode: none` with no permission required, so any authenticated
   subject passes the gate. Breaks are self-service and keyed to the caller;
   the inbox is self-scoped locally against the replicated `user_store_roles`.
   That means **the inbox's visibility clause is the only thing protecting it**.
   It is tested, but it is one clause rather than two independent checks.

### Breaks

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
