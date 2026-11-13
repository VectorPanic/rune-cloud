<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Http\FileResponse;
use RuneCloud\MySQL\MySQLi;
use RuntimeException;

/**
 * Resolves registered files within published library revisions.
 */
final class Files
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads published revision and file records.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection.
     *
     * @param MySQLi $database Database connection.
     */
    public function __construct(MySQLi $database)
    {
        $this->database = $database;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Resolves an allowed file registered in a published revision.
     *
     * @param string $revision Immutable revision key.
     * @param string $path Revision-relative file path, decoded once by the router.
     *
     * @return FileResponse|null File descriptor, or null when unknown or disallowed.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When published content is missing, ambiguous or fails integrity checks.
     */
    public function find(string $revision, string $path): ?FileResponse
    {
        return $this->resolve($revision, $path, $this->frozenQuery());
    }

    /**
     * Resolves a published release file for internal snapshot import.
     * @param string $revision Source revision key.
     * @param string $path Source file path.
     * @return FileResponse|null Verified file or null.
     * @throws \Throwable When file metadata or bytes are invalid.
     */
    public function findRelease(string $revision, string $path): ?FileResponse
    {
        return $this->resolve($revision, $path, $this->releaseQuery());
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Verifies a file from the explicitly selected storage query.
     * @param string $revision Published source key.
     * @param string $path File path.
     * @param string $query Explicit metadata query.
     * @return FileResponse|null Verified file or null.
     * @throws \Throwable When metadata or integrity checks fail.
     */
    private function resolve(string $revision, string $path, string $query): ?FileResponse
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $revision) !== 1) {
            return null;
        }
        
        $segments = explode('/', $path);
        
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || strpbrk($segment, "\\\0") !== false
            ) {
                return null;
            }
        }
        
        if ($path !== 'categories.json' && $path !== 'tags.json'
            && !str_starts_with($path, 'games/') && !str_starts_with($path, 'screensaver/')
        ) {
            return null;
        }
        
        $record = $this->database->fetchOne(
            'SELECT id FROM library_revisions
                WHERE BINARY revision = BINARY ? AND published_at IS NOT NULL',
            [$revision]
        );
        
        if ($record === null) {
            return null;
        }
        
        $rows = $this->database->fetchAll($query, [(int) $record->id, $path]);
        
        if ($rows === []) {
            return null;
        }
        
        if (count($rows) !== 1) {
            throw new RuntimeException('Ambiguous file in published revision.');
        }
        
        $row = $rows[0];
        
        if ($row->contentId === null || $row->storedSize === null || (int) $row->storedSize !== (int) $row->size) {
            throw new RuntimeException('Published file content is missing or has the wrong size.');
        }
        
        $stream = $this->readContent((int) $row->contentId, (int) $row->size, $row->checksum);
        
        return new FileResponse($path, (int) $row->size, $stream);
    }

    /**
     * Selects frozen file metadata for byte verification.
     * @return string SQL using revision ID and file path parameters.
     */
    private function frozenQuery(): string
    {
        return <<<'SQL'
        SELECT f.content_id AS contentId, f.size_bytes AS size, f.checksum,
        OCTET_LENGTH(c.content) AS storedSize
        FROM library_revision_files AS f
        LEFT JOIN file_contents AS c ON c.id = f.content_id
        WHERE f.library_revision_id = ? AND BINARY f.path = BINARY ?
        SQL;
    }

    /**
     * Selects published release file metadata for internal import.
     * @return string SQL using revision ID and file path parameters.
     */
    private function releaseQuery(): string
    {
        return <<<'SQL'
        SELECT f.content_id AS contentId, f.size_bytes AS size, f.checksum,
        OCTET_LENGTH(c.content) AS storedSize
        FROM library_revision_releases AS vr
        JOIN releases AS r ON r.id = vr.release_id
        JOIN release_files AS f ON f.release_id = r.id
        LEFT JOIN file_contents AS c ON c.id = f.content_id
        WHERE vr.library_revision_id = ? AND BINARY f.path = BINARY ?
        AND r.published_at IS NOT NULL
        SQL;
    }

    /**
     * Copies bounded database chunks to a temporary stream and verifies the original bytes.
     *
     * @param int $contentId Stored content ID.
     * @param int $size Published size in bytes.
     * @param string $checksum Published SHA-256 checksum.
     *
     * @return resource Verified stream positioned at its beginning.
     * @throws \mysqli_sql_exception When a chunk query fails.
     * @throws RuntimeException When reading, temporary storage or integrity verification fails.
     */
    private function readContent(int $contentId, int $size, string $checksum): mixed
    {
        $stream = @tmpfile();
        
        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary file stream.');
        }
        
        try {
            $hash = hash_init('sha256');
            $chunkSize = 1024 * 1024;
            
            for ($offset = 0; $offset < $size; $offset += $chunkSize) {
                $length = min($chunkSize, $size - $offset);
                $row = $this->database->fetchOne(
                    'SELECT SUBSTRING(content, ?, ?) AS bytes FROM file_contents WHERE id = ?',
                    [$offset + 1, $length, $contentId]
                );
                $bytes = $row?->bytes;
                
                if ($bytes === null || strlen($bytes) !== $length || @fwrite($stream, $bytes) !== $length) {
                    throw new RuntimeException('Unable to read the complete published file content.');
                }
                
                hash_update($hash, $bytes);
            }
            
            if (!hash_equals(strtolower($checksum), hash_final($hash)) || !rewind($stream)) {
                throw new RuntimeException('Published file checksum verification failed.');
            }
            
            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }
}
