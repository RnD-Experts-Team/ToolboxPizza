<?php

namespace Tests\Feature;

use App\Enums\WorkbookVisibility;
use App\Models\Workbook;
use App\Models\WorkbookRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Scoping and the auth chain, asserted on EVERY verb rather than just GET -
 * the shape TicketStoreScopeTest pins for tickets, for the same reason: a
 * read that is correctly hidden and a write that is not is the worst of both.
 */
class WorkbookStoreScopeTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    private Workbook $workbook;

    private WorkbookRow $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();

        $owner = $this->makeUser(9, 'Owner');
        $folder = $this->makeFolder($owner, WorkbookVisibility::OwnerOnly);
        $this->workbook = $this->makeWorkbook($folder, $owner, WorkbookVisibility::OwnerOnly);
        $this->makeColumn($this->workbook);
        $this->row = $this->makeRow($this->workbook, $owner, WorkbookVisibility::OwnerOnly);

        // A stranger: at another store, holding no grant that reaches any of it.
        $stranger = $this->makeUser(20, 'Stranger');
        $this->grantStore($stranger, $this->otherStore->store_number, 'team_member');

        $this->fakeAuthServer($stranger);
    }

    public function test_an_invisible_workbook_is_a_404_on_every_verb(): void
    {
        $id = $this->workbook->id;

        $this->getJson("/api/v1/workbooks/{$id}", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/workbooks/{$id}", ['name' => 'x'], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/workbooks/{$id}", [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/workbooks/{$id}/columns", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/workbooks/{$id}/columns", ['columns' => [['name' => 'x']]], $this->headers())
            ->assertNotFound();
        $this->getJson("/api/v1/workbooks/{$id}/rows", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/workbooks/{$id}/visibility", ['visibility' => 'store_view'], $this->headers())
            ->assertNotFound();
        $this->postJson(
            "/api/v1/stores/{$this->otherStore->store_number}/workbooks/{$id}/rows",
            ['cells' => []],
            $this->headers(),
        )->assertNotFound();
    }

    public function test_an_invisible_row_is_a_404_on_every_verb(): void
    {
        $id = $this->workbook->id;
        $rowId = $this->row->id;

        $this->getJson("/api/v1/workbooks/{$id}/rows/{$rowId}", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/workbooks/{$id}/rows/{$rowId}", ['cells' => []], $this->headers())->assertNotFound();
        $this->deleteJson("/api/v1/workbooks/{$id}/rows/{$rowId}", [], $this->headers())->assertNotFound();
        $this->postJson(
            "/api/v1/workbooks/{$id}/rows/{$rowId}/visibility",
            ['visibility' => 'store_view'],
            $this->headers(),
        )->assertNotFound();
    }

    public function test_a_row_reached_through_the_wrong_workbook_is_a_404(): void
    {
        $viewer = $this->makeUser(30, 'Viewer');
        $this->authUser = $viewer;

        $folder = $this->makeFolder($viewer, WorkbookVisibility::AllStoresEdit);
        $mine = $this->makeWorkbook($folder, $viewer, WorkbookVisibility::AllStoresEdit);
        $other = $this->makeWorkbook($folder, $viewer, WorkbookVisibility::AllStoresEdit);
        $row = $this->makeRow($other, $viewer, WorkbookVisibility::AllStoresEdit);

        // Both are fully visible to this caller - the 404 is about the row not
        // being IN that workbook, which from the caller's side is the same
        // thing as not existing.
        $this->getJson("/api/v1/workbooks/{$mine->id}/rows/{$row->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/workbooks/{$other->id}/rows/{$row->id}", $this->headers())->assertOk();
    }

    public function test_an_unknown_store_on_a_create_is_a_404_naming_the_code(): void
    {
        $this->postJson(
            '/api/v1/stores/03795-99999/workbook-folders',
            ['name' => 'Nowhere'],
            $this->headers(),
        )->assertNotFound()
            ->assertJsonPath('error.code', 'STORE_NOT_FOUND')
            ->assertJsonPath('error.store_number', '03795-99999');
    }

    public function test_an_inactive_token_is_rejected_before_anything_else(): void
    {
        $this->tokenActive = false;

        $this->getJson('/api/v1/workbook-folders', $this->headers())->assertUnauthorized();
    }

    public function test_a_token_pizzasys_declines_is_forbidden(): void
    {
        // pizzasys stays authoritative for the CALLER's access. Until the
        // workbook auth rules are seeded there, ext.authorized is false and
        // every one of these routes answers 403 - which is the intended
        // behaviour, not a bug.
        $this->tokenAuthorized = false;

        $this->getJson('/api/v1/workbook-folders', $this->headers())->assertForbidden();
    }

    public function test_an_employee_token_is_refused(): void
    {
        // Employees live in a different id space; this service takes user
        // tokens only.
        $this->tokenSubjectType = 'employee';

        $this->getJson('/api/v1/workbook-folders', $this->headers())->assertForbidden();
    }

    public function test_the_list_endpoints_hide_what_show_would_hide(): void
    {
        $this->getJson('/api/v1/workbook-folders', $this->headers())
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }
}
