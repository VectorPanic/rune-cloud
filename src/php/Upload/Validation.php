<?php

declare(strict_types=1);

namespace RuneCloud\Upload;

use RuneCloud\Http\HttpException;
use RuntimeException;

/**
 * Validates HTTP uploads before their bytes are inserted into the database.
 */
final class Validation
{
    //--------------------------------------------------------------------------
    // Private constants
    //--------------------------------------------------------------------------

    /**
     * Required Rune OS icon atlas width in pixels.
     * @var int
     */
    private const ICON_WIDTH = 442;

    /**
     * Required Rune OS icon atlas height in pixels.
     * @var int
     */
    private const ICON_HEIGHT = 884;

    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Upload validation limits.
     *
     * @var array
     */
    private array $config;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives upload validation limits.
     *
     * @param array<string, int|string> $config Storage configuration.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Validates one genuine HTTP upload without creating permanent disk files.
     *
     * @param string $kind JavaScript executable or game icon atlas.
     * @param array<string, mixed> $upload PHP upload metadata.
     *
     * @return File Validated temporary path, size and checksum.
     * @throws HttpException When validation fails.
     * @throws RuntimeException When storage fails.
     */
    public function validate(string $kind, array $upload): File
    {
        $this->reception($upload);
        $temporary = $upload['tmp_name'];
        $this->temporaryFile($temporary);
        $this->extension($kind, $upload['name'] ?? null);
        $size = $this->size($kind, $temporary);
        $this->content($kind, $temporary);

        return new File($temporary, $size, $this->checksum($temporary));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks PHP reception errors and the temporary path field.
     * @param array<string, mixed> $upload PHP upload metadata.
     * @return void
     * @throws HttpException When PHP rejects the upload.
     * @throws RuntimeException When the server could not receive the file.
     */
    private function reception(array $upload): void
    {
        $error = $upload['error'] ?? null;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpException('upload_too_large', 413);
        }
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            throw new RuntimeException('Server could not receive the uploaded file.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
            throw new HttpException('upload_failed', 422);
        }
    }

    /**
     * Requires a genuine PHP-managed HTTP upload.
     * @param string $path Temporary file path.
     * @return void
     * @throws HttpException When validation fails.
     */
    private function temporaryFile(string $path): void
    {
        if (!is_uploaded_file($path) || !is_file($path)) {
            throw new HttpException('invalid_upload', 422);
        }
    }

    /**
     * Checks the filename extension required for the upload kind.
     * @param string $kind JavaScript executable or game icon atlas.
     * @param mixed $name Submitted filename.
     * @return void
     * @throws HttpException When validation fails.
     */
    private function extension(string $kind, mixed $name): void
    {
        $extension = $kind === 'icon' ? 'png' : 'js';
        if (!is_string($name)
            || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension
        ) {
            throw new HttpException('invalid_file_extension', 422);
        }
    }

    /**
     * Checks the byte size against the configured limit.
     * @param string $kind JavaScript executable or game icon atlas.
     * @param string $temporary Temporary file path.
     * @return int
     * @throws HttpException When validation fails.
     */
    private function size(string $kind, string $temporary): int
    {
        $size = filesize($temporary);
        $limit = $this->config[$kind === 'icon' ? 'iconLimit' : 'gameLimit'];
        if ($size === false || $size === 0) {
            throw new HttpException('empty_upload', 422);
        }
        if ($size > $limit) {
            throw new HttpException('upload_too_large', 413);
        }

        return $size;
    }

    /**
     * Selects the content checks required by the upload kind.
     * @param string $kind JavaScript executable or game icon atlas.
     * @param string $temporary Temporary file path.
     * @return void
     * @throws HttpException When validation fails.
     */
    private function content(string $kind, string $temporary): void
    {
        if ($kind === 'icon') {
            $this->icon($temporary);
        } elseif ($kind === 'game') {
            $this->javascript($temporary);
        }
    }

    /**
     * Requires a valid PNG with the exact dimensions of a Rune OS icon atlas.
     * @param string $temporary Temporary file path.
     * @return void
     * @throws HttpException When the PNG or atlas dimensions are invalid.
     */
    private function icon(string $temporary): void
    {
        $image = $this->png($temporary);
        if ($image[0] !== self::ICON_WIDTH || $image[1] !== self::ICON_HEIGHT) {
            throw new HttpException('invalid_icon_dimensions', 422);
        }
    }

    /**
     * Checks PNG signature, format and image dimensions.
     * @param string $temporary Temporary file path.
     * @return array Validated image metadata, including width and height.
     * @throws HttpException When validation fails.
     */
    private function png(string $temporary): array
    {
        $image = @getimagesize($temporary);
        $signature = file_get_contents($temporary, false, null, 0, 8);
        if ($signature !== "\x89PNG\r\n\x1a\n" || $image === false || $image[2] !== IMAGETYPE_PNG
            || $image[0] < 1 || $image[1] < 1
            || max($image[0], $image[1]) > $this->config['maxDimension']
            || $image[0] * $image[1] > $this->config['maxPixels']
        ) {
            throw new HttpException('invalid_png', 422);
        }

        return $image;
    }

    /**
     * Checks that the uploaded executable has an accepted text MIME type.
     * @param string $temporary Temporary file path.
     * @return void
     * @throws HttpException When validation fails.
     */
    private function javascript(string $temporary): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $allowed = ['text/plain', 'text/javascript', 'application/javascript', 'application/x-javascript'];
        if (!in_array($mime, $allowed, true)) {
            throw new HttpException('invalid_javascript_file', 422);
        }
    }

    /**
     * Calculates the checksum of the validated file bytes.
     * @param string $temporary Temporary file path.
     * @return string
     * @throws RuntimeException When the checksum cannot be read.
     */
    private function checksum(string $temporary): string
    {
        $checksum = hash_file('sha256', $temporary);
        if ($checksum === false) {
            throw new RuntimeException('Unable to hash uploaded file.');
        }

        return $checksum;
    }
}
