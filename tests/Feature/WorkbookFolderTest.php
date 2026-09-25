<?php

namespace Tests\Feature;

use App\Enums\WorkbookVisibility;
use App\Models\WorkbookFolder;
use App\Services\Workbooks\WorkbookAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The folder tree: nesting, the cycle guard, and what deleting one takes with
 * it.
 */
class WorkbookFolderTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();
        $this->fakeAuthServer($this->makeUser(9, 'Author'));
    }

    public function test_a_folder_cannot_become_its_own_parent(): void
    {
        $folder = $this->makeFolder($this->authUser);

        $this->postJson(
            "/api/v1/workbook-folders/{$folder->id}",
            ['parent_id' => $folder->id],
            $this->headers(),
        )->assertStatus(422)->assertJsonPath('error.code', 'WORKBOOK_FOLDER_CYCLE');
    }

    public function test_a_folder_cannot_be_moved_inside_its_own_descendant(): void
    {
        $root = $this->makeFolder($this->authUser);
        $child = $this->makeFolder($this->authUser, parentId: $root->id);
        $grandchild = $this->makeFolder($this->authUser, parentId: $child->id);

        // The PREVENTION half of the guard - the only thing between a user and
        // a subtree that eats itself.
        $this->postJson(
            "/api/v1/workbook-folders/{$root->id}",
            ['parent_id' => $grandchild->id],
            $this->headers(),
        )->assertStatus(422)->assertJsonPath('error.code', 'WORKBOOK_FOLDER_CYCLE');
    }

    public function test_a_cycle_written_behind_the_api_is_contained_rather_than_looping(): void
    {
        $a = $this->makeFolder($this->authUser);
        $b = $this->makeFolder($this->authUser, parentId: $a->id);

        // A seeder, a manual UPDATE or a restored backup can produce what the
        // API refuses. The visited set is the CONTAINMENT half: one prevents,
        // one contains.
        DB::table('workbook_folders')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $chain = app(WorkbookAccessService::class)->folderChain((int) $a->id);

        $this->assertSame([$a->id, $b->id], $chain);
    }

    public function test_a_folder_moves_between_parents(): void
    {
        $from = $this->makeFolder($this->authUser);
        $to = $this->makeFolder($this->authUser);
        $child = $this->makeFolder($this->authUser, parentId: $from->id);

        $this->postJson(
            "/api/v1/workbook-folders/{$child->id}",
            ['parent_id' => $to->id],
            $this->headers(),
        )->assertOk()->assertJsonPath('data.parent_id', $to->id);
    }

    public function test_a_populated_folder_refuses_to_delete_without_force(): void
    {
        $folder = $this->makeFolder($this->authUser);
        $child = $this->makeFolder($this->authUser, parentId: $folder->id);
        $this->makeWorkbook($child, $this->authUser);

        // Deleting cascades folders -> workbooks -> rows -> cells at the
        // database level, so the refusal says how much is at stake and the UI
        // can put a number in the prompt. The reference had a confirm() and
        // nothing else.
        $this->deleteJson("/api/v1/workbook-folders/{$folder->id}", [], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'WORKBOOK_FOLDER_NOT_EMPTY')
            ->assertJsonPath('error.child_folders', 1)
            ->assertJsonPath('error.workbooks', 1)
            ->assertJsonPath('error.force_required', true);

        $this->assertDatabaseHas('workbook_folders', ['id' => $folder->id]);
    }

    public function test_forcing_the_delete_takes_the_whole_subtree(): void
    {
        $folder = $this->makeFolder($this->authUser);
        $child = $this->makeFolder($this->authUser, parentId: $folder->id);
        $workbook = $this->makeWorkbook($child, $this->authUser, WorkbookVisibility::StoreRoleView, ['gm']);
        $row = $this->makeRow($workbook, $this->authUser, WorkbookVisibility::StoreRoleView, ['gm']);

        $this->deleteJson("/api/v1/workbook-folders/{$folder->id}?force=1", [], $this->headers())
            ->assertNoContent();

        $this->assertDatabaseMissing('workbook_folders', ['id' => $folder->id]);
        $this->assertDatabaseMissing('workbook_folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('workbooks', ['id' => $workbook->id]);
        $this->assertDatabaseMissing('workbook_rows', ['id' => $row->id]);

        // NOTHING CASCADES ON A POLYMORPHIC KEY. If these survived, the next
        // resource to be handed one of these ids would inherit its audience.
        $this->assertDatabaseCount('workbook_visibility_roles', 0);
    }

    public function test_an_empty_folder_deletes_without_force(): void
    {
        $folder = $this->makeFolder($this->authUser);

        $this->deleteJson("/api/v1/workbook-folders/{$folder->id}", [], $this->headers())
            ->assertNoContent();
    }

    public function test_a_folder_is_created_at_the_store_in_the_path(): void
    {
        $response = $this->postJson(
            "/api/v1/stores/{$this->otherStore->store_number}/workbook-folders",
            ['name' => 'Openings', 'visibility' => WorkbookVisibility::StoreEdit->value],
            $this->headers(),
        )->assertCreated();

        $response->assertJsonPath('data.store.store_number', $this->otherStore->store_number)
            ->assertJsonPath('data.created_by.id', $this->authUser->id);

        $this->assertDatabaseHas('workbook_folders', [
            'id' => $response->json('data.id'),
            'store_id' => $this->otherStore->id,
        ]);
    }

    public function test_adding_a_subfolder_needs_edit_rights_on_the_parent(): void
    {
        $owner = $this->makeUser(20, 'Owner');
        $parent = $this->makeFolder($owner, WorkbookVisibility::StoreView);
        $this->grantStore($this->authUser, $this->store->store_number, 'team_member');

        // Read-only means read-only. Otherwise a shared folder would be a place
        // anyone could publish into.
        $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/workbook-folders",
            ['name' => 'Sneaky', 'parent_id' => $parent->id],
            $this->headers(),
        )->assertForbidden()->assertJsonPath('error.code', 'WORKBOOK_FORBIDDEN');
    }

    public function test_a_role_tag_without_roles_is_refused(): void
    {
        // A store_role_* tag with no roles reaches nobody, so it is rejected at
        // the door rather than saved as a resource whose disappearance its
        // author cannot explain.
        $this->postJson(
            "/api/v1/stores/{$this->store->store_number}/workbook-folders",
            ['name' => 'Roles', 'visibility' => WorkbookVisibility::StoreRoleEdit->value],
            $this->headers(),
        )->assertStatus(422)->assertJsonValidationErrorFor('visibility_roles');
    }

    public function test_retagging_away_from_a_role_tag_clears_its_roles(): void
    {
        $folder = $this->makeFolder($this->authUser, WorkbookVisibility::StoreRoleEdit, ['gm']);

        $this->postJson(
            "/api/v1/workbook-folders/{$folder->id}/visibility",
            ['visibility' => WorkbookVisibility::StoreEdit->value],
            $this->headers(),
        )->assertOk()->assertJsonPath('data.visibility_roles', null);

        // Stale rows left behind would silently widen the tag the moment
        // somebody set it back to a role tag.
        $this->assertDatabaseCount('workbook_visibility_roles', 0);
    }

    public function test_the_folder_list_counts_only_workbooks_the_caller_can_open(): void
    {
        $owner = $this->makeUser(20, 'Owner');
        $this->grantStore($this->authUser, $this->store->store_number, 'team_member');

        $folder = $this->makeFolder($owner, WorkbookVisibility::StoreView);
        $this->makeWorkbook($folder, $owner, WorkbookVisibility::StoreView);
        $this->makeWorkbook($folder, $owner, WorkbookVisibility::OwnerOnly);

        // Telling someone a folder holds two workbooks when they can open one
        // is a leak, just a quiet one.
        $this->getJson('/api/v1/workbook-folders', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.data.0.workbooks_count', 1);
    }

    public function test_the_tag_catalogue_is_served_for_the_ui(): void
    {
        $this->getJson('/api/v1/workbook-options', $this->headers())
            ->assertOk()
            ->assertJsonCount(count(WorkbookVisibility::cases()), 'data.visibilities')
            ->assertJsonPath('data.visibilities.0.value', 'owner_only')
            // needs_roles is the one field that changes the editor's shape.
            ->assertJsonPath('data.visibilities.3.needs_roles', true);
    }

    public function test_roots_and_the_whole_tree_are_different_requests(): void
    {
        $root = $this->makeFolder($this->authUser);
        $this->makeFolder($this->authUser, parentId: $root->id);

        $this->getJson('/api/v1/workbook-folders', $this->headers())
            ->assertOk()->assertJsonCount(2, 'data.data');

        $this->getJson('/api/v1/workbook-folders?parent_id=', $this->headers())
            ->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $root->id);
    }

    public function test_the_folder_sort_is_not_overridden_by_a_stray_default(): void
    {
        // The reference appended ->latest() after the chosen orderBy, which
        // silently overrode every sort but the default.
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            WorkbookFolder::query()->create([
                'store_id' => $this->store->id,
                'created_by' => $this->authUser->id,
                'name' => $name,
                'visibility' => WorkbookVisibility::AllStoresView,
            ]);
        }

        $names = collect($this->getJson('/api/v1/workbook-folders?sort_by=name&sort_order=asc', $this->headers())
            ->assertOk()->json('data.data'))->pluck('name')->all();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names);
    }
}
