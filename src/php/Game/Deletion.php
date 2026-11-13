<?php

declare(strict_types=1);

namespace RuneCloud\Game;

use RuneCloud\Library\Lock;
use RuneCloud\Library\Removal;
use RuneCloud\Model\FileContents;
use RuneCloud\Model\Revisions;
use RuneCloud\MySQL\MySQLi;
use stdClass;
use RuneCloud\Http\HttpException;

/**
 * Removes owned games and versions while reconciling library revisions.
 */
final class Deletion
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads game records from the database.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Stores and cleans up shared file contents.
     * @var FileContents
     */
    private readonly FileContents $contents;

    /**
     * Stores revision records and their frozen references.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Removes current and historical library references.
     * @var Removal
     */
    private readonly Removal $removal;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection from the caller.
     *
     * @param MySQLi $database Shared database connection.
     * @param FileContents $contents Shared file content model.
     * @param Revisions $libraries Shared revision storage.
     * @param Removal $removal Shared library removal operation.
     */
    public function __construct(MySQLi $database, FileContents $contents, Revisions $libraries, Removal $removal)
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
     * Permanently removes an owned game and every version.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Exact game identifier.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When validation, authorization or deletion fails.
     */
    public function delete(string $identifier, int $developerId): void
    {
        if (!$this->remove($identifier, $developerId, null)) {
            throw new HttpException('not_found', 404);
        }
    }

    /**
     * Removes one owned version while retaining the game and other versions.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Exact game identifier.
     * @param string $version Exact version string.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When validation, authorization or deletion fails.
     */
    public function deleteVersion(string $identifier, string $version, int $developerId): void
    {
        if (!$this->remove($identifier, $developerId, $version)) {
            throw new HttpException('not_found', 404);
        }
    }


    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Runs the selected deletion inside a transaction and publication lock.
     * @param string $identifier Exact game identifier.
     * @param int $developerId Authenticated owner.
     * @param string|null $onlyVersion Selected version, or null for the whole game.
     * @return bool Whether the selected content existed and was deleted.
     * @throws \Throwable When validation, locking or deletion fails.
     */
    private function remove(string $identifier, int $developerId, ?string $onlyVersion): bool
    {
        $this->validateIdentifier($identifier);

        return (new Lock($this->database))->run(fn (): bool => $this->database->transaction(
            fn (): bool => $this->deleteSelectedContent($identifier, $developerId, $onlyVersion)
        ));
    }

    /**
     * Validates the public game identifier.
     * @param string $identifier Exact game identifier.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function validateIdentifier(string $identifier): void
    {
        if (strlen($identifier) > 100 || preg_match('/^[a-z][a-z0-9-]*$/D', $identifier) !== 1) {
            throw new HttpException('invalid_game_id', 422);
        }
    }

    /**
     * Coordinates removal inside the caller's transaction and publication lock.
     * @param string $identifier Exact game identifier.
     * @param int $developerId Authenticated owner.
     * @param string|null $onlyVersion Selected version, or null for the whole game.
     * @return bool
     * @throws \Throwable When validation or database operations fail.
     */
    private function deleteSelectedContent(string $identifier, int $developerId, ?string $onlyVersion): bool
    {
        $game = $this->lockOwnedGame($identifier, $developerId);
        if ($game === null) {
            return false;
        }

        $gameId = (int) $game->id;
        $versions = $this->lockVersions($gameId, $onlyVersion);
        if ($onlyVersion !== null && $versions === []) {
            return false;
        }

        $releases = $this->lockReleases($versions);
        $versionId = $onlyVersion !== null ? (int) $versions[0]->id : null;
        $contentIds = $this->removeLibraryReferences($identifier, $versionId, $releases);
        $contentIds = array_merge($contentIds, $this->removeReleases($releases));
        $this->removeVersions($versions);
        
        if ($onlyVersion === null) {
            $this->removeGame($gameId);
        }
        $this->contents->deleteUnused($contentIds);

        return true;
    }

    /**
     * Locks the game and checks permission when it exists.
     * @param string $identifier Exact game identifier.
     * @param int $developerId Authenticated owner.
     * @return ?stdClass
     * @throws \Throwable When validation or database operations fail.
     */
    private function lockOwnedGame(string $identifier, int $developerId): ?stdClass
    {
        $game = $this->database->fetchOne(
            'SELECT id, developer_id FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$identifier]
        );
        
        if ($game === null) {
            return null;
        }
        
        $this->requireOwner($game, $developerId);

        return $game;
    }

    /**
     * Checks the authenticated game owner.
     * @param stdClass $game Locked game.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function requireOwner(stdClass $game, int $developerId): void
    {
        if ((int) $game->developer_id !== $developerId) {
            throw new HttpException('forbidden', 403);
        }
    }

    /**
     * Locks all game versions or the selected version.
     * @param int $gameId Game database ID.
     * @param string|null $onlyVersion Exact version, or null for all versions.
     * @return list<stdClass>
     * @throws \Throwable When validation or database operations fail.
     */
    private function lockVersions(int $gameId, ?string $onlyVersion): array
    {
        return $this->database->fetchAll(
            'SELECT id, published_at FROM game_versions WHERE game_id = ?'
                . ($onlyVersion !== null ? ' AND BINARY version = BINARY ?' : '') . ' FOR UPDATE',
            $onlyVersion !== null ? [$gameId, $onlyVersion] : [$gameId]
        );
    }

    /**
     * Removes current and historical references for permanent deletion.
     * @param string $identifier Game folder identifier.
     * @param int|null $versionId Selected version, or null for the whole game.
     * @param list<int> $releases Selected release IDs.
     * @return list<int> Content IDs eligible for cleanup.
     * @throws \Throwable When validation or database operations fail.
     */
    private function removeLibraryReferences(string $identifier, ?int $versionId, array $releases): array
    {
        $prefix = 'games/' . $identifier . '/';
        $this->removal->remove($prefix, $versionId === null ? null : $releases);
        $revisions = $versionId === null ? null : $this->libraries->allForGameVersion($versionId);
        return $this->removal->removeHistory($prefix, $revisions);
    }

    /**
     * Removes the selected version records.
     * @param list<stdClass> $versions Locked game versions.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function removeVersions(array $versions): void
    {
        $ids = array_map(fn ($version): int => (int) $version->id, $versions);
        if ($ids === []) {
            return;
        }
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->database->execute("DELETE FROM game_versions WHERE id IN ($placeholders)", $ids);
    }

    /**
     * Removes the game after its versions and releases.
     * @param int $gameId Game database ID.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function removeGame(int $gameId): void
    {
        $this->database->execute('DELETE FROM games WHERE id = ?', [$gameId]);
    }

    /**
     * Locks the releases selected for permanent deletion.
     * @param list<stdClass> $versions Locked versions.
     * @return list<int>
     * @throws \Throwable When validation or database operations fail.
     */
    private function lockReleases(array $versions): array
    {
        $releases = [];
        foreach ($versions as $version) {
            $rows = $this->database->fetchAll(
                'SELECT id FROM releases WHERE game_version_id = ? FOR UPDATE', [(int) $version->id]
            );
            foreach ($rows as $release) {
                $this->libraries->lockReleaseLinks((int) $release->id);
                $releases[] = (int) $release->id;
            }
        }

        return $releases;
    }

    /**
     * Removes release references and collects their content IDs for cleanup.
     * @param list<int> $releases Release IDs.
     * @return list<int>
     * @throws \Throwable When validation or database operations fail.
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
}
