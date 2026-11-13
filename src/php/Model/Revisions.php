<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use RuntimeException;
use stdClass;

/**
 * Stores library revisions, frozen file references and release membership.
 */
final class Revisions
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads revision and manifest records.
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
     * Requires the caller's transaction before composing a snapshot or removal.
     * @internal Used by library storage steps; application workflows own the transaction.
     * @return void
     * @throws \LogicException When the shared connection has no active transaction.
     */
    public function requireTransaction(): void
    {
        $this->database->requireTransaction();
    }

    /**
     * Returns the stored manifest of the most recently published complete revision.
     *
     * @return array<string, mixed>|null Manifest, or null before the first complete publication.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \JsonException When stored JSON is invalid.
     * @throws RuntimeException When the stored manifest does not match its revision.
     */
    public function getCurrentManifest(): ?array
    {
        $row = $this->findCurrent();

        if ($row === null) {
            return null;
        }

        if ($row->content === null) {
            throw new RuntimeException('Published manifest content is missing.');
        }

        $manifest = json_decode($row->content, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 1
            || ($manifest['revision'] ?? null) !== $row->revision
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])
        ) {
            throw new RuntimeException('Invalid published library manifest.');
        }

        return $manifest;
    }

    /**
     * Returns the latest complete published revision with its frozen manifest.
     * @return stdClass|null
     * @throws \Throwable When revision storage fails.
     */
    public function findCurrent(): ?stdClass
    {
        return $this->database->fetchOne(
            'SELECT v.id, v.revision, v.manifest_content_id, c.content FROM library_revisions v
                LEFT JOIN file_contents c ON c.id = v.manifest_content_id
                WHERE v.published_at IS NOT NULL AND v.manifest_content_id IS NOT NULL
                ORDER BY v.published_at DESC, v.id DESC LIMIT 1'
        );
    }

    /**
     * Reads current game JSON candidates and their frozen bytes in one query.
     * @return list<stdClass> File paths and content; null content indicates missing storage.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function getCurrentGameFiles(): array
    {
        return $this->database->fetchAll(
            <<<'SQL'
            SELECT f.path, c.content
            FROM library_revision_files f
            LEFT JOIN file_contents c ON c.id = f.content_id
            WHERE f.library_revision_id = (
                SELECT id FROM library_revisions
                WHERE published_at IS NOT NULL AND manifest_content_id IS NOT NULL
                ORDER BY published_at DESC, id DESC LIMIT 1
            ) AND f.path LIKE 'games/%.json'
            SQL
        );
    }

    /**
     * Finds a revision by its exact key.
     * @param string $revision Revision key.
     * @return stdClass|null
     * @throws \Throwable When revision storage fails.
     */
    public function find(string $revision): ?stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, revision, manifest_content_id, published_at
                FROM library_revisions
                WHERE BINARY revision = BINARY ?',
            [$revision]
        );
    }

    /**
     * Finds a published source, including one without a complete manifest.
     * @param string $revision Revision key.
     * @return stdClass|null
     * @throws \Throwable When revision storage fails.
     */
    public function findPublished(string $revision): ?stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, revision, manifest_content_id
                FROM library_revisions
                WHERE BINARY revision = BINARY ? AND published_at IS NOT NULL',
            [$revision]
        );
    }

    /**
     * Reads revision manifests for historical cleanup.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function all(): array
    {
        return $this->database->fetchAll(
            'SELECT v.id, v.manifest_content_id, c.content
                FROM library_revisions v
                LEFT
                JOIN file_contents c ON c.id = v.manifest_content_id'
        );
    }

    /**
     * Creates an unpublished revision.
     * @param string $revision New revision key.
     * @return int
     * @throws \Throwable When revision storage fails.
     */
    public function create(string $revision): int
    {
        return $this->database->insert('INSERT INTO library_revisions (revision) VALUES (?)', [$revision]);
    }

    /**
     * Creates a temporary published source inside the caller transaction.
     * @param string $revision Temporary source key.
     * @return int
     * @throws \Throwable When revision storage fails.
     */
    public function createSource(string $revision): int
    {
        return $this->database->insert(
            'INSERT INTO library_revisions (revision, published_at) VALUES (?, NOW())', [$revision]
        );
    }

    /**
     * Assigns stored manifest content to a revision.
     * @param int $id Revision ID.
     * @param int $contentId Manifest content ID.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function setManifest(int $id, int $contentId): void
    {
        $this->database->execute('UPDATE library_revisions SET manifest_content_id = ? WHERE id = ?', [$contentId, $id]);
    }

    /**
     * Activates a completed revision with its stored manifest.
     * @param int $id Revision ID.
     * @param int $manifestId Frozen manifest content ID.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function publish(int $id, int $manifestId): void
    {
        $this->database->execute(
            'UPDATE library_revisions SET manifest_content_id = ?, published_at = NOW()
                WHERE id = ?',
            [$manifestId, $id]
        );
    }

    /**
     * Reads frozen files in stable manifest order.
     * @param int $id Revision ID.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function getFiles(int $id): array
    {
        return $this->database->fetchAll(
            'SELECT path, content_id, checksum, size_bytes
                FROM library_revision_files
                WHERE library_revision_id = ?
                ORDER BY path',
            [$id]
        );
    }

    /**
     * Reads release membership with optional owning game IDs.
     * @param int $id Revision ID.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function getReleases(int $id): array
    {
        return $this->database->fetchAll(
            'SELECT r.id, gv.game_id,
                CASE WHEN gv.game_id IS NOT NULL THEN CONCAT(\'games/\', g.identifier, \'/\')
                    ELSE CONCAT(\'screensaver/\', s.identifier, \'/\') END AS folder
                FROM library_revision_releases vr
                JOIN releases r ON r.id = vr.release_id
                LEFT JOIN game_versions gv ON gv.id = r.game_version_id
                LEFT JOIN games g ON g.id = gv.game_id
                LEFT JOIN screensaver_versions sv ON sv.id = r.screensaver_version_id
                LEFT JOIN screensavers s ON s.id = sv.screensaver_id
                WHERE vr.library_revision_id = ?',
            [$id]
        );
    }

    /**
     * Adds an immutable file reference to a revision.
     * @param int $id Revision ID.
     * @param string $path Bundle-relative path.
     * @param int $contentId Stored bytes.
     * @param string $checksum SHA-256 digest.
     * @param int $size Byte count.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function addFile(int $id, string $path, int $contentId, string $checksum, int $size): void
    {
        $this->database->execute(
            'INSERT INTO library_revision_files (library_revision_id, path, content_id, checksum, size_bytes)
                VALUES (?, ?, ?, ?, ?)', [$id, $path, $contentId, $checksum, $size]
        );
    }

    /**
     * Adds selected release membership to a revision.
     * @param int $id Revision ID.
     * @param int $releaseId Release ID.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function addRelease(int $id, int $releaseId): void
    {
        $this->database->execute(
            'INSERT INTO library_revision_releases (library_revision_id, release_id) VALUES (?, ?)', [$id, $releaseId]
        );
    }

    /**
     * Removes a revision and its references; content cleanup remains explicit.
     * @param int $id Revision ID.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM library_revision_files WHERE library_revision_id = ?', [$id]);
        $this->database->execute('DELETE FROM library_revision_releases WHERE library_revision_id = ?', [$id]);
        $this->database->execute('DELETE FROM library_revisions WHERE id = ?', [$id]);
    }

    /**
     * Finds revisions linked to any version of a game.
     * @param int $gameId Game ID.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function allForGame(int $gameId): array
    {
        return $this->database->fetchAll(
            'SELECT DISTINCT vr.library_revision_id AS id
                FROM library_revision_releases vr
                JOIN releases r ON r.id = vr.release_id
                JOIN game_versions gv ON gv.id = r.game_version_id
                WHERE gv.game_id = ?',
            [$gameId]
        );
    }

    /**
     * Finds revision membership for all versions of a screensaver.
     * @param int $screensaverId Screensaver ID.
     * @return list<stdClass> Linked revisions.
     * @throws \Throwable When the lookup fails.
     */
    public function allForScreensaver(int $screensaverId): array
    {
        return $this->database->fetchAll('SELECT DISTINCT vr.library_revision_id AS id
            FROM library_revision_releases vr JOIN releases r ON r.id=vr.release_id
            JOIN screensaver_versions sv ON sv.id=r.screensaver_version_id WHERE sv.screensaver_id=?', [$screensaverId]);
    }

    /**
     * Finds revisions linked to one screensaver version.
     * @param int $versionId Screensaver version ID.
     * @return list<stdClass> Linked revisions.
     * @throws \Throwable When lookup fails.
     */
    public function allForScreensaverVersion(int $versionId): array
    {
        return $this->database->fetchAll('SELECT DISTINCT vr.library_revision_id AS id
            FROM library_revision_releases vr JOIN releases r ON r.id=vr.release_id
            WHERE r.screensaver_version_id=?', [$versionId]);
    }

    /**
     * Finds revisions linked to a particular game version.
     * @param int $versionId Game version ID.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function allForGameVersion(int $versionId): array
    {
        return $this->database->fetchAll(
            'SELECT DISTINCT vr.library_revision_id AS id
                FROM library_revision_releases vr
                JOIN releases r ON r.id = vr.release_id
                WHERE r.game_version_id = ?',
            [$versionId]
        );
    }

    /**
     * Reads historical files under a bundle folder without acquiring row locks.
     * @param string $prefix Exact folder prefix.
     * @return list<stdClass>
     * @throws \Throwable When revision storage fails.
     */
    public function filesWithPrefix(string $prefix): array
    {
        return $this->database->fetchAll(
            'SELECT library_revision_id, path, content_id
                FROM library_revision_files
                WHERE path LIKE ?',
            [$prefix . '%']
        );
    }

    /**
     * Checks for historical folder references without acquiring row locks.
     * @param string $prefix Exact folder prefix.
     * @return bool
     * @throws \Throwable When revision storage fails.
     */
    public function hasFiles(string $prefix): bool
    {
        return $this->database->fetchOne(
            'SELECT path FROM library_revision_files WHERE path LIKE ? LIMIT 1', [$prefix . '%']
        ) !== null;
    }

    /**
     * Checks revision membership without acquiring row locks.
     * @param int $releaseId Release ID.
     * @return bool
     * @throws \Throwable When revision storage fails.
     */
    public function hasRelease(int $releaseId): bool
    {
        return $this->database->fetchOne(
            'SELECT library_revision_id FROM library_revision_releases WHERE release_id = ? LIMIT 1', [$releaseId]
        ) !== null;
    }

    /**
     * Locks historical files under a folder before removing their references.
     * @param string $prefix Exact folder prefix.
     * @return list<stdClass> Locked file references.
     * @throws \LogicException When no transaction is active.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function lockFiles(string $prefix): array
    {
        $this->database->requireTransaction();

        return $this->database->fetchAll(
            'SELECT library_revision_id, path, content_id FROM library_revision_files WHERE path LIKE ? FOR UPDATE',
            [$prefix . '%']
        );
    }

    /**
     * Locks the first historical folder reference for a deletion restriction check.
     * @param string $prefix Exact folder prefix.
     * @return stdClass|null Locked reference, or null when absent.
     * @throws \LogicException When no transaction is active.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function lockFileReference(string $prefix): ?stdClass
    {
        $this->database->requireTransaction();

        return $this->database->fetchOne(
            'SELECT path FROM library_revision_files WHERE path LIKE ? LIMIT 1 FOR UPDATE', [$prefix . '%']
        );
    }

    /**
     * Locks revision membership before changing a release.
     * @param int $releaseId Release ID.
     * @return list<stdClass> Locked revision links.
     * @throws \LogicException When no transaction is active.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function lockReleaseLinks(int $releaseId): array
    {
        $this->database->requireTransaction();

        return $this->database->fetchAll(
            'SELECT library_revision_id FROM library_revision_releases WHERE release_id = ? FOR UPDATE', [$releaseId]
        );
    }

    /**
     * Checks whether a revision selects a game version.
     * @param int $id Revision ID.
     * @param int $versionId Game version ID.
     * @return bool
     * @throws \Throwable When revision storage fails.
     */
    public function containsVersion(int $id, int $versionId): bool
    {
        return $this->database->fetchOne(
            'SELECT r.id FROM library_revision_releases vr JOIN releases r ON r.id = vr.release_id
                WHERE vr.library_revision_id = ? AND r.game_version_id = ?', [$id, $versionId]
        ) !== null;
    }

    /**
     * Removes a release from historical revision membership.
     * @param int $releaseId Release ID.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function removeRelease(int $releaseId): void
    {
        $this->database->execute('DELETE FROM library_revision_releases WHERE release_id = ?', [$releaseId]);
    }

    /**
     * Removes revision membership for the selected releases in one operation.
     * @param list<int> $releaseIds Selected release IDs.
     * @return void
     * @throws \mysqli_sql_exception When deletion fails.
     */
    public function removeReleases(array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($releaseIds), '?'));
        $this->database->execute(
            "DELETE FROM library_revision_releases WHERE release_id IN ($placeholders)", $releaseIds
        );
    }

    /**
     * Removes one historical file reference.
     * @param int $id Revision ID.
     * @param string $path Exact file path.
     * @return void
     * @throws \Throwable When revision storage fails.
     */
    public function removeFile(int $id, string $path): void
    {
        $this->database->execute(
            'DELETE FROM library_revision_files WHERE library_revision_id = ? AND path = ?', [$id, $path]
        );
    }


    /**
     * Reads published game releases selected by a source revision.
     * @param int $sourceId Source revision ID.
     * @return list<stdClass>
     * @throws \Throwable When the lookup fails.
     */
    public function getGameVersions(int $sourceId): array
    {
        return $this->database->fetchAll(
            <<<'SQL'
            SELECT g.identifier AS id, gv.id AS versionId, gv.stage, r.id AS releaseId
            FROM library_revision_releases AS vr
            JOIN releases AS r ON r.id = vr.release_id
            JOIN game_versions AS gv ON gv.id = r.game_version_id
            JOIN games AS g ON g.id = gv.game_id
            WHERE vr.library_revision_id = ? AND r.published_at IS NOT NULL
            ORDER BY g.identifier COLLATE utf8mb4_bin
            SQL,
            [$sourceId]
        );
    }

    /**
     * Reads published screensaver releases selected by a source revision.
     * @param int $sourceId Source revision ID.
     * @return list<stdClass>
     * @throws \Throwable When the lookup fails.
     */
    public function getScreensaverVersions(int $sourceId): array
    {
        return $this->database->fetchAll(
            <<<'SQL'
            SELECT s.identifier AS id, sv.id AS versionId, r.id AS releaseId
            FROM library_revision_releases AS vr
            JOIN releases AS r ON r.id = vr.release_id
            JOIN screensaver_versions AS sv ON sv.id = r.screensaver_version_id
            JOIN screensavers AS s ON s.id = sv.screensaver_id
            WHERE vr.library_revision_id = ? AND r.published_at IS NOT NULL
            ORDER BY s.identifier COLLATE utf8mb4_bin
            SQL,
            [$sourceId]
        );
    }

    /**
     * Reads frozen file references from one revision.
     * @param int $revisionId Revision ID.
     * @return list<stdClass> Frozen file metadata.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function getFrozenFiles(int $revisionId): array
    {
        return $this->database->fetchAll(
            'SELECT path, content_id AS contentId, size_bytes AS size, checksum
                FROM library_revision_files WHERE library_revision_id = ?', [$revisionId]
        );
    }

    /**
     * Reads files from the published releases selected by a revision.
     * @param int $revisionId Revision ID.
     * @return list<stdClass> Published release file metadata.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function getReleaseFiles(int $revisionId): array
    {
        return $this->database->fetchAll(
            'SELECT f.path, f.content_id AS contentId, f.size_bytes AS size, f.checksum
                FROM library_revision_releases vr JOIN releases r ON r.id = vr.release_id
                JOIN release_files f ON f.release_id = r.id
                WHERE vr.library_revision_id = ? AND r.published_at IS NOT NULL', [$revisionId]
        );
    }
}
