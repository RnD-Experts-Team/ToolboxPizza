<?php

namespace Tests\Feature;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Models\Workbook;
use App\Models\WorkbookCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Typed cells: which slot a value lands in, what is refused, and the
 * value_text that every type writes alongside its own slot.
 */
class WorkbookCellTypeTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    private Workbook $workbook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();

        $author = $this->makeUser(9, 'Author');
        $folder = $this->makeFolder($author, WorkbookVisibility::AllStoresEdit);
        $this->workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::AllStoresEdit);

        $this->fakeAuthServer($author);
    }

    public function test_each_type_lands_in_its_own_slot_and_also_writes_value_text(): void
    {
        // assertEquals, not assertSame: SQLite stores a decimal column as a
        // REAL and hands back "12.5" where MySQL's decimal(20,6) would give
        // "12.500000". Pinning the string would pin the test to one driver.
        $cases = [
            [WorkbookColumnType::Text, 'Fryer oil', 'value_text', 'Fryer oil'],
            [WorkbookColumnType::LongText, 'A longer note', 'value_text', 'A longer note'],
            [WorkbookColumnType::Number, '12.50', 'value_number', 12.5],
            [WorkbookColumnType::Boolean, 'true', 'value_bool', 1],
        ];

        foreach ($cases as [$type, $input, $slot, $stored]) {
            $column = $this->makeColumn($this->workbook, $type->value, $type, 0);
            $rowId = $this->addRow([$column->id => $input]);

            $cell = WorkbookCell::query()
                ->where('row_id', $rowId)->where('column_id', $column->id)
                ->firstOrFail();

            $this->assertEquals($stored, $cell->getRawOriginal($slot), "{$type->value} slot");

            // The other three slots stay empty - a value belongs in exactly one.
            foreach (array_diff(['value_number', 'value_date', 'value_bool'], [$slot]) as $unused) {
                $this->assertNull($cell->getRawOriginal($unused), "{$type->value} bled into {$unused}");
            }

            // value_text is written for EVERY type, which is what makes the
            // cross-column search one clause instead of a four-way OR.
            $this->assertNotNull($cell->value_text, "{$type->value} left value_text empty");
        }
    }

    public function test_a_number_keeps_the_precision_it_was_given_for_display(): void
    {
        $column = $this->makeColumn($this->workbook, 'Price', WorkbookColumnType::Number);
        $rowId = $this->addRow([$column->id => '1.50']);

        $cell = WorkbookCell::query()->where('row_id', $rowId)->firstOrFail();

        // A price list that renders "1.5" where somebody typed "1.50" looks
        // broken, so the display string is the input as given.
        $this->assertSame('1.50', $cell->value_text);

        // And that is what the API hands back, rather than the decimal slot -
        // which renders differently per driver and would lose the precision.
        $this->getJson("/api/v1/workbooks/{$this->workbook->id}/rows/{$rowId}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.cells.'.$column->id, '1.50');
    }

    public function test_a_value_that_does_not_fit_its_column_is_refused_with_the_column_named(): void
    {
        $column = $this->makeColumn($this->workbook, 'Count', WorkbookColumnType::Number);

        $this->rowResponse([$column->id => 'not a number'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WORKBOOK_CELL_TYPE_MISMATCH')
            ->assertJsonPath('error.column_id', $column->id);
    }

    public function test_a_choice_outside_the_options_is_refused_and_the_options_handed_back(): void
    {
        $column = $this->makeColumn(
            $this->workbook, 'Shift', WorkbookColumnType::Select, options: ['am', 'pm'],
        );

        // Handing back what IS allowed turns a dead end into something the UI
        // can render as a dropdown.
        $this->rowResponse([$column->id => 'graveyard'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WORKBOOK_CELL_TYPE_MISMATCH')
            ->assertJsonPath('error.allowed', ['am', 'pm']);

        $this->rowResponse([$column->id => 'pm'])->assertCreated();
    }

    public function test_an_empty_value_writes_no_cell_at_all(): void
    {
        $column = $this->makeColumn($this->workbook, 'Item');

        $rowId = $this->addRow([$column->id => '']);

        // Absent and empty mean the same thing for a spreadsheet cell, and
        // storing the difference would be storing noise.
        $this->assertDatabaseMissing('workbook_cells', ['row_id' => $rowId]);
    }

    public function test_an_update_touches_only_the_columns_it_names(): void
    {
        $a = $this->makeColumn($this->workbook, 'A', position: 0);
        $b = $this->makeColumn($this->workbook, 'B', position: 1);

        $rowId = $this->addRow([$a->id => 'first', $b->id => 'second']);

        $this->postJson(
            "/api/v1/workbooks/{$this->workbook->id}/rows/{$rowId}",
            ['cells' => [(string) $a->id => 'changed']],
            $this->headers(),
        )->assertOk();

        // The reference implementation deleted every cell and re-inserted from
        // the submission, so a form that had dropped a field silently blanked it.
        $this->assertDatabaseHas('workbook_cells', ['row_id' => $rowId, 'column_id' => $a->id, 'value_text' => 'changed']);
        $this->assertDatabaseHas('workbook_cells', ['row_id' => $rowId, 'column_id' => $b->id, 'value_text' => 'second']);
    }

    public function test_clearing_a_cell_removes_its_row_rather_than_storing_an_empty_string(): void
    {
        $column = $this->makeColumn($this->workbook, 'Item');
        $rowId = $this->addRow([$column->id => 'something']);

        $this->postJson(
            "/api/v1/workbooks/{$this->workbook->id}/rows/{$rowId}",
            ['cells' => [(string) $column->id => null]],
            $this->headers(),
        )->assertOk();

        $this->assertDatabaseMissing('workbook_cells', ['row_id' => $rowId, 'column_id' => $column->id]);
    }

    public function test_a_column_from_another_workbook_cannot_be_written_through_a_forged_key(): void
    {
        $mine = $this->makeColumn($this->workbook, 'Mine');

        $other = $this->makeWorkbook(
            $this->workbook->folder,
            $this->authUser,
            WorkbookVisibility::AllStoresEdit,
        );
        $theirs = $this->makeColumn($other, 'Theirs');

        // The foreign key only checks that a column exists, not whose it is -
        // the reference validated nothing here at all.
        $this->rowResponse([$mine->id => 'ok', $theirs->id => 'forged'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('cells.'.$theirs->id);
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function addRow(array $cells): int
    {
        return (int) $this->rowResponse($cells)->assertCreated()->json('data.id');
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function rowResponse(array $cells): TestResponse
    {
        $keyed = [];

        foreach ($cells as $columnId => $value) {
            $keyed[(string) $columnId] = $value;
        }

        return $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/workbooks/{$this->workbook->id}/rows",
            ['cells' => $keyed],
            $this->headers(),
        );
    }
}
