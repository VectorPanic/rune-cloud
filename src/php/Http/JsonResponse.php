<?php

declare(strict_types=1);

namespace RuneCloud\Http;

/**
 * Carries JSON data, status and headers to the central renderer.
 */
final class JsonResponse
{
    //--------------------------------------------------------------------------
    // Public properties
    //--------------------------------------------------------------------------

    /**
     * Contains response data.
     *
     * @var array<string, mixed>
     */
    public readonly array $data;

    /**
     * Contains the HTTP status code.
     *
     * @var int
     */
    public readonly int $status;

    /**
     * Contains additional response headers.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the response data and HTTP metadata.
     *
     * @param array<string, mixed> $data Response data.
     * @param int $status HTTP status.
     * @param array<string, string> $headers Response headers.
     */
    public function __construct(array $data, int $status = 200, array $headers = [])
    {
        $this->data = $data;
        $this->status = $status;
        $this->headers = $headers;
    }
}
