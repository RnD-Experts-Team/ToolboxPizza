<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkbookVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WorkbookRowReorderRequest;
use App\Http\Requests\Api\V1\WorkbookRowRequest;
use App\Http\Requests\Api\V1\WorkbookVisibilityRequest;
use App\Models\Store;
use App\Services\Workbooks\WorkbookAccessService;
use App\Services\Workbooks\WorkbookQueryService;
use App\Services\Workbooks\WorkbookRowService;
use App\Services\Workbooks\WorkbookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The grid, and the rows in it.
 */
class WorkbookRowController extends Controller
{
    public function __construct(
        private readonly WorkbookRowService $rows,
        private readonly WorkbookService $workbooks,
        private readonly WorkbookQueryService $reader,
        private readonly WorkbookAccessService $access,
    ) {}

    public function index(Request $request, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        return response()->json(['data' => $this->rows->grid(
            $request->user(),
            $workbook,
            $request->only(['search', 'filter', 'sort_column', 'sort_order', 'per_page']),
        )]);
    }

    public function show(Request $request, int $workbookId, int $rowId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);
        $row = $this->access->findRow($request->user(), $workbook, $rowId);

        return response()->json(['data' => $this->reader->presentSingleRow($row, $workbook->columns, $request->user())]);
    }

    /**
     * The store in the path is the store the ROW is added at, which need not be
     * the workbook's - that is what lets every store add lines to one shared
     * workbook and see only its own.
     */
    public function store(WorkbookRowRequest $request, string $storeId, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        $row = $this->rows->create($request->user(), $workbook, Store::resolveByNumber($storeId), $request->validated());

        return response()->json(['data' => $this->reader->presentSingleRow($row, $workbook->columns, $request->user())], 201);
    }

    public function update(WorkbookRowRequest $request, int $workbookId, int $rowId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        $row = $this->rows->update(
            $request->user(),
            $this->access->findRow($request->user(), $workbook, $rowId),
            $workbook,
            $request->validated(),
        );

        return response()->json(['data' => $this->reader->presentSingleRow($row, $workbook->columns, $request->user())]);
    }

    public function retag(WorkbookVisibilityRequest $request, int $workbookId, int $rowId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);
        $row = $this->access->findRow($request->user(), $workbook, $rowId);

        $this->workbooks->retag(
            $request->user(),
            $row,
            WorkbookVisibility::from($request->validated('visibility')),
            (array) $request->validated('visibility_roles', []),
        );

        return response()->json(['data' => $this->reader->presentSingleRow(
            $row->refresh()->load(['store', 'creator', 'cells']),
            $workbook->columns,
            $request->user(),
        )]);
    }

    /**
     * Rows not named keep their position, so reordering a filtered view leaves
     * the rest alone.
     */
    public function reorder(WorkbookRowReorderRequest $request, int $workbookId): JsonResponse
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);
        $rowIds = array_map('intval', (array) $request->validated('row_ids'));

        $this->rows->reorder($request->user(), $workbook, $rowIds);

        return response()->json(['data' => ['workbook_id' => (int) $workbook->id, 'row_ids' => $rowIds]]);
    }

    public function destroy(Request $request, int $workbookId, int $rowId): Response
    {
        $workbook = $this->access->findWorkbook($request->user(), $workbookId);

        $this->rows->delete($request->user(), $this->access->findRow($request->user(), $workbook, $rowId));

        return response()->noContent();
    }
}
