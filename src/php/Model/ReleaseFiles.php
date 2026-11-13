<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use RuneCloud\Upload\File;

/**
 * Replaces draft release files and cleans up unreferenced binary contents.
 */
final class ReleaseFiles
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores release file references.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Stores and cleans shared binary contents.
     * @var FileContents
     */
    private readonly FileContents $contents;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives storage dependencies sharing the caller's transaction connection.
     * @param MySQLi $database Shared database connection.
     * @param FileContents $contents Shared binary storage.
     */
    public function __construct(MySQLi $database, FileContents $contents)
    {
        $this->database = $database;
        $this->contents = $contents;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Replaces a validated draft file inside the caller's transaction.
     * The caller checks ownership and draft status before invoking this operation.
     * @param int $releaseId Draft release ID.
     * @param string $path Full bundle path.
     * @param File $file Validated upload metadata.
     * @param string|null $kind Game file type, or null to preserve the existing type.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see GameVersions::upload()
     * @see ScreensaverVersions::upload()
     * @throws \LogicException When the shared connection has no active transaction.
     * @return void
     * @throws \Throwable When storage or cleanup fails.
     */
    public function replace(int $releaseId, string $path, File $file, ?string $kind = null): void
    {
        $this->database->requireTransaction();

        $contentId = $this->contents->storeFile($file->temporaryPath);
        $previous = $this->contentIds($releaseId, $path);
        $this->save($releaseId, $path, $kind, $contentId, $file);
        $this->contents->deleteUnused($previous);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Returns the content IDs currently referenced at the draft file path.
     * @param int $releaseId Draft release ID.
     * @param string $path Release file path.
     * @return list<int> Previous content IDs.
     * @throws \mysqli_sql_exception When the query fails.
     */
    private function contentIds(int $releaseId, string $path): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.id FROM release_files AS f
                JOIN file_contents AS c ON c.id = f.content_id WHERE f.release_id = ? AND f.path = ?',
            [$releaseId, $path]
        );
        
        return array_map(fn ($row): int => (int) $row->id, $rows);
    }

    /**
     * Inserts or replaces a draft file reference with validated content metadata.
     * @param int $releaseId Draft release ID.
     * @param string $path Release file path.
     * @param string|null $kind Game file type, or null to preserve the existing type.
     * @param int $contentId Newly stored content ID.
     * @param File $file Validated upload metadata.
     * @return void
     * @throws \mysqli_sql_exception When the write fails.
     */
    private function save(int $releaseId, string $path, ?string $kind, int $contentId, File $file): void
    {
        $this->database->execute(
            'INSERT INTO release_files
                (release_id, content_id, path, checksum, size_bytes, file_type, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE
                content_id = VALUES(content_id), checksum = VALUES(checksum),
                size_bytes = VALUES(size_bytes),
                file_type = COALESCE(VALUES(file_type), file_type), uploaded_at = NOW()',
            [$releaseId, $contentId, $path, $file->checksum, $file->size, $kind]
        );
    }
}
