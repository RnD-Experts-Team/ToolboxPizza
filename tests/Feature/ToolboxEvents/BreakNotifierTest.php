<?php

namespace Tests\Feature\ToolboxEvents;

use App\Jobs\PublishOutboxEventJob;
use App\Models\BreakEntry;
use App\Models\BreakMilestoneFiring;
use App\Models\ToolboxOutboxEvent;
use App\Services\Breaks\BreakMilestoneEvaluator;
use App\Services\Breaks\BreakSettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\BreakTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The seam to NotificationsPizza: a crossing becomes an outbox row, and nothing
 * else. There is no HTTP endpoint to create a notification anywhere in the
 * estate - publishing the envelope IS the creation.
 */
class BreakNotifierTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->seed(BreakTypeSeeder::class);

        CarbonImmutable::setTestNow('2026-09-15T21:00:00Z');

        $settings = app(BreakSettingsService::class);
        $settings->updateAllowance($this->authUser, 50);
        $settings->replaceThresholds($this->authUser, [20]);
    }

    private function breakOf(int $minutes): void
    {
        BreakEntry::factory()->for($this->authUser)->ofType('smoking')
            ->between('2026-09-15T14:00:00Z', CarbonImmutable::parse('2026-09-15T14:00:00Z')->addMinutes($minutes)->toIso8601String())
            ->create();
    }

    private function evaluate(): void
    {
        app(BreakMilestoneEvaluator::class)->evaluate($this->authUser, '2026-09-15');
    }

    /**
     * The shipped default. A crossing is still recorded - it just goes nowhere.
     */
    public function test_nothing_is_published_while_notifications_are_disabled(): void
    {
        config(['toolbox.notifications.enabled' => false]);

        $this->breakOf(25);
        $this->evaluate();

        $this->assertSame(1, BreakMilestoneFiring::query()->count());
        $this->assertNull(BreakMilestoneFiring::query()->value('outbox_event_id'));
        $this->assertDatabaseCount('toolbox_outbox_events', 0);
    }

    public function test_a_milestone_records_a_notification_envelope_when_enabled(): void
    {
        config(['toolbox.notifications.enabled' => true]);

        $this->breakOf(25);
        $this->evaluate();

        $row = ToolboxOutboxEvent::query()->where('subject', 'notifications.v1.notification.send')->firstOrFail();

        $this->assertNull($row->published_at);
        $this->assertSame('toolbox-system', $row->payload['source']);

        // The exact shape NotificationsPizza's NotificationSendHandler reads.
        $data = $row->payload['data'];
        $this->assertSame(['web'], $data['channels']);
        $this->assertSame($this->authUser->id, $data['users'][0]['id']);
        $this->assertSame('break_milestone_reached', $data['users'][0]['data']['type']);
        $this->assertSame('20 minutes of break used', $data['users'][0]['data']['title']);
        $this->assertSame(
            "You've used 25 of your 50 daily break minutes. 25 minutes left.",
            $data['users'][0]['data']['body'],
        );
        $this->assertSame('/toolbox/breaks?date=2026-09-15', $data['users'][0]['data']['action_url']);
    }

    public function test_the_firing_is_stamped_with_the_outbox_row_it_produced(): void
    {
        config(['toolbox.notifications.enabled' => true]);

        $this->breakOf(25);
        $this->evaluate();

        $firing = BreakMilestoneFiring::query()->firstOrFail();

        $this->assertNotNull($firing->outbox_event_id);
        $this->assertTrue($firing->wasEmitted());
        $this->assertDatabaseHas('toolbox_outbox_events', ['id' => $firing->outbox_event_id]);
    }

    public function test_a_domain_event_is_recorded_alongside_the_notification(): void
    {
        config(['toolbox.notifications.enabled' => true]);

        $this->breakOf(25);
        $this->evaluate();

        $this->assertDatabaseHas('toolbox_outbox_events', ['subject' => 'toolbox.v1.break.milestone_reached']);
        $this->assertDatabaseHas('toolbox_outbox_events', ['subject' => 'notifications.v1.notification.send']);
    }

    public function test_exceeding_the_allowance_publishes_its_own_event_carrying_the_overage(): void
    {
        config(['toolbox.notifications.enabled' => true]);

        $this->breakOf(54);
        $this->evaluate();

        $domain = ToolboxOutboxEvent::query()
            ->where('subject', 'toolbox.v1.break.allowance_exceeded')
            ->firstOrFail();

        $this->assertSame('allowance', $domain->payload['data']['kind']);
        $this->assertSame(4, $domain->payload['data']['over_minutes']);
        $this->assertSame(54, $domain->payload['data']['counted_minutes']);

        $notification = ToolboxOutboxEvent::query()
            ->where('subject', 'notifications.v1.notification.send')
            ->get()
            ->first(fn (ToolboxOutboxEvent $e) => $e->payload['data']['users'][0]['data']['type'] === 'break_allowance_exceeded');

        $this->assertNotNull($notification);
        $this->assertSame(
            "You've used 54 of your 50 daily break minutes - 4 over. Breaks keep running; nothing is blocked.",
            $notification->payload['data']['users'][0]['data']['body'],
        );
    }

    public function test_dev_mode_publishes_to_the_testing_subjects(): void
    {
        config(['toolbox.notifications.enabled' => true, 'nats.dev_mode' => true]);

        $this->breakOf(25);
        $this->evaluate();

        $this->assertDatabaseHas('toolbox_outbox_events', ['subject' => 'notifications.testing.v1.notification.send']);
        $this->assertDatabaseHas('toolbox_outbox_events', ['subject' => 'toolbox.testing.v1.break.milestone_reached']);
    }

    /**
     * REGRESSION. The announce path runs inside BreakWriteService's transaction
     * and every queue connection has after_commit => false, so without
     * afterCommit() a worker can claim the job before the outbox row commits,
     * find nothing and return silently. The sweeper still recovers it, so the
     * only symptom in production is every notification arriving up to five
     * minutes late - which is why this is asserted rather than left to chance.
     */
    public function test_the_publish_job_is_deferred_until_after_the_transaction_commits(): void
    {
        config(['toolbox.notifications.enabled' => true]);
        Queue::fake();

        $this->breakOf(25);
        $this->evaluate();

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
