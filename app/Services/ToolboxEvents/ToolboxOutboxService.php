<?php

namespace App\Services\ToolboxEvents;

use App\Models\ToolboxOutboxEvent;

class ToolboxOutboxService
{
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
