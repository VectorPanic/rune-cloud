<?php

declare(strict_types=1);

namespace RuneCloud\Http;

use RuntimeException;

/**
 * Describes an HTTP failure without rendering a response.
 */
final class HttpException extends RuntimeException
{
    //--------------------------------------------------------------------------
    // Public properties
    //--------------------------------------------------------------------------

    /**
     * Contains response headers required by the failure.
     *
     * @var array<string, string>
     */
    public readonly array $headers;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the public error identifier, HTTP status and response headers.
     *
     * @param string $error Public error identifier.
     * @param int $status HTTP status code.
     * @param array<string, string> $headers Additional response headers.
     * @throws \InvalidArgumentException When the error identifier or status is invalid.
     */
    public function __construct(string $error, int $status, array $headers = [])
    {
        if ($status < 400 || $status > 599 || preg_match('/^[a-z][a-z0-9_]*$/D', $error) !== 1) {
            throw new \InvalidArgumentException('HTTP errors require a stable identifier and a 4xx or 5xx status.');
        }
        
        parent::__construct($error, $status);
        $this->headers = $headers;
    }
}
