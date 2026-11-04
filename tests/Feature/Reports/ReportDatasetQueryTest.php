<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTicketWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The report dataset contract end to end, through the real auth middleware
 * with pizzasys faked. Datasets run on the test SQLite database.
 */
class ReportDatasetQueryTest extends TestCase
{
    use BuildsTicketWorld, FakesAuthServer, RefreshDatabase;

    private const URL = '/api/report-datasets/toolbox.tickets/query';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        $this->buildTicketWorld();

        // Store 1: two pending (one answered after 30 min, one never), one fixed
        // and reopened twice, one deleted. Store 2: one pending.
        $this->ticket(1, 'pending', '2026-10-21 10:00:00', '2026-10-21 10:30:00');
        $this->ticket(1, 'pending', '2026-10-21 12:00:00');
        $this->ticket(1, 'fixed', '2026-10-22 09:00:00', '2026-10-22 10:00:00', reopens: 2);
        $this->ticket(1, 'pending', '2026-10-21 08:00:00', deleted: true);
        $this->ticket(2, 'pending', '2026-10-21 11:00:00', '2026-10-21 11:10:00');
        // Outside the period below.
        $this->ticket(1, 'pending', '2026-10-30 11:00:00');
    }

    private function ticket(int $storeId, string $status, string $createdAt, ?string $respondedAt = null, int $reopens = 0, bool $deleted = false): void
    {
        DB::table('tickets')->insert([
            'store_id' => $storeId,
            'ticket_section_id' => $this->section->id,
            'title' => 'Oven down',
            'description' => 'It will not heat.',
            'reported_by' => $this->authUser->id,
            'status' => $status,
            'first_responded_at' => $respondedAt,
            'reopen_count' => $reopens,
            'last_activity_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => $deleted ? $createdAt : null,
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00001'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'columns' => ['store', 'date', 'status'],
            'group_by' => ['store', 'date', 'status'],
            'aggregates' => [
                ['column' => 'count', 'fn' => 'count', 'alias' => 'count'],
                ['column' => 'reopen_count', 'fn' => 'sum', 'alias' => 'reopen_count'],
                ['column' => 'response_minutes', 'fn' => 'avg', 'alias' => 'response_minutes'],
            ],
            'limit' => 50,
        ], $overrides);
    }

    public function test_the_catalog_names_this_service_and_lists_the_tickets_dataset(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'Toolbox')
            ->assertJsonPath('contract_version', 1)
            ->assertJsonPath('datasets.0.key', 'toolbox.tickets')
            ->assertJsonPath('datasets.0.path', '/report-datasets/toolbox.tickets/query')
            ->assertJsonPath('datasets.0.grain', ['store', 'date'])
            ->assertJsonPath('datasets.0.heavy', false)
            ->assertJsonPath('datasets.0.dated', true);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));
        $this->assertSame(['toolbox.tickets'], array_column($response->json('datasets'), 'key'));
        $this->assertSame(
            ['store', 'date', 'status', 'section', 'reopen_count', 'count', 'response_minutes'],
            array_column($response->json('datasets.0.columns'), 'key'),
        );
        $this->assertSame(
            ['pending', 'in_progress', 'fixed', 'closed'],
            array_column($response->json('datasets.0.columns.2.options'), 'value'),
        );
    }

    public function test_a_grouped_query_for_one_store_returns_its_rows_positionally(): void
    {
        $response = $this->postJson(self::URL, $this->body(), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [
                    ['key' => 'store', 'type' => 'string'],
                    ['key' => 'date', 'type' => 'date'],
                    ['key' => 'status', 'type' => 'enum'],
                    ['key' => 'count', 'type' => 'integer'],
                    ['key' => 'reopen_count', 'type' => 'integer'],
                    ['key' => 'response_minutes', 'type' => 'number'],
                ],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 2, 'contract_version' => 1],
            ]);

        // The deleted ticket and the one outside the period are not counted; the
        // unanswered ticket does not enter the average (SQLite's julianday is
        // not exact, hence the delta).
        $rows = $response->json('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(['03795-00001', '2026-10-21', 'pending', 2, 0], array_slice($rows[0], 0, 5));
        $this->assertEqualsWithDelta(30, $rows[0][5], 0.01);
        $this->assertSame(['03795-00001', '2026-10-22', 'fixed', 1, 2], array_slice($rows[1], 0, 5));
        $this->assertEqualsWithDelta(60, $rows[1][5], 0.01);

        // pizzasys judged this exact route, with the store codes of the body.
        Http::assertSent(fn (Request $request) => $request['path'] === self::URL
            && $request['method'] === 'POST'
            && $request['store_context']['body']['stores'] === ['03795-00001']
            && $request['store_context']['path']['dataset'] === 'toolbox.tickets');
    }

    public function test_a_row_filter_and_the_section_name_work(): void
    {
        $this->postJson(self::URL, $this->body([
            'columns' => ['store', 'section'],
            'group_by' => ['store', 'section'],
            'filters' => [['column' => 'status', 'op' => 'in', 'value' => ['pending', 'in_progress']]],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'count']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00001', 'Hiring page', 2]]);
    }

    public function test_empty_stores_means_no_store_filter(): void
    {
        $this->postJson(self::URL, $this->body([
            'stores' => [],
            'columns' => ['store'],
            'group_by' => ['store'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'count']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00001', 3], ['03795-00002', 1]]);
    }

    public function test_a_store_code_this_service_does_not_know_matches_nothing(): void
    {
        $this->postJson(self::URL, $this->body(['stores' => ['03795-00099']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', []);
    }

    public function test_unknown_or_unofferable_columns_are_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['columns' => ['store', 'description']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column description.']);

        // Measures cannot be grouped, and only the listed functions are offered.
        $this->postJson(self::URL, $this->body(['columns' => ['count'], 'group_by' => []]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('columns');

        $this->postJson(self::URL, $this->body(['aggregates' => [['column' => 'count', 'fn' => 'sum']]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('aggregates.0.fn');
    }

    public function test_the_period_is_not_capped(): void
    {
        $this->postJson(self::URL, $this->body(['date_from' => '2020-01-01', 'date_to' => '2026-10-27']), $this->headers())
            ->assertOk();
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::URL, $this->body(['stores' => ['03795-00002']]), $this->headers())->assertForbidden();
        $this->getJson('/api/report-datasets', $this->headers())->assertForbidden();
    }

    public function test_the_routes_need_a_token(): void
    {
        $this->postJson(self::URL, $this->body())->assertUnauthorized();
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $page = $this->postJson(self::URL, $this->body(), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => null]), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
