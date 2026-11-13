<?php

declare(strict_types=1);

namespace RuneCloud\Http;

use Throwable;

/**
 * Converts failures to the public API error contract.
 */
final class Errors
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Preserves expected failures and logs unexpected failures without exposing details.
     * @param Throwable $error Failure from request processing or response encoding.
     * @return JsonResponse Stable error identifier, HTTP status and required headers.
     */
    public function response(Throwable $error): JsonResponse
    {
        if ($error instanceof HttpException) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getCode(), $error->headers);
        }
        
        error_log((string) $error);
        return new JsonResponse(['error' => 'internal_server_error'], 500);
    }
}
