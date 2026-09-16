<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Work day
    |--------------------------------------------------------------------------
    | A work day runs from cutoff_hour to cutoff_hour, and a break belongs to
    | the day it STARTED in — a break that starts 23:00 and ends 07:00 the next
    | morning counts ENTIRELY against the day it started, not split across two.
    |
    | UTC deliberately: the boundary never shifts under DST, which a local-time
    | cutoff would. This WILL change one day, so nothing may hardcode 6 —
    | WorkDayResolver is constructed with these values and is the only place
    | the arithmetic lives.
    |
    | Changing it is NOT retroactive: break_entries.work_date is stored, so
    | existing rows keep the boundary they were written under and a summary
    | spanning the change is internally inconsistent. With 30-day retention it
    | self-heals in a month; change it on a quiet day.
    */
    'work_day' => [
        'cutoff_hour' => (int) env('TOOLBOX_WORK_DAY_CUTOFF_HOUR', 6),
        'timezone' => env('TOOLBOX_WORK_DAY_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Breaks
    |--------------------------------------------------------------------------
    | The allowance is per-user and the user sets their own; this is only the
    | value a brand-new user_break_settings row is created with.
    |
    | retention_days is the whole story for break data: breaks:prune deletes
    | entries, their notes and their milestone firings past this horizon. Once
    | a day is pruned it cannot be re-exported.
    */
    'breaks' => [
        'default_daily_allowance_minutes' => (int) env('TOOLBOX_DEFAULT_ALLOWANCE_MINUTES', 50),
        'retention_days' => (int) env('TOOLBOX_BREAK_RETENTION_DAYS', 30),
        'max_milestones' => (int) env('TOOLBOX_MAX_MILESTONES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | OFF by default, and shipped that way deliberately. A milestone crossing
    | is recorded in break_milestone_firings either way; this only decides
    | whether an outbox row is written for it.
    |
    | When enabled, BreakNotifier writes a notifications.v1.notification.send
    | envelope onto the outbox inside the same transaction as the firing row;
    | NotificationsPizza consumes it and broadcasts over ITS Reverb. Nothing
    | about Reverb, Echo or Pusher belongs in this service.
    |
    | TURNING THIS ON MUST BE PAIRED with scheduling breaks:evaluate-milestones
    | — see the commented block in routes/console.php for why.
    |
    | action_url is where the dashboard shows a day; built from config, never
    | hardcoded, because ToolboxPizza does not own the dashboard's routing.
    */
    'notifications' => [
        'enabled' => (bool) env('TOOLBOX_NOTIFICATIONS_ENABLED', false),
        'action_url' => env('TOOLBOX_BREAKS_ACTION_URL', '/toolbox/breaks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    | Live break state pushed to the user's socket over NotificationsPizza's
    | Reverb, on the same private-users.{id} channel the dashboard already
    | holds. No Reverb process here, and no second Echo connection there.
    |
    | Separate from notifications.enabled deliberately: live sync and milestone
    | toasts are different products, wanted independently, and they fail
    | independently.
    |
    | OFF by default because of DEPLOY ORDERING, not caution. The transport is
    | notifications.v1.broadcast.send, which NotificationsPizza only handles
    | once its BroadcastSendHandler is deployed. Turning this on first means
    | that service's consumer throws "No handler for subject" and parks the
    | messages. Deploy NotificationsPizza, then flip this.
    */
    'realtime' => [
        'enabled' => (bool) env('TOOLBOX_REALTIME_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    | Notifications default ON: a ticket nobody is told about is not a ticket,
    | unlike a break milestone toast. Realtime defaults OFF for the same
    | deploy-ordering reason as breaks - NotificationsPizza must be carrying
    | BroadcastSendHandler first or its consumer parks the messages.
    |
    | Own flags rather than the break ones, so tickets can ship without also
    | flipping break toasts.
    */
    'tickets' => [
        'action_url' => env('TOOLBOX_TICKETS_ACTION_URL', '/toolbox/tickets'),

        'notifications' => [
            'enabled' => (bool) env('TOOLBOX_TICKET_NOTIFICATIONS_ENABLED', true),
        ],

        'realtime' => [
            'enabled' => (bool) env('TOOLBOX_TICKET_REALTIME_ENABLED', false),
        ],

        'attachments' => [
            // Public, matching MaintenancePizza: files are served straight off
            // the storage:link symlink and the URL is embedded in the JSON.
            // That means the BYTES ARE UNAUTHENTICATED - anyone holding the
            // link reads the file. Recorded per row, so switching this later
            // does not strand existing files.
            //
            // `php artisan storage:link` is a required deploy step.
            'disk' => env('TOOLBOX_ATTACHMENT_DISK', 'public'),

            'max_kilobytes' => (int) env('TOOLBOX_ATTACHMENT_MAX_KB', 10240),
            'max_per_request' => (int) env('TOOLBOX_ATTACHMENT_MAX_COUNT', 10),

            // Checked against the SNIFFED type, not the filename. text/html and
            // image/svg+xml are absent on purpose: both execute script, and on
            // a publicly-served disk that is stored XSS on our own origin.
            'allowed_mimetypes' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic',
                'application/pdf', 'text/plain', 'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'video/mp4', 'video/quicktime',
            ],

            // How long a soft-deleted attachment's bytes survive, and how long a
            // freshly-staged file is protected from the orphan sweep.
            'retention_days' => (int) env('TOOLBOX_ATTACHMENT_RETENTION_DAYS', 30),
            'grace_hours' => (int) env('TOOLBOX_ATTACHMENT_GRACE_HOURS', 24),
        ],
    ],
];
