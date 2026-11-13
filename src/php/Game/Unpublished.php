<?php

declare(strict_types=1);

namespace RuneCloud\Game;

use RuneCloud\Library\Lock;
use RuneCloud\Model\FileContents;
use RuneCloud\Model\Revisions;
use RuneCloud\MySQL\MySQLi;
use stdClass;
use DomainException;
use RuneCloud\Http\HttpException;

/**
 * Removes only unpublished games without changing library revisions.
 */
final class Unpublished
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

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection from the caller.
     *
     * @param MySQLi $database Shared database connection.
     * @param FileContents $contents Shared file content model.
     * @param Revisions $libraries Shared revision storage.
     */
    public function __construct(MySQLi $database, FileContents $contents, Revisions $libraries)
    {
        $this->database = $database;
        $this->libraries = $libraries;
        $this->contents = $contents;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Removes only unpublished content without breaking revision downloads.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Exact game identifier.
     * @return bool Whether the game existed and was deleted.
     * @throws \Throwable When published references exist or deletion fails.
     */
    public function delete(string $identifier): bool
    {
        return $this->remove($identifier);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Runs the selected deletion inside a transaction and publication lock.
     * @param string $identifier Exact game identifier.
     * @return bool Whether the selected content existed and was deleted.
     * @throws \Throwable When validation, locking or deletion fails.
     */
    private function remove(string $identifier): bool
    {
        $this->validateIdentifier($identifier);
        
        return (new Lock($this->database))->run(fn (): bool => $this->database->transaction(
            fn (): bool => $this->erase($identifier)
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
     * @return bool
     * @throws \Throwable When validation or database operations fail.
     */
    private function erase(string $identifier): bool
    {
        $game = $this->lockGame($identifier);
        if ($game === null) {
            return false;
        }
        
        $gameId = (int) $game->id;
        $versions = $this->lockVersions($gameId);
        
        $releases = $this->collectReleasesForDeletion($identifier, $versions);
        $contentIds = $this->removeReleases($releases);
        $this->removeVersions($versions);
        $this->removeGame($gameId);
        $this->contents->deleteUnused($contentIds);
        
        return true;
    }

    /**
     * Locks the game when it exists.
     * @param string $identifier Exact game identifier.
     * @return ?stdClass
     * @throws \Throwable When validation or database operations fail.
     */
    private function lockGame(string $identifier): ?stdClass
    {
        $game = $this->database->fetchOne(
            'SELECT id, developer_id FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$identifier]
        );
        if ($game === null) {
            return null;
        }
        
        return $game;
    }

    /**
     * Locks all game versions or the selected version.
     * @param int $gameId Game database ID.
     * @return list<stdClass>
     * @throws \Throwable When validation or database operations fail.
     */
    private function lockVersions(int $gameId): array
    {
        return $this->database->fetchAll(
            'SELECT id, published_at FROM game_versions WHERE game_id = ? FOR UPDATE', [$gameId]
        );
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
     * Removes the unpublished game after its versions and releases.
     * @param int $gameId Game database ID.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function removeGame(int $gameId): void
    {
        $this->database->execute('DELETE FROM games WHERE id = ?', [$gameId]);
    }

    /**
     * Checks publication restrictions and locks the releases to remove.
     * @param string $identifier Game folder identifier.
     * @param list<stdClass> $versions Locked versions.
     * @return list<int>
     * @throws \Throwable When validation or database operations fail.
     */
    private function collectReleasesForDeletion(string $identifier, array $versions): array
    {
        $snapshot = $this->libraries->lockFileReference('games/' . $identifier . '/') !== null;
        if ($snapshot) {
            throw new DomainException('Game belongs to a library revision and cannot be deleted.');
        }
        $releases = [];
        foreach ($versions as $version) {
            if ($version->published_at !== null) {
                throw new DomainException('Game has published versions and cannot be deleted.');
            }
            $rows = $this->database->fetchAll(
                'SELECT id, published_at FROM releases WHERE game_version_id = ? FOR UPDATE',
                [(int) $version->id]
            );
            foreach ($rows as $release) {
                $links = $this->libraries->lockReleaseLinks((int) $release->id) !== [];
                if ($release->published_at !== null || $links) {
                    throw new DomainException('Game has published or revision-linked releases and cannot be deleted.');
                }
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
        $this->database->execute("DELETE FROM release_files WHERE release_id IN ($placeholders)", $releases);
        $this->database->execute("DELETE FROM releases WHERE id IN ($placeholders)", $releases);
        
        return array_map(fn ($file): int => (int) $file->content_id, $files);
    }
}
