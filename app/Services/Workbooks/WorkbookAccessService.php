<?php

namespace App\Services\Workbooks;

use App\Enums\WorkbookAudience;
use App\Enums\WorkbookVisibility;
use App\Enums\WorkbookVisibleType;
use App\Exceptions\WorkbookException;
use App\Models\Store;
use App\Models\User;
use App\Models\Workbook;
use App\Models\WorkbookFolder;
use App\Models\WorkbookRow;
use App\Models\WorkbookVisibilityRole;
use App\Services\StoreAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Who may do what to a folder, workbook or row. THE single source of truth for
 * the permission matrix - controllers ask, they never decide.
 *
 * MOST RESTRICTIVE WINS. A resource's effective audience is the INTERSECTION of
 * its own tag with every ancestor's: for a row, the row, its workbook, the
 * workbook's folder, that folder's parent and so on to the root must EACH let
 * the caller in. The consequences surprise people, so they are worth stating:
 *
 *   - Two store_role_* levels naming DIFFERENT roles intersect. Disjoint lists
 *     reach nobody but an owner - the honest answer, and the safe one.
 *
 *   - A read-only ancestor makes everything beneath it read-only, EVEN FOR THE
 *     CHILD'S OWN AUTHOR, unless that author also owns the ancestor.
 *
 * The owner carve-out: created_by always satisfies their OWN node, for view and
 * edit. It does not carry up the chain - owning a workbook inside somebody
 * else's private folder still leaves it out of reach.
 *
 * The rules are spelled twice: in memory for one resource, and in SQL for the
 * lists (scopeToVisible). The two must agree, and
 * WorkbookVisibilityMatrixTest asserts that for every tag - the failure mode, a
 * resource that appears in a list and then 404s when opened, is invisible
 * until a user hits it.
 */
class WorkbookAccessService
{
    /**
     * A folder cycle that gets past the write-time guard must not hang a
     * worker. This bounds every walk even if the visited set were defeated.
     */
    private const MAX_DEPTH = 32;

    /** @var array<int, int|null>|null folder id => parent id, the whole tree, memoised per request */
    private ?array $parents = null;

    /** @var array<int, WorkbookFolder> */
    private array $folders = [];

    /** @var array<string, array<int, string>> "type:id" => role names */
    private array $roleNames = [];

    /** @var array<int, string> stores.id => store_number */
    private array $storeCodes = [];

    /** @var array<int, array{user_id: int, store_ids: array<int, int>|null, roles_by_store: array<string, array<int, string>>}> */
    private array $viewers = [];

    public function __construct(private readonly StoreAccessResolver $storeAccess) {}

    /**
     * Drop everything memoised. Called after a write, because a stale chain is a
     * stale permission decision.
     */
    public function refresh(): void
    {
        $this->parents = null;
        $this->folders = [];
        $this->roleNames = [];
        $this->storeCodes = [];
        $this->viewers = [];
    }

    // -------------------------------------------------------------------------
    // Finding what the caller may see - 404 otherwise
    //
    // By hand rather than route-model binding: binding would resolve the row
    // before the visibility check could turn it into a 404 indistinguishable
    // from "no such id".
    // -------------------------------------------------------------------------

    public function findFolder(User $user, int $folderId): WorkbookFolder
    {
        $folder = WorkbookFolder::query()->with(['store', 'creator'])->findOrFail($folderId);

        $this->assertCanView($user, $folder);

        return $folder;
    }

    public function findWorkbook(User $user, int $workbookId): Workbook
    {
        $workbook = Workbook::query()->with(['store', 'creator', 'columns', 'folder'])->findOrFail($workbookId);

        $this->assertCanView($user, $workbook);

        return $workbook;
    }

    /**
     * Scoped to the workbook in the URL: a row reached through the wrong
     * workbook is, from the caller's side, simply not there.
     */
    public function findRow(User $user, Workbook $workbook, int $rowId): WorkbookRow
    {
        $row = WorkbookRow::query()
            ->with(['store', 'creator', 'cells'])
            ->where('workbook_id', $workbook->id)
            ->findOrFail($rowId);

        $row->setRelation('workbook', $workbook);

        $this->assertCanView($user, $row);

        return $row;
    }

    // -------------------------------------------------------------------------
    // The permission matrix
    // -------------------------------------------------------------------------

    public function canView(User $user, Model $node): bool
    {
        return $this->cappingNode($user, $node, 'view') === null;
    }

    public function canEdit(User $user, Model $node): bool
    {
        return $this->cappingNode($user, $node, 'edit') === null;
    }

    /**
     * View is the ONLY ability that 404s. A 403 would confirm the resource
     * exists, which is enough to probe for it.
     *
     * @throws ModelNotFoundException
     */
    public function assertCanView(User $user, Model $node): void
    {
        if (! $this->canView($user, $node)) {
            throw (new ModelNotFoundException)->setModel($node::class, [$node->getKey()]);
        }
    }

    /**
     * Every other ability 403s, because the caller can already see the thing.
     *
     * Everything that is not plain viewing needs edit rights: deleting,
     * retagging, adding rows and redefining columns are all the same authority -
     * you either own the shape of this thing or you do not.
     *
     * @throws WorkbookException
     */
    public function assertCan(string $ability, User $user, Model $node): void
    {
        $capping = $this->cappingNode($user, $node, 'edit');

        if ($capping !== null) {
            throw WorkbookException::forbidden($ability, $this->isSameNode($capping, $node) ? [] : $this->reference($capping));
        }
    }

    /**
     * What the dashboard should render - the same answers the server would give,
     * so the buttons on screen are exactly the ones that will work.
     *
     * @return array<string, mixed>
     */
    public function capabilities(User $user, Model $node): array
    {
        $canEdit = $this->canEdit($user, $node);

        return [
            'can' => [
                'view' => $this->canView($user, $node),
                'edit' => $canEdit,
                // Only a workbook has columns or takes rows; advertising those
                // on a folder or a row would render buttons for routes that do
                // not exist.
                'manage_columns' => $canEdit && $node instanceof Workbook,
                'add_rows' => $canEdit && $node instanceof Workbook,
                'change_visibility' => $canEdit,
                'delete' => $canEdit,
            ],
        ];
    }

    /**
     * The resolved truth about one node, including WHICH ancestor reduced it.
     * Without capped_by the UI can only say "you cannot edit this", and with
     * nested folders that is a support ticket every time.
     *
     * @return array<string, mixed>
     */
    public function effectiveVisibility(User $user, Model $node): array
    {
        $viewCap = $this->cappingNode($user, $node, 'view');
        $editCap = $viewCap ?? $this->cappingNode($user, $node, 'edit');

        $payload = ['can_view' => $viewCap === null, 'can_edit' => $editCap === null];

        // "Your own tag is why" is noise the client already has.
        if ($editCap !== null && ! $this->isSameNode($editCap, $node)) {
            $payload['capped_by'] = $this->reference($editCap);
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    public function roleNamesOf(Model $node): array
    {
        return $this->rolesFor($this->typeOf($node), (int) $node->getKey());
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    /**
     * Every folder the caller may view.
     *
     * Folders are the one resource resolved in memory rather than SQL: the
     * ancestor chain would need a recursive CTE, spelled differently in MySQL
     * and SQLite, over a table of tens to hundreds of rows. Loaded whole, it is
     * three queries for the entire answer.
     *
     * @return array<int, int>
     */
    public function viewableFolderIds(User $user): array
    {
        $ids = array_keys($this->parents());
        $this->loadFolders($ids);

        return array_values(array_filter($ids, fn (int $id) => isset($this->folders[$id]) && $this->canView($user, $this->folders[$id])));
    }

    /**
     * The same rules as grantsView(), spelled in SQL, applied BEFORE pagination
     * so a page of 25 is 25 things the caller may actually open.
     *
     * Covers a resource's OWN tag only; the caller settles the ancestor chain
     * (by listing inside a folder it has already checked, or by the folder-id
     * set from viewableFolderIds()).
     *
     * @param  Builder<covariant Model>  $query
     */
    public function scopeToVisible(Builder $query, User $user, WorkbookVisibleType $type): void
    {
        $table = $type->table();
        $viewer = $this->viewer($user);

        $query->where(function (Builder $outer) use ($viewer, $type, $table) {
            // 1. Your own, whatever the tag says.
            $outer->where($table.'.created_by', $viewer['user_id']);

            // 2. Published estate-wide.
            $outer->orWhereIn($table.'.visibility', WorkbookVisibility::valuesForAudience(WorkbookAudience::AllStores));

            // 3. Shared with a store the viewer can reach. NULL store ids means
            // every store, so the clause is DROPPED rather than turned into a
            // whereIn over the whole estate.
            $outer->orWhere(function (Builder $q) use ($viewer, $table) {
                $q->whereIn($table.'.visibility', WorkbookVisibility::valuesForAudience(WorkbookAudience::Store));

                if ($viewer['store_ids'] !== null) {
                    $q->whereIn($table.'.store_id', $viewer['store_ids']);
                }
            });

            // 4. Shared with named roles at the owning store. The join to stores
            // translates our integer store_id into the CODE user_store_roles
            // holds; matching those by id silently returns nothing, or the
            // wrong store. 'all' and NULL are both live spellings of an
            // unscoped grant.
            $outer->orWhere(function (Builder $q) use ($viewer, $type, $table) {
                $q->whereIn($table.'.visibility', WorkbookVisibility::valuesForAudience(WorkbookAudience::StoreRole))
                    ->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                        ->from('workbook_visibility_roles as vr')
                        ->join('stores as s', 's.id', '=', $table.'.store_id')
                        ->join('user_store_roles as usr', 'usr.role_name', '=', 'vr.role_name')
                        ->whereColumn('vr.visible_id', $table.'.id')
                        ->where('vr.visible_type', $type->value)
                        ->where('usr.user_id', $viewer['user_id'])
                        ->where('usr.active', true)
                        ->where(fn (QueryBuilder $w) => $w->whereColumn('usr.store_id', 's.store_number')
                            ->orWhere('usr.store_id', 'all')
                            ->orWhereNull('usr.store_id')));
            });
        });
    }

    // -------------------------------------------------------------------------
    // The folder tree
    // -------------------------------------------------------------------------

    /**
     * The folder itself plus every ancestor, NEAREST FIRST - so the resolver
     * reports the folder right above you rather than the root.
     *
     * @return array<int, int>
     */
    public function folderChain(int $folderId): array
    {
        $parents = $this->parents();

        if (! array_key_exists($folderId, $parents)) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $folderId;

        // The visited set is the seatbelt: a cycle introduced outside the API -
        // a seeder, a manual UPDATE, a restored backup - truncates the chain
        // here instead of looping forever.
        while ($current !== null && ! isset($visited[$current]) && count($chain) < self::MAX_DEPTH) {
            $visited[$current] = true;
            $chain[] = $current;
            $current = $parents[$current] ?? null;
        }

        return $chain;
    }

    /**
     * The folder itself plus every descendant.
     *
     * @return array<int, int>
     */
    public function folderDescendants(int $folderId): array
    {
        $parents = $this->parents();

        if (! array_key_exists($folderId, $parents)) {
            return [];
        }

        $children = [];

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $found = [];
        $queue = [$folderId];

        for ($depth = 0; $queue !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $next = [];

            foreach ($queue as $id) {
                if (! isset($found[$id])) {
                    $found[$id] = true;
                    array_push($next, ...($children[$id] ?? []));
                }
            }

            $queue = $next;
        }

        return array_keys($found);
    }

    /**
     * Would making $candidateParentId the parent of $folderId create a cycle?
     * The PREVENTION half of the guard; the visited sets contain what gets past
     * it. One prevents, one contains.
     */
    public function wouldCycle(int $folderId, ?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        return $candidateParentId === $folderId
            || in_array($candidateParentId, $this->folderDescendants($folderId), true);
    }

    // -------------------------------------------------------------------------
    // Resolution internals
    // -------------------------------------------------------------------------

    /**
     * The first node in the chain that denies the ability, or null. Nearest
     * first, so the answer is the most actionable one.
     *
     * @return array<string, mixed>|null
     */
    private function cappingNode(User $user, Model $node, string $ability): ?array
    {
        $chain = $this->chainFor($node);
        $viewer = $this->viewer($user);

        // A node whose chain could not be built has lost its folder. Deny rather
        // than guess: an orphan is a corruption, not a public thing.
        if ($chain === []) {
            return $this->nodeFor($node);
        }

        // View is checked over the whole chain first: a caller who cannot SEE a
        // node must be told that, not that they may not edit it.
        foreach ($chain as $link) {
            if (! $this->grantsView($viewer, $link)) {
                return $link;
            }
        }

        if ($ability === 'edit') {
            foreach ($chain as $link) {
                if (! $this->grantsEdit($viewer, $link)) {
                    return $link;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $viewer
     * @param  array<string, mixed>  $link
     */
    private function grantsView(array $viewer, array $link): bool
    {
        // The owner carve-out, applied before the tag is consulted.
        if ($link['created_by'] !== null && $link['created_by'] === $viewer['user_id']) {
            return true;
        }

        return match ($link['visibility']) {
            WorkbookVisibility::OwnerOnly => false,

            // No store list to enumerate: every authenticated user of this
            // service is already somebody pizzasys vouched for.
            WorkbookVisibility::AllStoresView, WorkbookVisibility::AllStoresEdit => true,

            WorkbookVisibility::StoreView, WorkbookVisibility::StoreEdit => $viewer['store_ids'] === null
                || in_array($link['store_id'], $viewer['store_ids'], true),

            // An unscoped grant ('all') counts at every store. An empty role
            // list reaches nobody - fail closed.
            WorkbookVisibility::StoreRoleView, WorkbookVisibility::StoreRoleEdit => $link['roles'] !== []
                && array_intersect(
                    [...($viewer['roles_by_store'][$this->storeCode($link['store_id'])] ?? []), ...($viewer['roles_by_store']['all'] ?? [])],
                    $link['roles'],
                ) !== [],
        };
    }

    /**
     * @param  array<string, mixed>  $viewer
     * @param  array<string, mixed>  $link
     */
    private function grantsEdit(array $viewer, array $link): bool
    {
        if ($link['created_by'] !== null && $link['created_by'] === $viewer['user_id']) {
            return true;
        }

        return $link['visibility']->grantsEdit() && $this->grantsView($viewer, $link);
    }

    /**
     * The viewer's grants, resolved once per request - so walking any chain
     * costs no further queries. Two queries, whatever the workload.
     *
     * @return array{user_id: int, store_ids: array<int, int>|null, roles_by_store: array<string, array<int, string>>}
     */
    private function viewer(User $user): array
    {
        return $this->viewers[(int) $user->id] ??= [
            'user_id' => (int) $user->id,
            'store_ids' => $this->storeAccess->accessibleStoreIdsFor((int) $user->id),
            'roles_by_store' => $this->storeAccess->roleNamesByStoreFor((int) $user->id),
        ];
    }

    /**
     * The node itself plus every ancestor, nearest first. Empty means the chain
     * is broken and nothing should be granted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chainFor(Model $node): array
    {
        $chain = [$this->nodeFor($node)];

        $folderIds = match (true) {
            // folderChain() includes the folder itself, already link one. A root
            // folder has no ancestors, and that is not a broken chain.
            $node instanceof WorkbookFolder => array_slice($this->folderChain((int) $node->getKey()), 1),
            $node instanceof Workbook => $this->folderChain((int) $node->workbook_folder_id) ?: null,
            $node instanceof WorkbookRow => null,
            default => throw new InvalidArgumentException($node::class.' is not a taggable workbook resource.'),
        };

        if ($node instanceof WorkbookRow) {
            $workbook = $node->workbook;

            if ($workbook === null) {
                return [];
            }

            $chain[] = $this->nodeFor($workbook);
            $folderIds = $this->folderChain((int) $workbook->workbook_folder_id) ?: null;
        }

        // Every workbook lives in a folder, so an empty ancestry for one means
        // the folder it hangs from is gone.
        if ($folderIds === null) {
            return [];
        }

        $this->loadFolders($folderIds);

        foreach ($folderIds as $id) {
            if (! isset($this->folders[$id])) {
                return [];
            }

            $chain[] = $this->nodeFor($this->folders[$id]);
        }

        return $chain;
    }

    /**
     * One link in a chain. Folders, workbooks and rows all carry the same four
     * facts - an owner, an owning store, a tag and a role list - which is what
     * keeps the algorithm to one loop instead of three near-copies.
     *
     * @return array<string, mixed>
     */
    private function nodeFor(Model $node): array
    {
        $type = $this->typeOf($node);

        return [
            'type' => $type,
            'id' => (int) $node->getKey(),
            'store_id' => (int) $node->getAttribute('store_id'),
            'created_by' => $node->getAttribute('created_by') === null ? null : (int) $node->getAttribute('created_by'),
            'visibility' => $node->getAttribute('visibility'),
            'roles' => $this->rolesFor($type, (int) $node->getKey()),
        ];
    }

    /**
     * What the caller is told capped their access. Never includes the role list:
     * a user who cannot see a node has no business learning which roles would
     * have let them.
     *
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function reference(array $link): array
    {
        return [
            'type' => $link['type']->value,
            'id' => $link['id'],
            'visibility' => $link['visibility']->value,
            'visibility_label' => $link['visibility']->label(),
        ];
    }

    private function typeOf(Model $node): WorkbookVisibleType
    {
        return match (true) {
            $node instanceof WorkbookFolder => WorkbookVisibleType::Folder,
            $node instanceof Workbook => WorkbookVisibleType::Workbook,
            $node instanceof WorkbookRow => WorkbookVisibleType::Row,
            default => throw new InvalidArgumentException($node::class.' is not a taggable workbook resource.'),
        };
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function isSameNode(array $link, Model $node): bool
    {
        return $link['id'] === (int) $node->getKey() && $link['type'] === $this->typeOf($node);
    }

    /**
     * The whole folder tree, one query, memoised for the request.
     *
     * @return array<int, int|null>
     */
    private function parents(): array
    {
        if ($this->parents !== null) {
            return $this->parents;
        }

        $parents = WorkbookFolder::query()
            ->pluck('parent_id', 'id')
            ->map(fn ($parentId) => $parentId === null ? null : (int) $parentId)
            ->all();

        // A parent that is somehow absent is treated as a root rather than
        // silently jumping the gap. There is no `active` flag here, so this only
        // fires on corruption.
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && ! array_key_exists($parentId, $parents)) {
                $parents[$id] = null;
            }
        }

        return $this->parents = $parents;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function loadFolders(array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys($this->folders)));

        if ($missing === []) {
            return;
        }

        // One query for the whole chain, however deep.
        foreach (WorkbookFolder::query()->whereIn('id', $missing)->get() as $folder) {
            $this->folders[(int) $folder->id] = $folder;
        }

        $this->loadRoles(WorkbookVisibleType::Folder, $missing);
    }

    /**
     * @return array<int, string>
     */
    private function rolesFor(WorkbookVisibleType $type, int $id): array
    {
        if (! array_key_exists($type->value.':'.$id, $this->roleNames)) {
            $this->loadRoles($type, [$id]);
        }

        return $this->roleNames[$type->value.':'.$id];
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function loadRoles(WorkbookVisibleType $type, array $ids): void
    {
        // Seeded first, so a resource with no role rows is not re-queried on
        // every lookup.
        foreach ($ids as $id) {
            $this->roleNames[$type->value.':'.$id] ??= [];
        }

        WorkbookVisibilityRole::query()
            ->where('visible_type', $type->value)
            ->whereIn('visible_id', $ids)
            ->get(['visible_id', 'role_name'])
            ->each(function (WorkbookVisibilityRole $row) use ($type) {
                $this->roleNames[$type->value.':'.(int) $row->visible_id][] = (string) $row->role_name;
            });
    }

    /**
     * stores.id => store_number, because user_store_roles matches on the CODE.
     * withTrashed(): a soft-deleted store must not turn a private workbook
     * public by failing to resolve - the empty string matches no grant.
     */
    private function storeCode(int $storeId): string
    {
        return $this->storeCodes[$storeId] ??= (string) (Store::query()->withTrashed()->whereKey($storeId)->value('store_number') ?? '');
    }
}
