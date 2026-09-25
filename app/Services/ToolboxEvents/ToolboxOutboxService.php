<?php

namespace App\Services\ToolboxEvents;

use App\Jobs\PublishOutboxEventJob;
use App\Models\ToolboxOutboxEvent;

class ToolboxOutboxService
{
    public function __construct(private readonly ToolboxEventFactory $events) {}

    /**
     * Wrap `$data` in an envelope, write it to the outbox and queue it for NATS.
     *
     * This is how everything leaves this service. A notification is never sent
     * directly: it is an event on the `notifications.*` subjects, written inside
     * the caller's transaction, which NotificationsPizza picks up and delivers.
     * So a notification can never fire for a write that rolled back.
     *
     * afterCommit() is load-bearing: callers run inside a DB transaction and
     * every queue connection has after_commit => false, as in the siblings.
     * Without it a worker can claim the job before the row commits, find
     * nothing, and return silently - outbox:publish-pending would still recover
     * it, but up to five minutes late.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(string $subject, array $data): ToolboxOutboxEvent
    {
        $row = $this->record($subject, $this->events->make($subject, $data));

        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    /**
     * Put a notification in each user's bell.
     *
     * `$notification` is exactly what the frontend receives - NotificationsPizza
     * copies it onto the notification row and pushes it as-is. One envelope
     * carries every recipient, and the envelope's `users` list is the record of
     * who was told at the time.
     *
     * @param  array<int, int>  $userIds
     * @param  array{type: string, title: string, body: string, action_url: string}  $notification
     */
    public function notify(array $userIds, array $notification): ToolboxOutboxEvent
    {
        return $this->publish('notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => array_map(fn (int $id) => ['id' => $id, 'data' => $notification], array_values($userIds)),
        ]);
    }

    /**
     * Push live state to each user's socket without touching their bell.
     *
     * The transient path: NotificationsPizza broadcasts `$event` on
     * private-users.{id} and writes no row, so a moving timer or an edited
     * ticket never fills the bell. Clients listen with a leading dot -
     * .listen('.break.updated', ...).
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function broadcast(array $userIds, string $event, array $data): ToolboxOutboxEvent
    {
        return $this->publish('notifications.v1.broadcast.send', [
            'event' => $event,
            'users' => array_map(fn (int $id) => ['id' => $id, 'data' => $data], array_values($userIds)),
        ]);
    }

    public function record(string $subject, array $payload): ToolboxOutboxEvent
    {
        $subject = $this->applyEnvironmentPrefix($subject);

        return ToolboxOutboxEvent::create([
            'subject' => $subject,
            'type' => $subject,
            'payload' => $payload,
        ]);
    }

    private function applyEnvironmentPrefix(string $subject): string
    {
        if (! config('nats.dev_mode')) {
            return $subject;
        }

        // Only transform toolbox + notifications domains
        if (str_starts_with($subject, 'toolbox.v1.')) {
            return str_replace('toolbox.v1.', 'toolbox.testing.v1.', $subject);
        }

        if (str_starts_with($subject, 'notifications.v1.')) {
            return str_replace('notifications.v1.', 'notifications.testing.v1.', $subject);
        }

        return $subject;
    }
}
