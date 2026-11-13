<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Model\Games;
use RuneCloud\Model\GameVersions;
use RuneCloud\Model\ScreensaverVersions;
use RuneCloud\Model\Screensavers;
use RuneCloud\Model\Revisions;
use RuneCloud\MySQL\MySQLi;
use RuntimeException;
use RuneCloud\Http\HttpException;

/**
 * Coordinates owned release selection and atomic library publication.
 */
final class Publisher
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Coordinates the publication transaction.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Stores sources and selected revision membership.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Updates and reads game metadata.
     * @var Games
     */
    private readonly Games $games;

    /**
     * Reads screensaver metadata.
     * @var Screensavers
     */
    private readonly Screensavers $screensavers;

    /**
     * Builds and freezes the completed library revision.
     * @var Snapshot
     */
    private readonly Snapshot $snapshot;

    /**
     * Selects and locks owned game versions.
     * @var GameVersions
     */
    private readonly GameVersions $gameVersions;

    /**
     * Selects and locks owned screensaver versions.
     * @var ScreensaverVersions
     */
    private readonly ScreensaverVersions $screensaverVersions;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives explicitly composed dependencies sharing the publication connection.
     * @param MySQLi $database Coordinates the publication transaction.
     * @param Revisions $libraries Stores sources and selected revision membership.
     * @param Games $games Updates and reads game metadata.
     * @param Screensavers $screensavers Reads screensaver metadata.
     * @param Snapshot $snapshot Builds and freezes the completed library revision.
     * @param GameVersions $gameVersions Owned game version selection.
     * @param ScreensaverVersions $screensaverVersions Owned screensaver version selection.
     */
    public function __construct(
        MySQLi $database,
        Revisions $libraries,
        Games $games,
        Screensavers $screensavers,
        Snapshot $snapshot,
        GameVersions $gameVersions,
        ScreensaverVersions $screensaverVersions
    ) {
        $this->database = $database;
        $this->libraries = $libraries;
        $this->games = $games;
        $this->screensavers = $screensavers;
        $this->snapshot = $snapshot;
        $this->gameVersions = $gameVersions;
        $this->screensaverVersions = $screensaverVersions;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Imports published release files into a new atomic library snapshot.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     *
     * @param string $revision New immutable revision key.
     * @param string $source Source revision containing published game or screensaver releases.
     *
     * @return array<string, mixed> Published manifest.
     * @throws \Throwable When validation, JSON encoding or database writes fail; all writes are rolled back.
     */
    public function publishFromReleases(string $revision, string $source): array
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $revision) !== 1 || strlen($revision) > 100) {
            throw new RuntimeException('Invalid revision key.');
        }
        
        return (new Lock($this->database))->run(fn (): array => $this->database->transaction(
            fn (): array => $this->snapshot->createFromReleases($revision, $source)
        ));
    }

    /**
     * Republishes current membership with fresh catalog metadata and verified file bytes.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @return array<string, mixed> New complete manifest.
     * @throws \Throwable When the current library is unavailable or publication fails.
     */
    public function publishCurrent(): array
    {
        return (new Lock($this->database))->run(fn (): array => $this->database->transaction(function (): array {
            $current = $this->libraries->findCurrent();
            if ($current === null) {
                throw new HttpException('library_unavailable', 503);
            }
            $revision = 'r-' . bin2hex(random_bytes(12));
            return $this->snapshot->create($revision, $current->revision);
        }));
    }

    /**
     * Publishes or reactivates an owned version atomically with the remaining library.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     *
     * @param string $identifier Game identifier.
     * @param string $version Version string.
     * @param int $developerId Authenticated owner.
     * @return array<string, mixed> New complete manifest.
     * @throws \Throwable When authorization, validation or publication fails.
     */
    public function publishGame(string $identifier, string $version, int $developerId): array
    {
        return (new Lock($this->database))->run(fn (): array => $this->database->transaction(function () use (
            $identifier, $version, $developerId
        ): array {
            $selection = $this->gameVersions->select($identifier, $version, $developerId);
            $this->validateGameForPublication($identifier, $selection->releaseId);
            
            $source = $this->prepareGameSource($identifier, $selection);
            $revision = 'r-' . bin2hex(random_bytes(12));
            $manifest = $this->snapshot->replaceGame($revision, $source['revision'], $identifier);
            $this->removePublicationSource($source['id']);
            return $manifest;
        }));
    }

    /**
     * Publishes or reactivates an owned screensaver without changing other content.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param int $developerId Authenticated owner.
     * @param string $identifier Screensaver identifier.
     * @param string $version Exact version string.
     * @return array<string, mixed> New complete manifest.
     * @throws \Throwable When validation or publication fails.
     */
    public function publishScreensaver(string $identifier, string $version, int $developerId): array
    {
        return (new Lock($this->database))->run(fn (): array => $this->database->transaction(function () use (
            $identifier, $version, $developerId
        ): array {
            $selection = $this->screensaverVersions->select($identifier, $version, $developerId);
            $this->validateScreensaverForPublication($identifier, $selection->releaseId);
            $source = $this->prepareScreensaverSource($identifier, $selection);
            $manifest = $this->snapshot->replaceScreensaver('r-' . bin2hex(random_bytes(12)), $source['revision'], $identifier);
            $this->removePublicationSource($source['id']);
            return $manifest;
        }));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks that the selected screensaver draft contains only its executable.
     * @param string $identifier Screensaver identifier.
     * @param int $releaseId Selected release ID.
     * @return void
     * @throws HttpException When a required upload is missing.
     * @throws \Throwable When stored metadata or database access fails.
     */
    private function validateScreensaverForPublication(string $identifier, int $releaseId): void
    {
        $files = $this->database->fetchAll('SELECT path FROM release_files WHERE release_id=?', [$releaseId]);
        $this->requireScreensaverFiles($identifier, $files);
    }

    /**
     * Prepares a source containing the selected saver and all other current content.
     * @param string $identifier Screensaver identifier.
     * @param Selection $selection Locked version and release.
     * @return array{id: int, revision: string} Temporary source.
     * @throws \Throwable When preparation fails.
     */
    private function prepareScreensaverSource(string $identifier, Selection $selection): array
    {
        $prefix = 'screensaver/' . $identifier . '/';
        $source = $this->createSource($prefix);
        $this->addScreensaverRelease($source['id'], $identifier, $selection);
        
        return $source;
    }

    /**
     * Attaches the selected saver files and preserves original publication timestamps.
     * @param int $sourceId Temporary source ID.
     * @param string $identifier Screensaver identifier.
     * @param Selection $selection Locked selection.
     * @return void
     * @throws \Throwable When file validation or storage fails.
     */
    private function addScreensaverRelease(int $sourceId, string $identifier, Selection $selection): void
    {
        $files = $this->releaseFiles($selection->releaseId);
        $this->requireScreensaverFiles($identifier, $files);
        $this->markScreensaverPublished($selection);
        $this->libraries->addRelease($sourceId, $selection->releaseId);
        $this->copyReleaseFiles($sourceId, $files);
    }

    /**
     * Checks required game metadata and the selected executable before publication.
     * @param string $identifier Game identifier.
     * @param int $releaseId Selected release.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function validateGameForPublication(string $identifier, int $releaseId): void
    {
        $document = $this->games->find($identifier);
        
        if (!isset($document['players']) || $document['input'] === []) {
            throw new HttpException('players_and_input_required', 422);
        }
        
        if (($document['modes']['coop'] || $document['modes']['versus']) && $document['players']['max'] < 2) {
            throw new HttpException('multiplayer_requires_multiple_players', 422);
        }
        
        $prefix = 'games/' . $identifier . '/';
        $required = $prefix . $identifier . '.js';
        $uploads = $this->database->fetchAll('SELECT path FROM release_files WHERE release_id=?', [$releaseId]);
        
        if (!in_array($required, array_column($uploads, 'path'), true)) {
            throw new HttpException('game_file_required', 422);
        }
    }

    /**
     * Assembles a temporary publication source from the current library and selected release.
     * @param string $identifier Game identifier.
     * @param Selection $selection Locked selection.
     * @return array{id: int, revision: string}
     * @throws \Throwable When validation or database operations fail.
     */
    private function prepareGameSource(string $identifier, Selection $selection): array
    {
        $prefix = 'games/' . $identifier . '/';
        $source = $this->createSource($prefix);
        $this->addGameRelease($source['id'], $selection, $prefix);

        return $source;
    }

    /**
     * Creates a temporary source retaining current content outside the replaced folder.
     * @param string $prefix Content folder being replaced, including its trailing slash.
     * @return array{id: int, revision: string} Temporary source identity.
     * @throws \Throwable When source creation or copying fails.
     */
    private function createSource(string $prefix): array
    {
        $current = $this->libraries->findCurrent();
        $revision = 'source-' . bin2hex(random_bytes(12));
        $id = $this->libraries->createSource($revision);
        if ($current !== null) {
            $this->preserveOtherContent($current, $id, $prefix);
        }

        return ['id' => $id, 'revision' => $revision];
    }

    /**
     * Copies other games and screensavers into the temporary publication source.
     * @param \stdClass $current Current revision.
     * @param int $sourceId Temporary revision ID.
     * @param string $prefix Folder being replaced.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function preserveOtherContent(\stdClass $current, int $sourceId, string $prefix): void
    {
        $currentId = (int) $current->id;
        $entries = $this->libraries->getFiles($currentId);
        foreach ($entries as $entry) {
            if (!str_starts_with($entry->path, $prefix)) {
                $this->libraries->addFile(
                    $sourceId, $entry->path, (int) $entry->content_id, $entry->checksum, (int) $entry->size_bytes
                );
            }
        }
        
        $links = $this->libraries->getReleases($currentId);
        foreach ($links as $link) {
            if ($link->folder !== $prefix) {
                $this->libraries->addRelease($sourceId, (int) $link->id);
            }
        }
    }

    /**
     * Marks the selected version as published and attaches its release to the source.
     * @param int $sourceId Temporary revision ID.
     * @param Selection $selection Locked selection.
     * @param string $prefix Allowed game folder.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function addGameRelease(int $sourceId, Selection $selection, string $prefix): void
    {
        $files = $this->releaseFiles($selection->releaseId);
        $this->requireFilePaths($files, $prefix, 'invalid_game_file_path');
        $this->markGamePublished($selection);
        $this->libraries->addRelease($sourceId, $selection->releaseId);
        $this->copyReleaseFiles($sourceId, $files);
    }

    /**
     * Loads the file metadata needed to attach one release.
     * @param int $releaseId Selected release ID.
     * @return list<\stdClass> Stored file references.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function releaseFiles(int $releaseId): array
    {
        return $this->database->fetchAll(
            'SELECT path, content_id, checksum, size_bytes FROM release_files WHERE release_id=?', [$releaseId]
        );
    }

    /**
     * Requires every file to stay inside the selected content folder.
     * @param list<\stdClass> $files Release file references.
     * @param string $prefix Allowed content folder.
     * @param string $error Public error identifier for an invalid path.
     * @return void
     * @throws HttpException When a file belongs outside the folder.
     */
    private function requireFilePaths(array $files, string $prefix, string $error): void
    {
        foreach ($files as $file) {
            if (!str_starts_with($file->path, $prefix)) {
                throw new HttpException($error, 422);
            }
        }
    }

    /**
     * Requires exactly the single canonical screensaver executable.
     * @param string $identifier Screensaver identifier.
     * @param list<\stdClass> $files Release file references.
     * @return void
     * @throws HttpException When executable or file paths are invalid.
     */
    private function requireScreensaverFiles(string $identifier, array $files): void
    {
        $prefix = 'screensaver/' . $identifier . '/';
        $paths = array_column($files, 'path');
        if (!in_array($prefix . $identifier . '.js', $paths, true)) {
            throw new HttpException('screensaver_file_required', 422);
        }
        
        if (count($paths) !== 1) {
            throw new HttpException('invalid_screensaver_file_path', 422);
        }
    }

    /**
     * Records game publication while preserving original timestamps on reactivation.
     * @param Selection $selection Locked game version and release.
     * @return void
     * @throws \mysqli_sql_exception When publication dates cannot be stored.
     */
    private function markGamePublished(Selection $selection): void
    {
        $this->markReleasePublished($selection->releaseId);
        $this->database->execute(
            'UPDATE game_versions SET published_at=COALESCE(published_at,NOW()) WHERE id=?', [$selection->versionId]
        );
    }

    /**
     * Records screensaver publication while preserving original timestamps.
     * @param Selection $selection Locked screensaver version and release.
     * @return void
     * @throws \mysqli_sql_exception When publication dates cannot be stored.
     */
    private function markScreensaverPublished(Selection $selection): void
    {
        $this->markReleasePublished($selection->releaseId);
        $this->database->execute(
            'UPDATE screensaver_versions SET published_at=COALESCE(published_at,NOW()) WHERE id=?', [$selection->versionId]
        );
    }

    /**
     * Sets the release's initial publication timestamp without changing an existing one.
     * @param int $releaseId Selected release ID.
     * @return void
     * @throws \mysqli_sql_exception When the date cannot be stored.
     */
    private function markReleasePublished(int $releaseId): void
    {
        $this->database->execute('UPDATE releases SET published_at=COALESCE(published_at,NOW()) WHERE id=?', [$releaseId]);
    }

    /**
     * Copies selected release file references into the prepared source.
     * @param int $sourceId Temporary source ID.
     * @param list<\stdClass> $files Validated file references.
     * @return void
     * @throws \Throwable When references cannot be stored.
     */
    private function copyReleaseFiles(int $sourceId, array $files): void
    {
        foreach ($files as $file) {
            $this->libraries->addFile(
                $sourceId, $file->path, (int) $file->content_id, $file->checksum, (int) $file->size_bytes
            );
        }
    }

    /**
     * Removes the temporary source after its complete snapshot has been built.
     * @param int $sourceId Temporary revision ID.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function removePublicationSource(int $sourceId): void
    {
        $this->libraries->delete($sourceId);
    }

}
