<?php

namespace Tests\Feature;

use App\Enums\WorkbookColumnType;
use App\Enums\WorkbookVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The payload shape the dashboard is written against.
 *
 * docs/WORKBOOK_SYSTEM_FRONTEND.md documents these keys as a contract, and a
 * frontend in another repository cannot notice one of them quietly changing
 * name. This is the test that does.
 */
class WorkbookContractShapeTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();
        $this->fakeAuthServer($this->makeUser(9, 'Author'));
    }

    public function test_the_options_catalogue_carries_the_flags_the_editor_branches_on(): void
    {
        $this->getJson('/api/v1/workbook-options', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'visibilities' => [['value', 'label', 'audience', 'audience_label', 'grants_edit', 'needs_roles']],
                'column_types' => [['value', 'label', 'needs_options']],
            ]]);
    }

    public function test_a_workbook_carries_every_documented_key(): void
    {
        $folder = $this->makeFolder($this->authUser, WorkbookVisibility::AllStoresEdit);
        $workbook = $this->makeWorkbook($folder, $this->authUser, WorkbookVisibility::StoreRoleEdit, ['shift_lead']);
        $this->makeColumn($workbook, 'Shift', WorkbookColumnType::Select, options: ['am', 'pm']);

        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'id', 'folder_id', 'name', 'description',
                'store' => ['id', 'store_number', 'name'],
                'created_by' => ['id', 'name'],
                'visibility', 'visibility_label', 'visibility_roles',
                'effective_visibility' => ['can_view', 'can_edit'],
                'columns' => [['id', 'name', 'type', 'type_label', 'options', 'required', 'position']],
                'breadcrumb' => [['id', 'name']],
                'created_at', 'updated_at',
                'viewer' => ['can' => [
                    'view', 'edit', 'manage_columns', 'add_rows', 'change_visibility', 'delete',
                ]],
            ]])
            // The documented invariant: always an array for a role tag, always
            // null otherwise, so the client never checks the tag to know
            // whether to read the field.
            ->assertJsonPath('data.visibility_roles', ['shift_lead'])
            ->assertJsonPath('data.columns.0.options', ['am', 'pm']);
    }

    public function test_a_folder_without_a_role_tag_reports_null_roles(): void
    {
        $folder = $this->makeFolder($this->authUser, WorkbookVisibility::StoreEdit);

        $this->getJson("/api/v1/workbook-folders/{$folder->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.visibility_roles', null)
            ->assertJsonPath('data.parent_id', null);
    }

    public function test_a_row_reports_a_key_for_every_column_blank_ones_included(): void
    {
        $folder = $this->makeFolder($this->authUser, WorkbookVisibility::AllStoresEdit);
        $workbook = $this->makeWorkbook($folder, $this->authUser, WorkbookVisibility::AllStoresEdit);
        $filled = $this->makeColumn($workbook, 'Filled', WorkbookColumnType::Text, 0);
        $blank = $this->makeColumn($workbook, 'Blank', WorkbookColumnType::Text, 1);
        $flag = $this->makeColumn($workbook, 'Flag', WorkbookColumnType::Boolean, 2);

        $rowId = $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/workbooks/{$workbook->id}/rows",
            ['cells' => [(string) $filled->id => 'here', (string) $flag->id => 'yes']],
            $this->headers(),
        )->assertCreated()->json('data.id');

        $this->getJson("/api/v1/workbooks/{$workbook->id}/rows/{$rowId}", $this->headers())
            ->assertOk()
            // A sparse map would make the client distinguish "no cell" from
            // "empty cell", and they mean the same thing here.
            ->assertJsonPath('data.cells.'.$filled->id, 'here')
            ->assertJsonPath('data.cells.'.$blank->id, null)
            // Booleans come back as real booleans, not "1".
            ->assertJsonPath('data.cells.'.$flag->id, true)
            ->assertJsonStructure(['data' => [
                'id', 'workbook_id', 'position',
                'store' => ['id', 'store_number'],
                'created_by' => ['id', 'name'],
                'visibility', 'visibility_label', 'visibility_roles',
                'cells', 'created_at', 'updated_at',
                'effective_visibility' => ['can_view', 'can_edit'],
                'viewer' => ['can' => ['view', 'edit', 'change_visibility', 'delete']],
            ]]);
    }

    public function test_lists_are_paginators_nested_under_data(): void
    {
        $this->makeFolder($this->authUser, WorkbookVisibility::AllStoresView);

        // The shape the frontend destructures: data.data for the rows,
        // data.meta/links for the pager.
        $this->getJson('/api/v1/workbook-folders', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']])
            ->assertJsonPath('data.data.0.workbooks_count', 0);
    }
}
