<?php

declare(strict_types=1);

namespace RuneCloud\View;

use RuneCloud\Http\FileResponse;
use RuntimeException;

/**
 * Streams file bytes with a content type appropriate to the file extension.
 */
final class FileView
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Checks the prepared stream before sending headers, then sends its bytes.
     *
     * @param FileResponse $file Verified file descriptor.
     *
     * @return void
     * @throws RuntimeException When the file cannot be opened or its size differs from publication.
     */
    public function render(FileResponse $file): void
    {
        $stream = $file->stream;

        if (!is_resource($stream)) {
            throw new RuntimeException('Invalid published file stream.');
        }

        try {
            $stat = fstat($stream);

            if ($stat === false || $stat['size'] !== $file->size || !rewind($stream)) {
                throw new RuntimeException('Published file size does not match its record.');
            }

            $contentType = match (strtolower(pathinfo($file->path, PATHINFO_EXTENSION))) {
                'json' => 'application/json',
                'js', 'mjs' => 'text/javascript',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'wasm' => 'application/wasm',
                'ogg' => 'audio/ogg',
                'mp3' => 'audio/mpeg',
                'wav' => 'audio/wav',
                default => 'application/octet-stream',
            };

            http_response_code(200);
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . $stat['size']);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');

            if (@fpassthru($stream) === false) {
                error_log('Unable to finish streaming published file.');
            }
        } finally {
            fclose($stream);
        }
    }
}
