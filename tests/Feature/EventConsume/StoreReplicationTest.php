<?php

namespace Tests\Feature\EventConsume;

use App\Models\Store;
use App\Services\EventConsume\Handlers\StoreCreatedHandler;
use App\Services\EventConsume\Handlers\StoreDeletedHandler;
use App\Services\EventConsume\Handlers\StoreUpdatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stores are not created here - they arrive over auth.v1.store.*. Ticket routes
 * are keyed on store_number, so a store that has not replicated means every
 * ticket URL for it 404s.
 */
class StoreReplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * On `created`, data.store.store_id is the CODE. On `updated`, data.store_id
     * is the integer pk. Same key name, two meanings - the single most likely
     * thing to get wrong here.
     */
    public function test_a_created_event_reads_the_code_from_store_id(): void
    {
        app(StoreCreatedHandler::class)->handle([
            'type' => 'auth.v1.store.created',
            'data' => ['store' => [
                'id' => 42,
                'store_id' => '03795-00001',
                'name' => 'Downtown',
                'is_active' => true,
            ]],
        ]);

        $this->assertDatabaseHas('stores', [
            'id' => 42,
            'store_number' => '03795-00001',
            'name' => 'Downtown',
        ]);
    }

    public function test_a_created_event_without_a_store_number_is_rejected(): void
    {
        // Maintenance/Inventory fall back to the literal 'UNKNOWN' here, which
        // materialises a store nothing can ever route to. Throwing lets the
        // consumer NAK and retry instead.
        $this->expectExceptionMessage('missing store_id');

        app(StoreCreatedHandler::class)->handle([
            'data' => ['store' => ['id' => 42, 'name' => 'Downtown']],
        ]);
    }

    public function test_replaying_a_created_event_is_idempotent(): void
    {
        $event = ['data' => ['store' => ['id' => 42, 'store_id' => '03795-00001', 'name' => 'Downtown']]];

        app(StoreCreatedHandler::class)->handle($event);
        app(StoreCreatedHandler::class)->handle($event);

        $this->assertSame(1, Store::query()->count());
    }

    public function test_an_updated_event_reads_the_integer_pk_and_applies_deltas(): void
    {
        Store::query()->create(['id' => 42, 'store_number' => '03795-00001', 'name' => 'Downtown']);

        app(StoreUpdatedHandler::class)->handle([
            'data' => [
                'store_id' => 42, // the PK on this subject, not the code
                'changed_fields' => ['name' => ['from' => 'Downtown', 'to' => 'Downtown East']],
            ],
        ]);

        $this->assertDatabaseHas('stores', [
            'id' => 42,
            'store_number' => '03795-00001', // never changes
            'name' => 'Downtown East',
        ]);
    }

    /**
     * Redelivery order is not guaranteed, so an update can land before its
     * create. Throwing makes the consumer retry rather than materialising a
     * store with no store_number.
     */
    public function test_an_update_before_its_create_is_rejected_so_the_consumer_retries(): void
    {
        $this->expectExceptionMessage('not synced yet');

        app(StoreUpdatedHandler::class)->handle([
            'data' => ['store_id' => 42, 'changed_fields' => ['name' => ['from' => 'a', 'to' => 'b']]],
        ]);
    }

    public function test_a_deleted_event_soft_deletes_the_store(): void
    {
        Store::query()->create(['id' => 42, 'store_number' => '03795-00001', 'name' => 'Downtown']);

        app(StoreDeletedHandler::class)->handle(['data' => ['store_id' => 42]]);

        $this->assertSoftDeleted('stores', ['id' => 42]);
    }

    public function test_recreating_a_deleted_store_restores_it(): void
    {
        $event = ['data' => ['store' => ['id' => 42, 'store_id' => '03795-00001', 'name' => 'Downtown']]];

        app(StoreCreatedHandler::class)->handle($event);
        app(StoreDeletedHandler::class)->handle(['data' => ['store_id' => 42]]);
        app(StoreCreatedHandler::class)->handle($event);

        $this->assertDatabaseHas('stores', ['id' => 42, 'deleted_at' => null]);
    }
}
