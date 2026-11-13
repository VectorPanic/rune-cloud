<?php

declare(strict_types=1);

namespace RuneCloud\Developer;

use RuneCloud\Library\Lock;
use RuneCloud\Http\HttpException;
use RuneCloud\Game\Deletion as GameDeletion;
use RuneCloud\MySQL\MySQLi;
use RuneCloud\Model\FileContents;
use RuneCloud\Model\Revisions;
use RuneCloud\Screensaver\Deletion as ScreensaverDeletion;

/**
 * Erases an authenticated developer and its content as one atomic operation.
 */
final class Deletion
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores account, content and frozen revision records.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Removes owned games while maintaining the current library.
     * @var GameDeletion
     */
    private readonly GameDeletion $gameDeletion;

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
     * Removes owned screensavers using the same API deletion process.
     * @var ScreensaverDeletion
     */
    private readonly ScreensaverDeletion $screensaverDeletion;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives persistence and game deletion dependencies.
     * @param MySQLi $database Shared connection.
     * @param GameDeletion $gameDeletion Game deletion using the same connection.
     * @param FileContents $contents Shared file content model.
     * @param Revisions $libraries Shared revision storage.
     * @param ScreensaverDeletion $screensaverDeletion Owned screensaver deletion.
     */
    public function __construct(MySQLi $database, GameDeletion $gameDeletion, FileContents $contents, Revisions $libraries, ScreensaverDeletion $screensaverDeletion)
    {
        $this->database = $database;
        $this->libraries = $libraries;
        $this->screensaverDeletion = $screensaverDeletion;
        $this->contents = $contents;
        $this->gameDeletion = $gameDeletion;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Deletes the account, tokens, owned content and identifying frozen history.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param int $developerId Authenticated developer ID, never supplied by a request body.
     * @return void
     * @throws \Throwable When deletion fails; all changes are rolled back.
     */
    public function delete(int $developerId): void
    {
        (new Lock($this->database))->run(function () use ($developerId): void {
            $this->database->transaction(fn () => $this->erase($developerId));
        });
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Coordinates account erasure inside the publication lock and transaction.
     * @param int $developerId Authenticated developer ID.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function erase(int $developerId): void
    {
        $this->requireAccount($developerId);
        
        $games = $this->findGames($developerId);
        $gameHistory = $this->collectGameHistory($games);
        $this->deleteGames($games, $developerId);
        
        $screensavers = $this->findScreensavers($developerId);
        $screensaverHistory = $this->collectScreensaverHistory($screensavers);
        $this->deleteScreensavers($screensavers, $developerId);
        
        $prefixes = $this->contentPrefixes($games, $screensavers);
        $this->eraseHistory($prefixes, array_replace($gameHistory, $screensaverHistory));
        $this->deleteTokens($developerId);
        $this->deleteAccount($developerId);
    }

    /**
     * Locks the account or rejects credentials for an account that no longer exists.
     * @param int $developerId Authenticated developer ID.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function requireAccount(int $developerId): void
    {
        if ($this->database->fetchOne('SELECT id FROM developers WHERE id = ? FOR UPDATE', [$developerId]) === null) {
            throw new HttpException('unauthorized', 401);
        }
    }

    /**
     * Locks the owned games in a stable order.
     * @param int $developerId Authenticated developer ID.
     * @return list<\stdClass>
     * @throws \Throwable When lookup or deletion fails.
     */
    private function findGames(int $developerId): array
    {
        return $this->database->fetchAll(
            'SELECT id, identifier FROM games WHERE developer_id = ? ORDER BY id FOR UPDATE', [$developerId]
        );
    }

    /**
     * Collects linked revision IDs before removing owned games.
     * @param list<\stdClass> $games Locked owned records.
     * @return array<int, bool>
     * @throws \Throwable When lookup or deletion fails.
     */
    private function collectGameHistory(array $games): array
    {
        $affected = [];
        foreach ($games as $item) {
            foreach ($this->libraries->allForGame((int) $item->id) as $revision) {
                $affected[(int) $revision->id] = true;
            }
        }
        
        return $affected;
    }

    /**
     * Deletes owned games through their existing deletion workflow.
     * @param list<\stdClass> $games Locked owned records.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function deleteGames(array $games, int $developerId): void
    {
        foreach ($games as $item) {
            $this->gameDeletion->delete($item->identifier, $developerId);
        }
    }

    /**
     * Locks the owned screensavers in a stable order.
     * @param int $developerId Authenticated developer ID.
     * @return list<\stdClass>
     * @throws \Throwable When lookup or deletion fails.
     */
    private function findScreensavers(int $developerId): array
    {
        return $this->database->fetchAll(
            'SELECT id, identifier FROM screensavers WHERE developer_id = ? ORDER BY id FOR UPDATE', [$developerId]
        );
    }

    /**
     * Collects linked revision IDs before removing owned screensavers.
     * @param list<\stdClass> $screensavers Locked owned records.
     * @return array<int, bool>
     * @throws \Throwable When lookup or deletion fails.
     */
    private function collectScreensaverHistory(array $screensavers): array
    {
        $affected = [];
        foreach ($screensavers as $item) {
            foreach ($this->libraries->allForScreensaver((int) $item->id) as $revision) {
                $affected[(int) $revision->id] = true;
            }
        }
        
        return $affected;
    }

    /**
     * Deletes owned screensavers through their existing deletion workflow.
     * @param list<\stdClass> $screensavers Locked owned records.
     * @param int $developerId Authenticated owner.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function deleteScreensavers(array $screensavers, int $developerId): void
    {
        foreach ($screensavers as $item) {
            $this->screensaverDeletion->delete($item->identifier, $developerId);
        }
    }

    /**
     * Identifies owned folders whose frozen history must be erased.
     * @param list<\stdClass> $games Owned games.
     * @param list<\stdClass> $screensavers Owned screensavers.
     * @return list<string>
     * @throws \Throwable When lookup or deletion fails.
     */
    private function contentPrefixes(array $games, array $screensavers): array
    {
        return array_merge(
            array_map(fn ($game): string => 'games/' . $game->identifier . '/', $games),
            array_map(fn ($saver): string => 'screensaver/' . $saver->identifier . '/', $screensavers)
        );
    }

    /**
     * Removes all account tokens.
     * @param int $developerId Authenticated developer ID.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function deleteTokens(int $developerId): void
    {
        $this->database->execute('DELETE FROM api_tokens WHERE developer_id = ?', [$developerId]);
    }

    /**
     * Removes the account after its content and tokens.
     * @param int $developerId Authenticated developer ID.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    private function deleteAccount(int $developerId): void
    {
        $this->database->execute('DELETE FROM developers WHERE id = ?', [$developerId]);
    }

    /**
     * Removes contaminated manifests and their remaining snapshot references.
     * @param list<string> $prefixes Owned game and screensaver folders.
     * @param array<int, bool> $affected Revisions linked to owned releases before removal.
     * @return void
     * @throws \Throwable When historical content cannot be read or removed.
     */
    private function eraseHistory(array $prefixes, array $affected): void
    {
        if ($prefixes === []) {
            return;
        }
        $revisions = $this->libraries->all();
        $contents = [];
        
        foreach ($revisions as $revision) {
            if ($revision->content !== null) {
                $manifest = json_decode($revision->content, true, 512, JSON_THROW_ON_ERROR);
                foreach ($manifest['files'] ?? [] as $file) {
                    foreach ($prefixes as $prefix) {
                        if (str_starts_with($file['path'], $prefix)) {
                            $affected[(int) $revision->id] = true;
                        }
                    }
                }
            }
            
            if (!isset($affected[(int) $revision->id])) {
                continue;
            }
            
            if ($revision->manifest_content_id !== null) {
                $contents[] = (int) $revision->manifest_content_id;
            }
            
            foreach ($this->libraries->getFiles((int) $revision->id) as $file) {
                $contents[] = (int) $file->content_id;
            }
            
            $this->libraries->delete((int) $revision->id);
        }
        $this->contents->deleteUnused($contents);
    }
}
