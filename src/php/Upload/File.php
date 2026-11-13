<?php

declare(strict_types=1);

namespace RuneCloud\Upload;

/**
 * Carries validated upload metadata without reading or storing file contents.
 */
final class File
{
    //--------------------------------------------------------------------------
    // Public properties
    //--------------------------------------------------------------------------

    /**
     * PHP-managed temporary upload path, valid during the current request.
     * @var string
     */
    public readonly string $temporaryPath;

    /**
     * Validated file size in bytes.
     * @var int
     */
    public readonly int $size;

    /**
     * SHA-256 checksum of the validated file bytes.
     * @var string
     */
    public readonly string $checksum;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives metadata produced by upload validation.
     * @param string $temporaryPath PHP-managed temporary file path.
     * @param int $size File size in bytes.
     * @param string $checksum SHA-256 checksum.
     */
    public function __construct(string $temporaryPath, int $size, string $checksum)
    {
        $this->temporaryPath = $temporaryPath;
        $this->size = $size;
        $this->checksum = $checksum;
    }
}
