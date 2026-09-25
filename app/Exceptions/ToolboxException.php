<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * A domain failure the API renders as a structured error the UI can act on.
 *
 * `errorCode` is the contract a client branches on; `context` becomes the rest
 * of the `error` object:
 *
 *     {"message": "...", "error": {"code": "ALREADY_ON_BREAK", "running": {...}}}
 *
 * Laravel calls render() itself, so there is no renderer class and nothing to
 * register in bootstrap/app.php. Each module's failures live in a subclass that
 * holds only its named constructors.
 */
class ToolboxException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 409,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => ['code' => $this->errorCode] + $this->context,
        ], $this->statusCode);
    }

    /**
     * The store exists in pizzasys but has not replicated here yet - the store
     * analogue of "user not synced yet". Shared by every store-scoped route.
     */
    public static function storeNotFound(string $storeNumber): static
    {
        return new static(
            "Store {$storeNumber} is not known here yet.",
            'STORE_NOT_FOUND',
            404,
            ['store_number' => $storeNumber],
        );
    }
}
