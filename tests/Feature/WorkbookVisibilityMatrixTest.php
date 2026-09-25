<?php

namespace Tests\Feature;

use App\Enums\WorkbookVisibility;
use App\Models\User;
use App\Models\Workbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWorkbookWorld;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The seven tags against five kinds of viewer, for both abilities.
 *
 * This is the test that has to be right. Everything else in the feature is
 * plumbing around the answers pinned here, and the failure mode of getting one
 * cell wrong is another store reading data it should not.
 *
 * The fixtures deliberately put the workbook inside an all_stores_edit folder
 * so the chain never caps anything - this file is about ONE tag at a time.
 * WorkbookNestingCeilingTest covers what happens when the chain does cap.
 */
class WorkbookVisibilityMatrixTest extends TestCase
{
    use BuildsWorkbookWorld, FakesAuthServer, RefreshDatabase;

    private const OWNER_ID = 9;

    private const SAME_STORE_ID = 20;

    private const OTHER_STORE_ID = 30;

    private const ROLE_HOLDER_ID = 40;

    private const NON_ROLE_HOLDER_ID = 50;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkbookWorld();
    }

    /**
     * viewer => [tag => [can_view, can_edit]]
     *
     * Written out in full rather than derived, so a change to the rules has to
     * be stated here as an intention rather than quietly agreeing with itself.
     *
     * @return array<string, array<string, array{0: bool, 1: bool}>>
     */
    public static function matrix(): array
    {
        $tags = [
            'owner_only',
            'store_view',
            'store_edit',
            'store_role_view',
            'store_role_edit',
            'all_stores_view',
            'all_stores_edit',
        ];

        // The owner may always do both, whatever the tag says.
        $owner = array_fill_keys($tags, [true, true]);

        $sameStore = [
            'owner_only' => [false, false],
            'store_view' => [true, false],
            'store_edit' => [true, true],
            // Holds the store but not the named role.
            'store_role_view' => [false, false],
            'store_role_edit' => [false, false],
            'all_stores_view' => [true, false],
            'all_stores_edit' => [true, true],
        ];

        $otherStore = [
            'owner_only' => [false, false],
            'store_view' => [false, false],
            'store_edit' => [false, false],
            'store_role_view' => [false, false],
            'store_role_edit' => [false, false],
            // Estate-wide means estate-wide: any authenticated user of this
            // service, because pizzasys already vouched for them.
            'all_stores_view' => [true, false],
            'all_stores_edit' => [true, true],
        ];

        $roleHolder = [
            'owner_only' => [false, false],
            'store_view' => [true, false],
            'store_edit' => [true, true],
            'store_role_view' => [true, false],
            'store_role_edit' => [true, true],
            'all_stores_view' => [true, false],
            'all_stores_edit' => [true, true],
        ];

        // At the store, but under a role the tag does not name. Identical to
        // sameStore - and that is the point: holding SOME role is not holding
        // THE role.
        $nonRoleHolder = $sameStore;

        $cases = [];

        foreach ([
            'owner' => [self::OWNER_ID, $owner],
            'same store' => [self::SAME_STORE_ID, $sameStore],
            'other store' => [self::OTHER_STORE_ID, $otherStore],
            'role holder' => [self::ROLE_HOLDER_ID, $roleHolder],
            'non role holder' => [self::NON_ROLE_HOLDER_ID, $nonRoleHolder],
        ] as $who => [$userId, $expectations]) {
            foreach ($expectations as $tag => [$canView, $canEdit]) {
                $cases["{$who} / {$tag}"] = [$userId, $tag, $canView, $canEdit];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_the_visibility_matrix(int $viewerId, string $tag, bool $canView, bool $canEdit): void
    {
        $viewer = $this->seedWorld($viewerId, WorkbookVisibility::from($tag));
        $this->fakeAuthServer($viewer);

        $workbook = Workbook::query()->firstOrFail();

        $show = $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers());

        if (! $canView) {
            // 404, never 403. A 403 would confirm the workbook exists, which is
            // enough to probe for one.
            $show->assertNotFound();

            return;
        }

        $show->assertOk()
            ->assertJsonPath('data.viewer.can.view', true)
            ->assertJsonPath('data.viewer.can.edit', $canEdit)
            ->assertJsonPath('data.effective_visibility.can_edit', $canEdit);

        // The advertised capability and the enforced one must agree. Asserting
        // only the boolean would let the two drift silently, and the UI would
        // render a button that 403s.
        $update = $this->postJson(
            "/api/v1/workbooks/{$workbook->id}",
            ['name' => 'Renamed'],
            $this->headers(),
        );

        $canEdit
            ? $update->assertOk()
            : $update->assertForbidden()->assertJsonPath('error.code', 'WORKBOOK_FORBIDDEN');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyTag(): array
    {
        $cases = [];

        foreach (WorkbookVisibility::cases() as $tag) {
            $cases[$tag->value] = [$tag->value];
        }

        return $cases;
    }

    /**
     * The list clause and the per-resource resolver are two spellings of the
     * same rules, and they have to agree.
     *
     * The leak this guards is one-sided and invisible until somebody hits it: a
     * workbook the list admits but `show` 404s, or the other way round. It is
     * worth its own test precisely because neither endpoint looks wrong alone.
     */
    #[DataProvider('everyTag')]
    public function test_the_list_admits_exactly_what_show_opens(string $tag): void
    {
        $viewer = $this->seedWorld(self::SAME_STORE_ID, WorkbookVisibility::from($tag));
        $this->fakeAuthServer($viewer);

        $workbook = Workbook::query()->firstOrFail();

        $listed = $this->getJson(
            "/api/v1/workbook-folders/{$workbook->workbook_folder_id}/workbooks",
            $this->headers(),
        )->assertOk()->json('data.data');

        $opened = $this->getJson("/api/v1/workbooks/{$workbook->id}", $this->headers())->status();

        $this->assertSame(
            $opened === 200,
            count($listed) === 1,
            "The list and show disagree for {$tag}."
        );
    }

    /**
     * Five users, one workbook. The viewer is whichever id the case names.
     */
    private function seedWorld(int $viewerId, WorkbookVisibility $tag): User
    {
        $owner = $this->makeUser(self::OWNER_ID, 'Owner');

        $sameStore = $this->makeUser(self::SAME_STORE_ID, 'SameStore');
        $this->grantStore($sameStore, $this->store->store_number, 'team_member');

        $otherStore = $this->makeUser(self::OTHER_STORE_ID, 'OtherStore');
        $this->grantStore($otherStore, $this->otherStore->store_number, 'team_member');

        $roleHolder = $this->makeUser(self::ROLE_HOLDER_ID, 'RoleHolder');
        $this->grantStore($roleHolder, $this->store->store_number, 'shift_lead');

        $nonRoleHolder = $this->makeUser(self::NON_ROLE_HOLDER_ID, 'NonRoleHolder');
        $this->grantStore($nonRoleHolder, $this->store->store_number, 'team_member');

        $this->openTreeFor($owner, $tag, ['shift_lead']);

        return User::query()->findOrFail($viewerId);
    }
}
