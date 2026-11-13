<?php

declare(strict_types=1);

namespace RuneCloud\Screensaver;

use RuneCloud\Library\Lock;
use RuneCloud\Library\Removal;
use RuneCloud\Http\HttpException;
use RuneCloud\Model\FileContents;
use RuneCloud\Model\Revisions;
use RuneCloud\MySQL\MySQLi;
use stdClass;

/**
 * Removes owned screensavers or selected versions and reconciles the current library.
 */
final class Deletion
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Shared transaction connection.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Revision and file reference storage.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Shared BLOB cleanup.
     * @var FileContents
     */
    private readonly FileContents $contents;

    /**
     * Removes current and historical library references.
     * @var Removal
     */
    private readonly Removal $removal;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives persistence dependencies using the same connection.
     * @param MySQLi $database Shared connection.
     * @param Revisions $libraries Revision storage.
     * @param FileContents $contents Binary storage.
     * @param Removal $removal Shared library removal operation.
     */
    public function __construct(MySQLi $database, Revisions $libraries, FileContents $contents, Removal $removal)
    {
        $this->removal = $removal;
        $this->database = $database;
        $this->libraries = $libraries;
        $this->contents = $contents;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Permanently removes an owned screensaver and every version.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Screensaver identifier.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When authorization or deletion fails.
     */
    public function delete(string $identifier, int $developerId): void
    {
        $this->remove($identifier, $developerId, null);
    }

    /**
     * Removes one owned version while retaining the screensaver and other versions.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Screensaver identifier.
     * @param string $version Exact version string.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When authorization or deletion fails.
     */
    public function deleteVersion(string $identifier, string $version, int $developerId): void
    {
        $this->remove($identifier, $developerId, $version);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Serializes deletion with publication and commits every change together.
     * @param string $identifier Screensaver identifier.
     * @param int $developerId Authenticated owner.
     * @param string|null $version Selected version, or null for the whole saver.
     * @return void
     * @throws \Throwable When any step fails; all changes roll back.
     */
    private function remove(string $identifier, int $developerId, ?string $version): void
    {
        (new Lock($this->database))->run(function () use ($identifier, $developerId, $version): void {
            $this->database->transaction(fn () => $this->erase($identifier, $developerId, $version));
        });
    }

    /**
     * Coordinates content removal inside the publication lock and transaction.
     * @param string $identifier Screensaver identifier.
     * @param int $developerId Authenticated owner.
     * @param string|null $version Selected version, or null for the whole screensaver.
     * @return void
     * @throws \Throwable When any deletion step fails.
     */
    private function erase(string $identifier, int $developerId, ?string $version): void
    {
        $saver = $this->lockOwnedScreensaver($identifier, $developerId);
        $versions = $this->lockVersions((int) $saver->id, $version);
        $releases = $this->lockReleases($versions);
        $versionId = $version === null ? null : (int) $versions[0]->id;
            
        $contents = $this->removeLibraryReferences($identifier, $versionId, $releases);
        $contents = array_merge($contents, $this->removeReleases($releases));
        $this->removeVersionRecords($versions);
        
        if ($version === null) {
            $this->removeScreensaver((int) $saver->id);
        }
        
        $this->contents->deleteUnused($contents);
    }

    /**
     * Removes active and historical library references before deleting releases.
     * @param string $identifier Screensaver identifier.
     * @param int|null $versionId Selected version ID, or null for the whole screensaver.
     * @param list<int> $releases Selected release IDs.
     * @return list<int> Content IDs eligible for later cleanup.
     * @throws \Throwable When library removal fails.
     */
    private function removeLibraryReferences(string $identifier, ?int $versionId, array $releases): array
    {
        $prefix = 'screensaver/' . $identifier . '/';
        $this->removal->remove($prefix, $versionId === null ? null : $releases);
        $revisions = $versionId === null ? null : $this->libraries->allForScreensaverVersion($versionId);

        return $this->removal->removeHistory($prefix, $revisions);
    }

    /**
     * Removes the parent record after its versions and releases.
     * @param int $saverId Screensaver database ID.
     * @return void
     * @throws \mysqli_sql_exception When deletion fails.
     */
    private function removeScreensaver(int $saverId): void
    {
        $this->database->execute('DELETE FROM screensavers WHERE id=?', [$saverId]);
    }

    /**
     * Locks a screensaver and verifies token ownership.
     * @param string $identifier Exact identifier.
     * @param int $developerId Authenticated owner.
     * @return stdClass Locked record.
     * @throws HttpException When missing or owned by another developer.
     */
    private function lockOwnedScreensaver(string $identifier, int $developerId): stdClass
    {
        $saver = $this->database->fetchOne('SELECT id,developer_id FROM screensavers WHERE BINARY identifier=BINARY ? FOR UPDATE', [$identifier]);
        if ($saver === null) {
            throw new HttpException('not_found', 404);
        }
        
        if ((int) $saver->developer_id !== $developerId) {
            throw new HttpException('forbidden', 403);
        }
        
        return $saver;
    }

    /**
     * Locks all versions or one exact version and checks that a requested version exists.
     * @param int $saverId Screensaver ID.
     * @param string|null $version Selected version.
     * @return list<stdClass> Locked versions.
     * @throws HttpException When the selected version is missing.
     */
    private function lockVersions(int $saverId, ?string $version): array
    {
        $rows = $this->database->fetchAll('SELECT id FROM screensaver_versions WHERE screensaver_id=?'
            . ($version !== null ? ' AND BINARY version=BINARY ?' : '') . ' FOR UPDATE',
            $version !== null ? [$saverId, $version] : [$saverId]);
        if ($version !== null && $rows === []) {
            throw new HttpException('not_found', 404);
        }
        
        return $rows;
    }

    /**
     * Locks and collects release IDs for the selected versions.
     * @param list<stdClass> $versions Locked version records.
     * @return list<int> Release IDs.
     * @throws \Throwable When lookup fails.
     */
    private function lockReleases(array $versions): array
    {
        $ids = [];
        foreach ($versions as $version) {
            foreach ($this->database->fetchAll('SELECT id FROM releases WHERE screensaver_version_id=? FOR UPDATE', [(int) $version->id]) as $row) {
                $ids[] = (int) $row->id;
            }
        }
        
        return $ids;
    }

    /**
     * Removes release membership and files while collecting referenced BLOBs.
     * @param list<int> $releases Release IDs.
     * @return list<int> Candidate content IDs.
     * @throws \Throwable When deletion fails.
     */
    private function removeReleases(array $releases): array
    {
        if ($releases === []) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($releases), '?'));
        $files = $this->database->fetchAll(
            "SELECT content_id FROM release_files WHERE release_id IN ($placeholders) AND content_id IS NOT NULL FOR UPDATE",
            $releases
        );
        
        $this->libraries->removeReleases($releases);
        $this->database->execute("DELETE FROM release_files WHERE release_id IN ($placeholders)", $releases);
        $this->database->execute("DELETE FROM releases WHERE id IN ($placeholders)", $releases);

        return array_map(fn ($file): int => (int) $file->content_id, $files);
    }


    /**
     * Removes version records after their releases have been deleted.
     * @param list<stdClass> $versions Selected versions.
     * @return void
     * @throws \Throwable When deletion fails.
     */
    private function removeVersionRecords(array $versions): void
    {
        $ids = array_map(fn ($version): int => (int) $version->id, $versions);
        if ($ids === []) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->database->execute("DELETE FROM screensaver_versions WHERE id IN ($placeholders)", $ids);
    }
}
