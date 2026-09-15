<?php

namespace Tests\Feature\ToolboxEvents;

use App\Jobs\PublishOutboxEventJob;
use App\Models\BreakType;
use App\Models\ToolboxOutboxEvent;
use App\Services\Breaks\BreakSettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Live break state on the wire.
 *
 * The transport is notifications.v1.broadcast.send - NotificationsPizza's
 * transient path, which broadcasts to private-users.{id} WITHOUT writing an
 * InAppNotification row. These tests pin the envelope that service's
 * BroadcastSendHandler reads: `event` plus `users[].id` and `users[].data`.
 */
class BreakBroadcastTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private const SUBJECT = 'notifications.v1.broadcast.send';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T14:00:00Z');

        config(['toolbox.realtime.enabled' => true]);

        app(BreakSettingsService::class)->updateAllowance($this->authUser, 50);
    }

    private function typeId(string $slug): int
    {
        return (int) BreakType::query()->where('slug', $slug)->value('id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function broadcasts(): array
    {
        return ToolboxOutboxEvent::query()
            ->where('subject', self::SUBJECT)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ToolboxOutboxEvent $e) => $e->payload['data'])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastBroadcast(): ?array
    {
        $all = $this->broadcasts();

        return $all === [] ? null : end($all);
    }

    private function startBreak(string $slug = 'coffee_break'): int
    {
        return (int) $this->postJson('/api/v1/breaks/start', ['break_type_id' => $this->typeId($slug)], $this->headers())
            ->assertCreated()->json('data.id');
    }

    /**
     * The shipped default, and a deploy-ordering guard: publishing this subject
     * before NotificationsPizza has BroadcastSendHandler would park messages in
     * its inbox.
     */
    public function test_nothing_is_broadcast_while_realtime_is_disabled(): void
    {
        config(['toolbox.realtime.enabled' => false]);

        $this->startBreak();

        $this->assertDatabaseCount('toolbox_outbox_events', 0);
    }

    public function test_starting_a_break_broadcasts_the_entry_and_the_day_totals(): void
    {
        $id = $this->startBreak();

        $row = ToolboxOutboxEvent::query()->where('subject', self::SUBJECT)->firstOrFail();

        // The envelope NotificationsPizza's BroadcastSendHandler reads.
        $this->assertSame('break.started', $row->payload['data']['event']);
        $this->assertSame($this->authUser->id, $row->payload['data']['users'][0]['id']);
        $this->assertSame('toolbox-system', $row->payload['source']);

        $data = $row->payload['data']['users'][0]['data'];
        $this->assertSame($id, $data['break_id']);
        $this->assertSame('2026-09-15', $data['work_date']);
        $this->assertTrue($data['entry']['running']);
        $this->assertSame('Coffee break', $data['entry']['label']);
        // started_at is what the client ticks from - the server never pushes
        // the clock itself.
        $this->assertSame('2026-09-15T14:00:00+00:00', $data['entry']['started_at']);
        $this->assertSame(50, $data['totals']['allowance_minutes']);
        $this->assertTrue($data['totals']['has_active_break']);
    }

    public function test_stopping_a_break_broadcasts_the_finished_entry(): void
    {
        $id = $this->startBreak();

        CarbonImmutable::setTestNow('2026-09-15T14:25:00Z');
        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        $data = $this->lastBroadcast()['users'][0]['data'];

        $this->assertSame('break.stopped', $this->lastBroadcast()['event']);
        $this->assertFalse($data['entry']['running']);
        $this->assertSame(1500, $data['entry']['duration_seconds']);
        $this->assertSame(25, $data['totals']['counted_minutes']);
        $this->assertFalse($data['totals']['has_active_break']);
    }

    /**
     * A manual entry is already finished, so it is an upsert rather than the
     * start of a ticker.
     */
    public function test_a_manual_entry_broadcasts_as_an_update(): void
    {
        $this->postJson('/api/v1/breaks', [
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T12:00:00Z',
            'ended_at' => '2026-09-15T12:10:00Z',
        ], $this->headers())->assertCreated();

        $this->assertSame('break.updated', $this->lastBroadcast()['event']);
        $this->assertSame('manual', $this->lastBroadcast()['users'][0]['data']['entry']['source']);
    }

    public function test_editing_a_break_broadcasts_the_new_state(): void
    {
        $id = $this->startBreak();
        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        $this->postJson("/api/v1/breaks/{$id}", ['ended_at' => '2026-09-15T14:30:00Z'], $this->headers())->assertOk();

        $data = $this->lastBroadcast()['users'][0]['data'];

        $this->assertSame('break.updated', $this->lastBroadcast()['event']);
        $this->assertSame(1800, $data['entry']['duration_seconds']);
        $this->assertArrayNotHasKey('previous_work_date', $data);
    }

    /**
     * An edit across the cutoff moves the entry to another work day. One message
     * has to fix both days, or a client showing the old one is left with a
     * phantom entry and a stale total.
     */
    public function test_an_edit_across_the_cutoff_carries_both_work_days(): void
    {
        CarbonImmutable::setTestNow('2026-09-16T12:00:00Z');

        $id = (int) $this->postJson('/api/v1/breaks', [
            'break_type_id' => $this->typeId('coffee_break'),
            'started_at' => '2026-09-16T07:00:00Z',
            'ended_at' => '2026-09-16T07:20:00Z',
        ], $this->headers())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/breaks/{$id}", ['started_at' => '2026-09-16T05:00:00Z'], $this->headers())->assertOk();

        $data = $this->lastBroadcast()['users'][0]['data'];

        $this->assertSame('2026-09-15', $data['work_date']);
        $this->assertSame('2026-09-16', $data['previous_work_date']);
        // The day it left is now empty, and says so.
        $this->assertSame(0, $data['previous_totals']['entry_count']);
        $this->assertSame(0, $data['previous_totals']['counted_minutes']);
    }

    public function test_deleting_a_break_broadcasts_the_id_to_drop(): void
    {
        $id = $this->startBreak();
        $this->postJson("/api/v1/breaks/{$id}/stop", [], $this->headers())->assertOk();

        $this->deleteJson("/api/v1/breaks/{$id}", [], $this->headers())->assertNoContent();

        $data = $this->lastBroadcast()['users'][0]['data'];

        $this->assertSame('break.deleted', $this->lastBroadcast()['event']);
        $this->assertSame($id, $data['break_id']);
        $this->assertNull($data['entry']);
        $this->assertSame(0, $data['totals']['entry_count']);
    }

    public function test_a_milestone_crossing_is_broadcast(): void
    {
        app(BreakSettingsService::class)->replaceThresholds($this->authUser, [20]);

        $this->postJson('/api/v1/breaks', [
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T12:00:00Z',
            'ended_at' => '2026-09-15T12:25:00Z',
        ], $this->headers())->assertCreated();

        $milestone = collect($this->broadcasts())->firstWhere('event', 'break.milestone');

        $this->assertNotNull($milestone);
        $data = $milestone['users'][0]['data'];
        $this->assertSame('milestone', $data['kind']);
        $this->assertSame(20, $data['threshold_minutes']);
        // 20 minutes into a break that started at 12:00.
        $this->assertSame('2026-09-15T12:20:00+00:00', $data['crossed_at']);
        $this->assertSame(25, $data['totals']['counted_minutes']);
    }

    /**
     * The two flags are independent products: live sync without toasts, or
     * toasts without live sync.
     */
    public function test_realtime_and_notifications_are_independently_gated(): void
    {
        config(['toolbox.realtime.enabled' => true, 'toolbox.notifications.enabled' => false]);
        app(BreakSettingsService::class)->replaceThresholds($this->authUser, [20]);

        $this->postJson('/api/v1/breaks', [
            'break_type_id' => $this->typeId('smoking'),
            'started_at' => '2026-09-15T12:00:00Z',
            'ended_at' => '2026-09-15T12:25:00Z',
        ], $this->headers())->assertCreated();

        $this->assertNotNull(collect($this->broadcasts())->firstWhere('event', 'break.milestone'));
        $this->assertDatabaseMissing('toolbox_outbox_events', ['subject' => 'notifications.v1.notification.send']);
    }

    public function test_dev_mode_rewrites_the_broadcast_subject(): void
    {
        config(['nats.dev_mode' => true]);

        $this->startBreak();

        $this->assertDatabaseHas('toolbox_outbox_events', ['subject' => 'notifications.testing.v1.broadcast.send']);
    }

    /**
     * Same reason as BreakNotifier: this runs inside BreakWriteService's
     * transaction and every queue connection has after_commit => false.
     */
    public function test_the_publish_job_is_deferred_until_after_commit(): void
    {
        Queue::fake();

        $this->startBreak();

        Queue::assertPushed(
            PublishOutboxEventJob::class,
            fn (PublishOutboxEventJob $job) => $job->afterCommit === true,
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
