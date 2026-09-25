<?php

namespace Tests\Feature;

use App\Enums\WorkbookVisibility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Most restrictive wins, over a chain.
 *
 * WorkbookVisibilityMatrixTest covers one tag at a time; this covers what
 * happens when a resource's own tag and its ancestors' disagree. Every case
 * here is one somebody will hit and be surprised by, which is exactly why they
 * are written down.
 */
class WorkbookNestingCeilingTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();
    }

    public function test_a_read_only_folder_makes_an_editable_workbook_read_only(): void
    {
        $author = $this->makeUser(9, 'Author');
        $viewer = $this->makeUser(20, 'Viewer');
        $this->grantStore($viewer, $this->store->store_number, 'team_member');

        $folder = $this->makeFolder($author, WorkbookVisibility::StoreView);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::StoreEdit);

        $this->fakeAuthServer($viewer);

        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.can.view', true)
            ->assertJsonPath('data.viewer.can.edit', false)
            // The UI has to be able to say WHY, or this is a support ticket
            // every single time.
            ->assertJsonPath('data.effective_visibility.capped_by.type', 'folder')
            ->assertJsonPath('data.effective_visibility.capped_by.id', $folder->id)
            ->assertJsonPath('data.effective_visibility.capped_by.visibility', 'store_view');

        $this->postJson("/api/v1/workbooks/{$workbook->id}", ['name' => 'Nope'], $this->headers())
            ->assertForbidden()
            ->assertJsonPath('error.capped_by.id', $folder->id);
    }

    public function test_a_private_folder_hides_a_workbook_from_its_own_author(): void
    {
        // Owning a workbook is not a way into somebody else's folder. You
        // cannot edit what you cannot reach.
        $folderOwner = $this->makeUser(9, 'FolderOwner');
        $workbookOwner = $this->makeUser(20, 'WorkbookOwner');
        $this->grantStore($workbookOwner, $this->store->store_number, 'team_member');

        $folder = $this->makeFolder($folderOwner, WorkbookVisibility::OwnerOnly);
        $workbook = $this->makeWorkbook($folder, $workbookOwner, WorkbookVisibility::AllStoresEdit);

        $this->fakeAuthServer($workbookOwner);

        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())->assertNotFound();
    }

    public function test_the_author_of_a_read_only_folder_can_still_edit_inside_it(): void
    {
        // The escape hatch from the case above: own the folder, and the owner
        // carve-out satisfies that link of the chain.
        $author = $this->makeUser(9, 'Author');

        $folder = $this->makeFolder($author, WorkbookVisibility::StoreView);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::StoreView);

        $this->fakeAuthServer($author);

        $this->postJson("/api/v1/workbooks/{$workbook->id}", ['name' => 'Fine'], $this->headers())
            ->assertOk();
    }

    public function test_a_grandparent_caps_a_three_deep_chain(): void
    {
        $author = $this->makeUser(9, 'Author');
        $viewer = $this->makeUser(20, 'Viewer');
        $this->grantStore($viewer, $this->store->store_number, 'team_member');

        $root = $this->makeFolder($author, WorkbookVisibility::StoreView);
        $mid = $this->makeFolder($author, WorkbookVisibility::StoreEdit, parentId: $root->id);
        $workbook = $this->makeWorkbook($mid, $author, WorkbookVisibility::StoreEdit);

        $this->fakeAuthServer($viewer);

        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.can.edit', false)
            // The NEAREST denier is reported, and here that is the root two
            // levels up - the only link that actually withholds anything.
            ->assertJsonPath('data.effective_visibility.capped_by.id', $root->id);
    }

    public function test_overlapping_role_lists_intersect_to_the_shared_role(): void
    {
        $author = $this->makeUser(9, 'Author');

        $shared = $this->makeUser(20, 'Shared');
        $this->grantStore($shared, $this->store->store_number, 'shift_lead');

        $onlyOne = $this->makeUser(30, 'OnlyOne');
        $this->grantStore($onlyOne, $this->store->store_number, 'assistant_manager');

        $folder = $this->makeFolder($author, WorkbookVisibility::StoreRoleEdit, ['shift_lead', 'gm']);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::StoreRoleEdit, ['shift_lead', 'assistant_manager']);

        // In both lists: through.
        $this->fakeAuthServer($shared);
        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.can.edit', true);

        // In the workbook's list but not the folder's: stopped at the folder.
        $this->actAs($onlyOne);
        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())->assertNotFound();
    }

    public function test_disjoint_role_lists_reach_nobody_but_the_owner(): void
    {
        $author = $this->makeUser(9, 'Author');

        $gm = $this->makeUser(20, 'Gm');
        $this->grantStore($gm, $this->store->store_number, 'gm');

        $lead = $this->makeUser(30, 'Lead');
        $this->grantStore($lead, $this->store->store_number, 'shift_lead');

        // No role satisfies both links, so the intersection is empty. That is
        // the honest answer, and the safe one.
        $folder = $this->makeFolder($author, WorkbookVisibility::StoreRoleEdit, ['gm']);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::StoreRoleEdit, ['shift_lead']);

        $this->fakeAuthServer($gm);

        foreach ([$gm, $lead] as $user) {
            $this->actAs($user);
            $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())->assertNotFound();
        }

        $this->actAs($author);
        $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())->assertOk();
    }

    public function test_a_row_is_capped_by_its_workbook(): void
    {
        $author = $this->makeUser(9, 'Author');
        $viewer = $this->makeUser(20, 'Viewer');
        $this->grantStore($viewer, $this->store->store_number, 'team_member');

        $folder = $this->makeFolder($author, WorkbookVisibility::AllStoresEdit);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::StoreView);
        $this->makeColumn($workbook);
        $row = $this->makeRow($workbook, $author, WorkbookVisibility::AllStoresEdit);

        $this->fakeAuthServer($viewer);

        // A permissive row inside a read-only workbook stays read-only. Without
        // this, a row tag would be a way to punch a hole in its own workbook.
        $this->getJson("/api/v1/workbooks/{$workbook->id}/rows/{$row->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.viewer.can.edit', false);

        $this->postJson(
            "/api/v1/workbooks/{$workbook->id}/rows/{$row->id}",
            ['cells' => []],
            $this->headers(),
        )->assertForbidden();
    }

    public function test_rows_of_other_stores_are_absent_from_a_shared_workbook(): void
    {
        // The pattern the row-level store exists for: one estate-wide workbook,
        // each store seeing only the lines it added.
        $author = $this->makeUser(9, 'Author');
        $viewer = $this->makeUser(20, 'Viewer');
        $this->grantStore($viewer, $this->store->store_number, 'team_member');

        $folder = $this->makeFolder($author, WorkbookVisibility::AllStoresEdit);
        $workbook = $this->makeWorkbook($folder, $author, WorkbookVisibility::AllStoresEdit);
        $this->makeColumn($workbook);

        $mine = $this->makeRow($workbook, $author, WorkbookVisibility::StoreEdit, store: $this->store);
        $theirs = $this->makeRow($workbook, $author, WorkbookVisibility::StoreEdit, store: $this->otherStore);

        $this->fakeAuthServer($viewer);

        $ids = collect($this->getJson("/api/v1/workbooks/{$workbook->id}/rows", $this->headers())
            ->assertOk()
            ->json('data.data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$mine->id], $ids);

        $this->getJson("/api/v1/workbooks/{$workbook->id}/rows/{$theirs->id}", $this->headers())
            ->assertNotFound();
    }

    /**
     * Http::fake() merges stubs and the first match wins, so a second
     * fakeAuthServer() in one test would silently keep the first user. Swapping
     * the property the stub closes over is the supported way to change viewer.
     */
    private function actAs(User $user): void
    {
        $this->authUser = $user;
    }
}
