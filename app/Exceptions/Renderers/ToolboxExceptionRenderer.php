<?php

namespace App\Exceptions\Renderers;

use App\Services\Breaks\Exceptions\BreakException;
use App\Services\Tickets\Exceptions\TicketException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Domain failures become structured errors the UI can act on, rather than a
 * generic 500. The `error.code` is the contract - a client branches on it
 * (ALREADY_ON_BREAK offers "end the running one", BREAK_OVERLAP can show the
 * conflicting entries it is handed back in `error.conflicts`).
 */
class ToolboxExceptionRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        if ($e instanceof BreakException) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode] + $e->context,
            ], $e->statusCode);
        }

        if ($e instanceof TicketException) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode] + $e->context,
            ], $e->statusCode);
        }

        return null;
    }
}
