<?php

use Illuminate\Support\Facades\Schedule;

// Break data is kept config('toolbox.breaks.retention_days') days. 07:00 UTC -
// one hour after the 06:00 cutoff - so a work day is never pruned while it is
// still running. Once a day is pruned it cannot be re-exported.
Schedule::command('breaks:prune')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// Safety net for the transactional outbox: PublishOutboxEventJob is the primary
// path, this sweeps up anything the queue lost or never published.
Schedule::command('outbox:publish-pending')->everyFiveMinutes()->withoutOverlapping();

/*
 | NOT scheduled, deliberately: breaks:evaluate-milestones.
 |
 | A stateless API has no in-process timer. A user on a break at minute 19
 | crosses their 20-minute milestone with no request in flight and nothing here
 | awake to notice it. So milestones are evaluated on the write paths (start,
 | stop, manual entry, edit, delete) and on every read of the CURRENT work day
 | (GET breaks/active, GET breaks/day) — which is why a firing stores BOTH
 | crossed_at, the true instant computed from the entries, and noticed_at, when
 | evaluation actually ran.
 |
 | With TOOLBOX_NOTIFICATIONS_ENABLED=false that latency is invisible: the
 | firing row is only ever read as part of a summary, which is the same request
 | that created it.
 |
 | TURN THIS ON AT THE SAME TIME AS NOTIFICATIONS. Once a milestone becomes a
 | push, "we noticed when you next asked" is not good enough, and a user who
 | closed the tab is never told at all:
 |
 |     Schedule::command('breaks:evaluate-milestones')
 |         ->everyMinute()
 |         ->withoutOverlapping()
 |         ->onOneServer()
 |         ->skip(fn () => ! config('toolbox.notifications.enabled'));
 */
