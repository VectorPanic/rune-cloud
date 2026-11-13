<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;

/**
 * Stores binary content and removes it only when no database references remain.
 */
final class FileContents
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores content on the caller's shared transaction connection.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection.
     * @param MySQLi $database Shared connection.
     */
    public function __construct(MySQLi $database)
    {
        $this->database = $database;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Stores bytes without changing their encoding or content.
     * @param string $bytes Raw bytes, including binary or serialized JSON.
     * @return int Content ID.
     * @throws \Throwable When storage fails.
     */
    public function store(string $bytes): int
    {
        return $this->database->insert('INSERT INTO file_contents (content) VALUES (?)', [$bytes]);
    }

    /**
     * Streams a file into the database without creating a permanent disk copy.
     * @param string $path Readable temporary file path.
     * @return int Content ID.
     * @throws \Throwable When reading or storage fails.
     */
    public function storeFile(string $path): int
    {
        return $this->database->insertFile('INSERT INTO file_contents (content) VALUES (?)', $path);
    }

    /**
     * Removes candidate content only when no release, snapshot or manifest uses it.
     * @param list<int> $contentIds Candidate content IDs.
     * @return void
     * @throws \Throwable When deletion fails.
     */
    public function deleteUnused(array $contentIds): void
    {
        $ids = array_values(array_unique($contentIds));
        if ($ids === []) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->database->execute(
            "DELETE FROM file_contents WHERE id IN ($placeholders)
                AND NOT EXISTS (SELECT 1 FROM release_files WHERE content_id = file_contents.id)
                AND NOT EXISTS (SELECT 1 FROM library_revision_files WHERE content_id = file_contents.id)
                AND NOT EXISTS (SELECT 1 FROM library_revisions WHERE manifest_content_id = file_contents.id)",
            $ids
        );
    }
}
