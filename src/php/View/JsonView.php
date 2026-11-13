<?php

declare(strict_types=1);

namespace RuneCloud\View;

use JsonException;

/**
 * Renders JSON responses with an explicit HTTP status.
 */
final class JsonView
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Encodes the complete body before sending response headers.
     *
     * @param array<string, mixed> $data Response data.
     * @param int $status HTTP status code.
     *
     * @return void
     * @throws JsonException When response data cannot be encoded.
     */
    public function render(array $data, int $status = 200): void
    {
        $options = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

        $body = json_encode($data, $options);

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo $body;
    }
}
