<?php

declare(strict_types=1);

namespace RuneCloud\Http;

/**
 * Describes verified file bytes ready for streaming.
 */
final class FileResponse
{
    //--------------------------------------------------------------------------
    // Public properties
    //--------------------------------------------------------------------------

    /**
     * Contains the revision-relative file path used to determine its content type.
     *
     * @var string
     */
    public readonly string $path;

    /**
     * Contains the published file size in bytes.
     *
     * @var int
     */
    public readonly int $size;

    /**
     * Holds the verified, rewound binary stream owned by this response.
     *
     * @var resource
     */
    public readonly mixed $stream;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the file name, verified size and binary stream.
     *
     * @param string $path Revision-relative file path.
     * @param int $size Published size in bytes.
     * @param resource $stream Verified, rewound binary stream; the view closes it after rendering.
     */
    public function __construct(string $path, int $size, mixed $stream)
    {
        $this->path = $path;
        $this->size = $size;
        $this->stream = $stream;
    }
}
