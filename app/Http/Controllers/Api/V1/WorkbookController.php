<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkbookVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkbookColumnsRequest;
use App\Http\Requests\Api\V1\WorkbookFolderStoreRequest;
use App\Http\Requests\Api\V1\WorkbookFolderUpdateRequest;
use App\Http\Requests\Api\V1\WorkbookStoreRequest;
use App\Http\Requests\Api\V1\WorkbookUpdateRequest;
use App\Http\Requests\Api\V1\WorkbookVisibilityRequest;
use App\Models\Store;
use App\Models\WorkbookColumn;
use App\Services\Workbooks\WorkbookAccessService;
use App\Services\Workbooks\WorkbookQueryService;
use App\Services\Workbooks\WorkbookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Folders and workbooks: reading, creating, editing, columns and retagging.
 *
 * Reads are NOT store-scoped: a folder tagged all_stores_view is visible from
 * every store at once, so a store-prefixed list would either repeat it or hide
 * it. Creates ARE - the store in the path is where the thing is created, and
 * becomes its own store.
 */
class WorkbookController extends Controller
{
    public function __construct(
        private readonly WorkbookService $workbooks,
        private readonly WorkbookQueryService $reader,
        private readonly WorkbookAccessService $access,
    ) {}

    /**
     * The labelled tag and column-type catalogues, so the dashboard hardcodes
     * neither.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->reader->options()]);
    }

    // -------------------------------------------------------------------------
    // Folders
    // -------------------------------------------------------------------------

    public function folders(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'sort_by', 'sort_order', 'per_page']);

        // Absent means the whole tree, flat; an explicit empty value means roots
        // only. The two are different questions and the client says which.
        if ($request->has('parent_id')) {
            $filters['parent_id'] = $request->filled('parent_id') ? $request->integer('parent_id') : null;
        }

        return response()->json(['data' => $this->reader->folders($request->user(), $filters)]);
    }

    /**
     * With the trail above it, so a client landing on a deep link can render a
     * breadcrumb without walking parent_id one request at a time.
     */
    public function showFolder(Request $request, int $folderId): JsonResponse
    {
        $folder = $this->access->findFolder($request->user(), $folderId);

        return response()->json(['data' => $this->reader->presentFolder(
            $folder,
            $request->user(),
            breadcrumb: array_slice($this->workbooks->breadcrumb((int) $folder->id), 0, -1),
        )]);
    }

    public function storeFolder(WorkbookFolderStoreRequest $request, string $storeId): JsonResponse
    {
        $folder = $this->workbooks->createFolder($request->user(), Store::resolveByNumber($storeId), $request->validated());

        return response()->json(['data' => $this->reader->presentFolder($folder, $request->user())], 201);
    }

    public function updateFolder(WorkbookFolderUpdateRequest $request, int $folderId): JsonResponse
    {
        $folder = $this->workbooks->updateFolder(
            $request->user(),
            $this->access->findFolder($request->user(), $folderId),
            $request->validated(),
        );

        return response()->json(['data' => $this->reader->presentFolder($folder, $request->user())]);
    }

    /**
     * Takes the whole subtree. Refuses a populated folder without ?force=true,
     * and says how much is inside so the UI can put a number in the prompt.
     */
    public function destroyFolder(Request $request, int $folderId): Response
    {
        $this->workbooks->deleteFolder(
            $request->user(),
            $this->access->findFolder($request->user(), $folderId),
            $request->boolean('force'),
        );

        return response()->noContent();
    }

    public function retagFolder(WorkbookVisibilityRequest $request, int $folderId): JsonResponse
    {
        $folder = $this->access->findFolder($request->user(), $folderId);

        $this->workbooks->retag(
            $request->user(),
            $folder,
            WorkbookVisibility::from($request->validated('visibility')),
            (array) $request->validated('visibility_roles', []),
        );

        return response()->json(['data' => $this->reader->presentFolder($folder->refresh(), $request->user())]);
    }

    // -------------------------------------------------------------------------
    // Workbooks
    // -------------------------------------------------------------------------

    /**
     * Viewing the folder settles the ancestor chain for everything inside it,
     * so the list only weighs each workbook's own tag.
     */
    public function index(Request $request, int $folderId): JsonResponse
    {
        $folder = $this->access->findFolder($request->user(), $folderId);

        return response()->json(['data' => $this->reader->workbooksIn(
            $request->user(),
            $folder,
            $request->only(['search', 'sort_by', 'sort_order', 'per_page']),
        )]);
    }

    public function show(Request $request, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        return response()->json(['data' => $this->reader->presentWorkbook(
            $workbook,
            $request->user(),
            withColumns: true,
            breadcrumb: $this->workbooks->breadcrumb((int) $workbook->workbook_folder_id),
        )]);
    }

    /**
     * The store need not be the folder's - that is how one shared folder holds a
     * workbook per store.
     */
    public function store(WorkbookStoreRequest $request, string $storeId, int $folderId): JsonResponse
    {
        $workbook = $this->workbooks->createWorkbook(
            $request->user(),
            $this->access->findFolder($request->user(), $folderId),
            Store::resolveByNumber($storeId),
            $request->validated(),
        );

        return response()->json(['data' => $this->reader->presentWorkbook($workbook, $request->user(), withColumns: true)], 201);
    }

    public function update(WorkbookUpdateRequest $request, int $workbookId): JsonResponse
    {
        $workbook = $this->workbooks->updateWorkbook(
            $request->user(),
            $this->access->findWorkbook($request->user(), $workbookId),
            $request->validated(),
        );

        return response()->json(['data' => $this->reader->presentWorkbook($workbook, $request->user(), withColumns: true)]);
    }

    public function destroy(Request $request, int $workbookId): Response
    {
        $this->workbooks->deleteWorkbook($request->user(), $this->access->findWorkbook($request->user(), $workbookId));

        return response()->noContent();
    }

    public function retag(WorkbookVisibilityRequest $request, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        $this->workbooks->retag(
            $request->user(),
            $workbook,
            WorkbookVisibility::from($request->validated('visibility')),
            (array) $request->validated('visibility_roles', []),
        );

        return response()->json(['data' => $this->reader->presentWorkbook(
            $workbook->refresh()->load(['store', 'creator', 'columns']),
            $request->user(),
            withColumns: true,
        )]);
    }

    // -------------------------------------------------------------------------
    // Columns
    // -------------------------------------------------------------------------

    public function columns(Request $request, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        return response()->json(['data' => $workbook->columns->map(fn (WorkbookColumn $c) => $this->reader->presentColumn($c))->all()]);
    }

    /**
     * Whole-list replace: the submitted order IS the display order, and a column
     * absent from the submission is deleted with its cells.
     */
    public function replaceColumns(WorkbookColumnsRequest $request, int $workbookId): JsonResponse
    {
        $workbook = $this->workbooks->replaceColumns(
            $request->user(),
            $this->access->findWorkbook($request->user(), $workbookId),
            (array) $request->validated('columns'),
        );

        return response()->json(['data' => $this->reader->presentWorkbook($workbook, $request->user(), withColumns: true)]);
    }
}
