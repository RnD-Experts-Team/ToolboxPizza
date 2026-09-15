<?php

namespace Tests\Feature\ToolboxEvents;

use App\Models\ToolboxOutboxEvent;
use App\Services\Nats\JetStreamPublisher;
use App\Services\ToolboxEvents\ToolboxEventFactory;
use App\Services\ToolboxEvents\ToolboxOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_factory_builds_a_cloudevents_envelope_sourced_to_this_service(): void
    {
        $envelope = app(ToolboxEventFactory::class)->make('toolbox.v1.break.milestone_reached', ['user_id' => 9]);

        $this->assertSame('1.0', $envelope['specversion']);
        $this->assertSame('toolbox-system', $envelope['source']);
        $this->assertSame('toolbox.v1.break.milestone_reached', $envelope['type']);
        // subject mirrors type across these services — the consumer filters on it.
        $this->assertSame($envelope['type'], $envelope['subject']);
        $this->assertSame('application/json', $envelope['datacontenttype']);
        $this->assertSame(['user_id' => 9], $envelope['data']);
        $this->assertNotEmpty($envelope['id']);
        $this->assertArrayHasKey('correlation_id', $envelope['meta']);
    }

    public function test_dev_mode_rewrites_both_the_toolbox_and_notification_domains(): void
    {
        config(['nats.dev_mode' => true]);

        $factory = app(ToolboxEventFactory::class);

        $this->assertSame(
            'toolbox.testing.v1.break.milestone_reached',
            $factory->make('toolbox.v1.break.milestone_reached', [])['type']
        );
        $this->assertSame(
            'notifications.testing.v1.notification.send',
            $factory->make('notifications.v1.notification.send', [])['type']
        );
        // Not ours to rewrite.
        $this->assertSame('auth.v1.user.created', $factory->make('auth.v1.user.created', [])['type']);
    }

    public function test_recording_stores_an_unpublished_row(): void
    {
        $row = app(ToolboxOutboxService::class)->record('toolbox.v1.break.allowance_exceeded', ['hello' => 'world']);

        $this->assertInstanceOf(ToolboxOutboxEvent::class, $row);
        $this->assertNull($row->published_at);
        $this->assertSame(['hello' => 'world'], $row->payload);
        // attempts defaults at the database, not on the model, so read it back.
        $this->assertSame(0, (int) $row->fresh()->attempts);
        $this->assertDatabaseHas('toolbox_outbox_events', [
            'subject' => 'toolbox.v1.break.allowance_exceeded',
            'type' => 'toolbox.v1.break.allowance_exceeded',
        ]);
    }

    /**
     * MaintenancePizza's publisher reads a config key its own config/nats.php
     * never defines, so every publish there throws — after the event has
     * already been committed. This asserts the OperationsPizza version we
     * copied instead actually resolves our two configured streams.
     */
    public function test_the_publisher_resolves_each_subject_to_its_configured_stream(): void
    {
        $resolve = new ReflectionMethod(JetStreamPublisher::class, 'resolvePublishTarget');

        $publisher = app(JetStreamPublisher::class);

        $this->assertSame(
            'TOOLBOX_EVENTS',
            $resolve->invoke($publisher, 'toolbox.v1.break.milestone_reached')['name']
        );
        $this->assertSame(
            'NOTIFICATIONS_EVENTS',
            $resolve->invoke($publisher, 'notifications.v1.notification.send')['name']
        );
    }

    public function test_the_publisher_refuses_a_subject_with_no_configured_stream(): void
    {
        $this->expectExceptionMessage("No publish target configured for subject 'auth.v1.user.created'");

        // We CONSUME auth events; publishing one would be a bug, not a feature.
        (new ReflectionMethod(JetStreamPublisher::class, 'resolvePublishTarget'))
            ->invoke(app(JetStreamPublisher::class), 'auth.v1.user.created');
    }
}
