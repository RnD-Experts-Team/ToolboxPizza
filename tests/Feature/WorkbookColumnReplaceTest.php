<?php

namespace Tests\Feature;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use App\Models\Workbook;
use App\Models\WorkbookCell;
use App\Models\WorkbookFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The whole-list column replace, and the holes the reference implementation
 * left in it.
 */
class WorkbookColumnReplaceTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    private Workbook $workbook;

    private WorkbookFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();

        $author = $this->makeUser(9, 'Author');
        $this->folder = $this->makeFolder($author, WorkbookVisibility::AllStoresEdit);
        $this->workbook = $this->makeWorkbook($this->folder, $author, WorkbookVisibility::AllStoresEdit);

        $this->fakeAuthServer($author);
    }

    public function test_resubmitting_in_a_new_order_reorders_the_columns(): void
    {
        $a = $this->makeColumn($this->workbook, 'A', position: 0);
        $b = $this->makeColumn($this->workbook, 'B', position: 1);

        // There is no move endpoint: position is rewritten from the submitted
        // array index, so a reorder IS a resubmission.
        $this->replace([
            ['id' => $b->id, 'name' => 'B'],
            ['id' => $a->id, 'name' => 'A'],
        ])->assertOk()
            ->assertJsonPath('data.columns.0.name', 'B')
            ->assertJsonPath('data.columns.1.name', 'A');
    }

    public function test_an_omitted_column_is_deleted_with_its_cells(): void
    {
        $keep = $this->makeColumn($this->workbook, 'Keep', position: 0);
        $drop = $this->makeColumn($this->workbook, 'Drop', position: 1);

        $row = $this->makeRow($this->workbook, $this->authUser);
        WorkbookCell::query()->create(['row_id' => $row->id, 'column_id' => $drop->id, 'value_text' => 'gone']);

        $this->replace([['id' => $keep->id, 'name' => 'Keep']])
            ->assertOk()
            ->assertJsonCount(1, 'data.columns');

        // Cells go by database cascade. If that ever stops being true, this is
        // the test that says so.
        $this->assertDatabaseMissing('workbook_cells', ['column_id' => $drop->id]);
        $this->assertDatabaseMissing('workbook_columns', ['id' => $drop->id]);
    }

    public function test_a_column_belonging_to_another_workbook_is_refused(): void
    {
        $mine = $this->makeColumn($this->workbook, 'Mine');

        $other = $this->makeWorkbook($this->folder, $this->authUser, WorkbookVisibility::AllStoresEdit);
        $theirs = $this->makeColumn($other, 'Theirs');

        // The reference validated `exists:columns,id` alone, so this submission
        // would have silently re-parented another workbook's column.
        $this->replace([
            ['id' => $mine->id, 'name' => 'Mine'],
            ['id' => $theirs->id, 'name' => 'Stolen'],
        ])->assertStatus(422)->assertJsonValidationErrorFor('columns.1.id');

        $this->assertDatabaseHas('workbook_columns', [
            'id' => $theirs->id,
            'workbook_id' => $other->id,
        ]);
    }

    public function test_a_workbook_cannot_be_left_with_no_columns(): void
    {
        $this->makeColumn($this->workbook, 'Only');

        $this->replace([])->assertStatus(422);
    }

    public function test_a_choice_column_needs_its_options(): void
    {
        $this->replace([
            ['name' => 'Shift', 'type' => WorkbookColumnType::Select->value],
        ])->assertStatus(422)->assertJsonValidationErrorFor('columns.0.options');

        $this->replace([
            ['name' => 'Shift', 'type' => WorkbookColumnType::Select->value, 'options' => ['am', 'pm']],
        ])->assertOk()->assertJsonPath('data.columns.0.options', ['am', 'pm']);
    }

    public function test_options_are_null_on_every_type_that_does_not_use_them(): void
    {
        // The client should never have to check the type to know whether to
        // read this field.
        $this->replace([
            ['name' => 'Note', 'type' => WorkbookColumnType::LongText->value, 'options' => ['ignored']],
        ])->assertOk()->assertJsonPath('data.columns.0.options', null);
    }

    public function test_replacing_columns_needs_edit_rights(): void
    {
        $this->makeColumn($this->workbook, 'Only');

        $stranger = $this->makeUser(20, 'Stranger');
        $this->grantStore($stranger, $this->store->store_number, 'team_member');
        $this->authUser = $stranger;

        // all_stores_VIEW: visible to everyone, editable by nobody but its author.
        $this->workbook->forceFill(['visibility' => WorkbookVisibility::AllStoresView])->save();

        $this->replace([['name' => 'Nope']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'WORKBOOK_FORBIDDEN');
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     */
    private function replace(array $columns): TestResponse
    {
        return $this->postJson(
            "/api/v1/workbooks/{$this->workbook->id}/columns",
            ['columns' => $columns],
            $this->headers(),
        );
    }
}
