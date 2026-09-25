<?php

namespace Tests\Feature;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Models\Workbook;
use App\Models\WorkbookColumn;
use App\Models\WorkbookFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The grid: typed filtering, typed sorting, search, pagination, and the query
 * count that makes it worth having.
 */
class WorkbookRowGridTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    private Workbook $workbook;

    private WorkbookColumn $item;

    private WorkbookColumn $count;

    private WorkbookColumn $due;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();

        $author = $this->makeUser(9, 'Author');

        $folder = $this->makeFolder($author, WorkbookVisibility::AllStoresEdit);
        $this->workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::AllStoresEdit);

        $this->item = $this->makeColumn($this->workbook, 'Item', WorkbookColumnType::Text, 0);
        $this->count = $this->makeColumn($this->workbook, 'Count', WorkbookColumnType::Number, 1);
        $this->due = $this->makeColumn($this->workbook, 'Due', WorkbookColumnType::Date, 2);

        $this->fakeAuthServer($author);
    }

    public function test_a_number_column_sorts_numerically_not_lexicographically(): void
    {
        // The single most visible thing the reference implementation got wrong:
        // everything was text, so "10" sorted before "2".
        foreach ([2, 10, 3] as $n) {
            $this->addRow(['Item '.$n, (string) $n, null]);
        }

        $values = $this->rows(['sort_column' => $this->count->id, 'sort_order' => 'asc'])
            ->pluck('cells.'.$this->count->id)
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertSame([2, 3, 10], $values);
    }

    public function test_a_date_filter_matches_the_whole_day_not_just_midnight(): void
    {
        $this->addRow(['Morning', null, '2026-09-23T08:30:00+00:00']);
        $this->addRow(['Other day', null, '2026-09-24T08:30:00+00:00']);

        $names = $this->rows(['filter' => [$this->due->id => '2026-09-23']])
            ->pluck('cells.'.$this->item->id)
            ->all();

        // Comparing a dateTime column by equality to a bare date would find
        // only rows stored at exactly 00:00:00, i.e. usually nothing.
        $this->assertSame(['Morning'], $names);
    }

    public function test_a_text_filter_matches_a_substring_and_a_number_filter_does_not(): void
    {
        $this->addRow(['Fryer oil', '12', null]);
        $this->addRow(['Freezer temp', '1', null]);

        $this->assertSame(
            ['Fryer oil'],
            $this->rows(['filter' => [$this->item->id => 'ryer']])->pluck('cells.'.$this->item->id)->all(),
        );

        // A substring match on a number is never what anyone meant - "1" must
        // not drag in 12.
        $this->assertSame(
            ['Freezer temp'],
            $this->rows(['filter' => [$this->count->id => '1']])->pluck('cells.'.$this->item->id)->all(),
        );
    }

    public function test_an_unparseable_filter_is_dropped_rather_than_rejected(): void
    {
        $this->addRow(['Fryer oil', '12', null]);

        // The house convention: a half-typed filter value is a user still
        // typing, not a malformed request.
        $this->rows(['filter' => [$this->count->id => 'abc']])
            ->whenEmpty(fn () => $this->fail('The bad filter narrowed the result instead of being dropped.'));
    }

    public function test_search_crosses_every_column_including_typed_ones(): void
    {
        $this->addRow(['Fryer oil', '12', null]);
        $this->addRow(['Freezer temp', '99', null]);

        // value_text is written for every type precisely so this is one clause.
        $this->assertSame(
            ['Freezer temp'],
            $this->rows(['search' => '99'])->pluck('cells.'.$this->item->id)->all(),
        );
    }

    public function test_the_grid_does_not_scale_its_query_count_with_rows_times_columns(): void
    {
        // Asserted as a COMPARISON rather than against a magic number: what
        // matters is that widening the page does not add queries, not the exact
        // constant, which will drift as relations are eager-loaded.
        for ($i = 0; $i < 5; $i++) {
            $this->addRow(['Item '.$i, (string) $i, '2026-09-23T00:00:00+00:00']);
        }

        $small = $this->queriesToRenderGrid(5);

        for ($i = 5; $i < 40; $i++) {
            $this->addRow(['Item '.$i, (string) $i, '2026-09-23T00:00:00+00:00']);
        }

        $large = $this->queriesToRenderGrid(40);

        // 5 rows x 3 columns is 15 cells; 40 x 3 is 120. The reference
        // implementation read every cell through a relation method that
        // re-queried per call, so this ratio would be eight to one.
        $this->assertSame(
            $small,
            $large,
            "Rendering 40 rows took {$large} queries against {$small} for 5 - the grid is scaling with cells again.",
        );
    }

    private function queriesToRenderGrid(int $expectedRows): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson("/api/v1/workbooks/{$this->workbook->id}/rows?per_page=50", $this->headers())
            ->assertOk()
            ->assertJsonCount($expectedRows, 'data.data');

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    public function test_rows_paginate(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->addRow(['Item '.$i, null, null]);
        }

        $this->getJson("/api/v1/workbooks/{$this->workbook->id}/rows?per_page=10", $this->headers())
            ->assertOk()
            ->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.total', 30);
    }

    public function test_rows_reorder_as_a_whole_list_replace(): void
    {
        $a = $this->addRow(['A', null, null]);
        $b = $this->addRow(['B', null, null]);
        $c = $this->addRow(['C', null, null]);

        $this->postJson(
            "/api/v1/workbooks/{$this->workbook->id}/rows/reorder",
            ['row_ids' => [$c, $a, $b]],
            $this->headers(),
        )->assertOk();

        $this->assertSame(
            ['C', 'A', 'B'],
            $this->rows()->pluck('cells.'.$this->item->id)->all(),
        );
    }

    public function test_a_row_from_another_workbook_cannot_be_reordered_into_this_one(): void
    {
        $other = $this->makeWorkbook(
            WorkbookFolder::query()->firstOrFail(),
            $this->authUser,
            WorkbookVisibility::AllStoresEdit,
        );
        $stranger = $this->makeRow($other, $this->authUser);

        $this->postJson(
            "/api/v1/workbooks/{$this->workbook->id}/rows/reorder",
            ['row_ids' => [$stranger->id]],
            $this->headers(),
        )->assertStatus(422)->assertJsonValidationErrorFor('row_ids.0');
    }

    /**
     * @param  array<int, string|null>  $values  [item, count, due]
     */
    private function addRow(array $values): int
    {
        $response = $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/workbooks/{$this->workbook->id}/rows",
            ['cells' => [
                (string) $this->item->id => $values[0],
                (string) $this->count->id => $values[1],
                (string) $this->due->id => $values[2],
            ]],
            $this->headers(),
        );

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $query = []): Collection
    {
        $url = "/api/v1/workbooks/{$this->workbook->id}/rows";

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return collect($this->getJson($url, $this->headers())->assertOk()->json('data.data'));
    }
}
