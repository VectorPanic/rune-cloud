<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Model\Revisions;
use RuneCloud\Model\Tags;
use RuneCloud\Model\Categories;
use RuneCloud\Model\Games;
use RuneCloud\Model\Screensavers;
use RuneCloud\Model\FileContents;
use RuntimeException;

/**
 * Builds immutable library snapshots inside the caller's transaction and publication lock.
 */
final class Snapshot
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores the immutable revision and its references.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Reads the tag catalog.
     * @var Tags
     */
    private readonly Tags $tags;

    /**
     * Reads the category catalog.
     * @var Categories
     */
    private readonly Categories $categories;

    /**
     * Reads game metadata.
     * @var Games
     */
    private readonly Games $games;

    /**
     * Reads screensaver metadata.
     * @var Screensavers
     */
    private readonly Screensavers $screensavers;

    /**
     * Verifies source file bytes.
     * @var Files
     */
    private readonly Files $files;

    /**
     * Stores frozen JSON contents.
     * @var FileContents
     */
    private readonly FileContents $contents;

    /**
     * Checks consistency without database or snapshot storage dependencies.
     * @var Rules
     */
    private readonly Rules $rules;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives explicitly composed dependencies sharing the publication connection.
     * @param Revisions $libraries Stores the immutable revision and its references.
     * @param Tags $tags Reads the tag catalog.
     * @param Categories $categories Reads the category catalog.
     * @param Games $games Reads game metadata.
     * @param Screensavers $screensavers Reads screensaver metadata.
     * @param Files $files Verifies source file bytes.
     * @param FileContents $contents Stores frozen JSON contents.
     * @param Rules $rules Catalog and game consistency rules.
     */
    public function __construct(
        Revisions $libraries,
        Tags $tags,
        Categories $categories,
        Games $games,
        Screensavers $screensavers,
        Files $files,
        FileContents $contents,
        Rules $rules
    ) {
        $this->libraries = $libraries;
        $this->tags = $tags;
        $this->categories = $categories;
        $this->games = $games;
        $this->screensavers = $screensavers;
        $this->files = $files;
        $this->contents = $contents;
        $this->rules = $rules;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Builds a complete snapshot with fresh metadata for all selected content.
     * Runs inside the caller's transaction and publication lock.
     * @param string $revision New revision key.
     * @param string $source Prepared source key.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see Publisher
     * @throws \LogicException When the shared connection has no active transaction.
     * @return array<string, mixed> Published manifest.
     * @throws \Throwable When validation or storage fails.
     */
    public function create(string $revision, string $source): array
    {
        $this->libraries->requireTransaction();
        return $this->build($revision, $source, fn (string $path): bool => true, $this->copyFrozenFiles(...));
    }

    /**
     * Refreshes the selected game's metadata while preserving other frozen metadata.
     * Runs inside the caller's transaction and publication lock.
     * @param string $revision New revision key.
     * @param string $source Source prepared with the selected game release.
     * @param string $identifier Game identifier.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see Publisher
     * @throws \LogicException When the shared connection has no active transaction.
     * @return array<string, mixed> Published manifest.
     * @throws \Throwable When validation or storage fails.
     */
    public function replaceGame(string $revision, string $source, string $identifier): array
    {
        $this->libraries->requireTransaction();
        
        $metadataPath = 'games/' . $identifier . '/' . $identifier . '.json';
        
        return $this->build($revision, $source, fn (string $path): bool => $path === $metadataPath, $this->copyFrozenFiles(...));
    }

    /**
     * Refreshes the selected screensaver while preserving other frozen metadata.
     * Runs inside the caller's transaction and publication lock.
     * @param string $revision New revision key.
     * @param string $source Source prepared with the selected screensaver release.
     * @param string $identifier Screensaver identifier.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see Publisher
     * @throws \LogicException When the shared connection has no active transaction.
     * @return array<string, mixed> Published manifest.
     * @throws \Throwable When validation or storage fails.
     */
    public function replaceScreensaver(string $revision, string $source, string $identifier): array
    {
        $this->libraries->requireTransaction();
        
        $metadataPath = 'screensaver/' . $identifier . '/' . $identifier . '.json';
        
        return $this->build($revision, $source, fn (string $path): bool => $path === $metadataPath, $this->copyFrozenFiles(...));
    }

    /**
     * Imports published release files when no frozen source files have been prepared.
     * @param string $revision New revision key.
     * @param string $source Revision selecting published releases.
     * @return array<string, mixed> Published manifest.
     * @internal Requires the caller's publication lock and transaction.
     * @throws \Throwable When validation or storage fails.
     */
    public function createFromReleases(string $revision, string $source): array
    {
        $this->libraries->requireTransaction();
        
        return $this->build($revision, $source, fn (string $path): bool => true, $this->copyReleaseFiles(...));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Builds and stores a snapshot using the selected metadata refresh rule.
     * @param string $revision New revision key.
     * @param string $source Prepared source key.
     * @param callable(string): bool $refreshMetadata Whether a metadata path is refreshed from live data.
     * @param callable(string, int): array $copyFiles Explicit frozen-file or release-file reader.
     * @return array<string, mixed> Published manifest.
     * @throws \Throwable When validation or storage fails.
     */
    private function build(string $revision, string $source, callable $refreshMetadata, callable $copyFiles): array
    {
        $this->requireAvailableRevision($revision);
        $sourceId = $this->requirePublishedSource($source);
        $catalog = $this->loadCatalog();
        $entries = $copyFiles($source, $sourceId);
        $versions = $this->loadVersions($sourceId);
        $entries = $this->prepareFiles($entries, $versions, $catalog, $refreshMetadata);
        return $this->store($revision, $entries, $versions);
    }

    /**
     * Rejects an attempt to replace an existing revision.
     * @param string $revision New revision key.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function requireAvailableRevision(string $revision): void
    {
        if ($this->libraries->find($revision) !== null) {
            throw new RuntimeException('Revision already exists; published revisions cannot be replaced.');
        }
    }

    /**
     * Returns the ID of a published source revision or rejects a missing source.
     * @param string $source Source revision key.
     * @return int
     * @throws \Throwable When validation or database operations fail.
     */
    private function requirePublishedSource(string $source): int
    {
        $sourceRevision = $this->libraries->findPublished($source);
        
        if ($sourceRevision === null) {
            throw new RuntimeException('Published source revision does not exist.');
        }
        
        return (int) $sourceRevision->id;
    }

    /**
     * Loads tags and categories and checks that their references agree.
     * @return array{tags: array, categories: array}
     * @throws \Throwable When validation or database operations fail.
     */
    private function loadCatalog(): array
    {
        $tags = $this->tags->all();
        $categories = $this->categories->all();
        $this->rules->catalog($tags, $categories);
        return ['tags' => $tags, 'categories' => $categories];
    }

    /**
     * Loads the published game and screensaver releases selected by the source.
     * @param int $sourceId Source revision ID.
     * @return array{games: list<\stdClass>, screensavers: list<\stdClass>}
     * @throws \Throwable When validation or database operations fail.
     */
    private function loadVersions(int $sourceId): array
    {
        $gameVersions = $this->libraries->getGameVersions($sourceId);
        
        $screensaverVersions = $this->libraries->getScreensaverVersions($sourceId);
        
        if ($gameVersions === [] && $screensaverVersions === [] && $this->libraries->getFiles($sourceId) === []) {
            throw new RuntimeException('The source revision contains no published content releases.');
        }
        
        return ['games' => $gameVersions, 'screensavers' => $screensaverVersions];
    }

    /**
     * Prepares game, screensaver and shared catalog files for the snapshot.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Verified source files.
     * @param array{games: list<\stdClass>, screensavers: list<\stdClass>} $versions Selected releases.
     * @param array{tags: array, categories: array} $catalog Validated catalog.
     * @param callable(string): bool $refreshMetadata Selects metadata paths to refresh.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When validation or database operations fail.
     */
    private function prepareFiles(array $entries, array $versions, array $catalog, callable $refreshMetadata): array
    {
        $entries = $this->prepareGameFiles($entries, $versions['games'], $catalog, $refreshMetadata);
        $entries = $this->prepareScreensaverFiles($entries, $versions['screensavers'], $refreshMetadata);
        $entries['tags.json'] = $this->storeJson($catalog['tags']);
        $entries['categories.json'] = $this->storeJson($catalog['categories']);
        return $entries;
    }

    /**
     * Validates selected game executables and freezes their metadata when needed.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Snapshot files.
     * @param list<\stdClass> $versions Selected games.
     * @param array{tags: array, categories: array} $catalog Validated catalog.
     * @param callable(string): bool $refreshMetadata Selects metadata paths to refresh.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When validation or database operations fail.
     */
    private function prepareGameFiles(array $entries, array $versions, array $catalog, callable $refreshMetadata): array
    {
        $seen = [];
        foreach ($versions as $version) {
            if (isset($seen[$version->id])) {
                throw new RuntimeException('Multiple releases for the same game are not supported.');
            }
            
            $seen[$version->id] = true;
            $prefix = 'games/' . $version->id . '/' . $version->id;
            $this->requireExecutable($entries, $prefix, 'game', $version->id);
            if (isset($entries[$prefix . '.json']) && !$refreshMetadata($prefix . '.json')) {
                continue;
            }
            
            $game = $this->loadGameMetadata($version, $catalog);
            $entries[$prefix . '.json'] = $this->storeJson($game);
        }
        
        return $entries;
    }

    /**
     * Loads complete game metadata with the selected stage and validates its tag references.
     * @param \stdClass $version Selected game version.
     * @param array{tags: array, categories: array} $catalog Validated catalog.
     * @return array<string, mixed>
     * @throws \Throwable When validation or database operations fail.
     */
    private function loadGameMetadata(\stdClass $version, array $catalog): array
    {
        $game = $this->games->find($version->id);
        if ($game === null || !isset($game['players']) || $game['input'] === []) {
            throw new RuntimeException('Incomplete metadata for game: ' . $version->id);
        }
        
        $game['stage'] = $version->stage;
        $this->rules->game($catalog['tags'], $game);
        
        return $game;
    }

    /**
     * Validates screensaver executables and freezes metadata when it is not preserved.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Snapshot files.
     * @param list<\stdClass> $versions Selected screensavers.
     * @param callable(string): bool $refreshMetadata Selects metadata paths to refresh.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When validation or database operations fail.
     */
    private function prepareScreensaverFiles(array $entries, array $versions, callable $refreshMetadata): array
    {
        $seen = [];
        foreach ($versions as $version) {
            if (isset($seen[$version->id]) || preg_match('/^[a-z][a-z0-9-]*$/D', $version->id) !== 1) {
                throw new RuntimeException('Duplicate or invalid screensaver identifier: ' . $version->id);
            }
            
            $seen[$version->id] = true;
            $prefix = 'screensaver/' . $version->id . '/' . $version->id;
            $this->requireExecutable($entries, $prefix, 'screensaver', $version->id);
            $this->requireScreensaverFiles($entries, $version->id);
            if (isset($entries[$prefix . '.json']) && !$refreshMetadata($prefix . '.json')) {
                continue;
            }
            
            $screensaver = $this->loadScreensaverMetadata($version->id);
            $entries[$prefix . '.json'] = $this->storeJson($screensaver);
        }
        
        return $entries;
    }

    /**
     * Requires a nonempty executable in the selected content folder.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Snapshot files.
     * @param string $prefix Bundle path without extension.
     * @param string $kind Game or screensaver.
     * @param string $identifier Content identifier.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function requireExecutable(array $entries, string $prefix, string $kind, string $identifier): void
    {
        if (!isset($entries[$prefix . '.js']) || $entries[$prefix . '.js']['size'] === 0) {
            throw new RuntimeException('Missing executable for ' . $kind . ': ' . $identifier);
        }
    }

    /**
     * Loads screensaver metadata and requires a nonempty name.
     * @param string $identifier Screensaver identifier.
     * @return array<string, mixed>
     * @throws \Throwable When validation or database operations fail.
     */
    private function loadScreensaverMetadata(string $identifier): array
    {
        $screensaver = $this->screensavers->find($identifier);
        if ($screensaver === null || $screensaver['name'] === '') {
            throw new RuntimeException('Incomplete metadata for screensaver: ' . $identifier);
        }
        
        return $screensaver;
    }

    /**
     * Rejects extra files in a screensaver snapshot folder.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Snapshot files.
     * @param string $identifier Screensaver identifier.
     * @return void
     * @throws RuntimeException When a screensaver contains anything beyond metadata and JavaScript.
     */
    private function requireScreensaverFiles(array $entries, string $identifier): void
    {
        $folder = 'screensaver/' . $identifier . '/';
        $allowed = [$folder . $identifier . '.js', $folder . $identifier . '.json'];
        foreach (array_keys($entries) as $path) {
            if (str_starts_with($path, $folder) && !in_array($path, $allowed, true)) {
                throw new RuntimeException('Unexpected screensaver file: ' . $path);
            }
        }
    }

    /**
     * Stores the ordered files, release links and published manifest in the current transaction.
     * @param string $revision New revision key.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Prepared snapshot files.
     * @param array{games: list<\stdClass>, screensavers: list<\stdClass>} $versions Selected releases.
     * @return array<string, mixed>
     * @throws \Throwable When validation or database operations fail.
     */
    private function store(string $revision, array $entries, array $versions): array
    {
        ksort($entries, SORT_STRING);
        $revisionId = $this->libraries->create($revision);
        $files = $this->storeFiles($revisionId, $entries);
        $manifest = ['schemaVersion' => 1, 'revision' => $revision, 'files' => $files];
        $this->storeReleases($revisionId, array_merge($versions['games'], $versions['screensavers']));
        $this->publishManifest($revisionId, $manifest);
        return $manifest;
    }

    /**
     * Stores snapshot file references and returns the matching public manifest entries.
     * @param int $revisionId New revision ID.
     * @param array<string, array{contentId: int, size: int, sha256: string}> $entries Ordered files.
     * @return list<array{path: string, size: int, sha256: string}>
     * @throws \Throwable When validation or database operations fail.
     */
    private function storeFiles(int $revisionId, array $entries): array
    {
        $files = [];
        foreach ($entries as $path => $entry) {
            $this->libraries->addFile(
                $revisionId, $path, $entry['contentId'], $entry['sha256'], $entry['size']
            );
            $files[] = ['path' => $path, 'size' => $entry['size'], 'sha256' => $entry['sha256']];
        }
        
        return $files;
    }

    /**
     * Links the selected game and screensaver releases to the new revision.
     * @param int $revisionId New revision ID.
     * @param list<\stdClass> $versions Selected releases.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function storeReleases(int $revisionId, array $versions): void
    {
        foreach ($versions as $version) {
            $this->libraries->addRelease($revisionId, (int) $version->releaseId);
        }
    }

    /**
     * Stores the frozen manifest bytes and marks the completed revision as published.
     * @param int $revisionId New revision ID.
     * @param array<string, mixed> $manifest Complete manifest.
     * @return void
     * @throws \Throwable When validation or database operations fail.
     */
    private function publishManifest(int $revisionId, array $manifest): void
    {
        $storedManifest = $this->storeJson($manifest);
        $this->libraries->publish($revisionId, $storedManifest['contentId']);
    }

    /**
     * Loads and verifies explicitly selected source files.
     * @param string $source Published source key.
     * @param int $sourceId Source database ID.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When source metadata or bytes are invalid.
     */
    private function copyFrozenFiles(string $source, int $sourceId): array
    {
        $rows = $this->libraries->getFrozenFiles($sourceId);
        
        return $this->verifySourceFiles($source, $rows, $this->files->find(...));
    }

    /**
     * Loads and verifies explicitly selected source files.
     * @param string $source Published source key.
     * @param int $sourceId Source database ID.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When source metadata or bytes are invalid.
     */
    private function copyReleaseFiles(string $source, int $sourceId): array
    {
        $rows = $this->libraries->getReleaseFiles($sourceId);
        
        return $this->verifySourceFiles($source, $rows, $this->files->findRelease(...));
    }

    /**
     * Verifies source file bytes and reuses their content references.
     * @param string $source Source revision key.
     * @param list<\stdClass> $rows Selected file metadata.
     * @param callable(string, string): ?\RuneCloud\Http\FileResponse $findFile Explicit file reader.
     * @return array<string, array{contentId: int, size: int, sha256: string}>
     * @throws \Throwable When source integrity checks fail.
     */
    private function verifySourceFiles(string $source, array $rows, callable $findFile): array
    {
        $entries = [];
        
        foreach ($rows as $row) {
            if (isset($entries[$row->path])) {
                throw new RuntimeException('Duplicate file path in source revision.');
            }
            
            $file = $findFile($source, $row->path);
            
            if ($file === null) {
                throw new RuntimeException('Invalid source file: ' . $row->path);
            }
            
            try {
                if (str_ends_with($row->path, '.png') && fread($file->stream, 8) !== "\x89PNG\r\n\x1a\n") {
                    throw new RuntimeException('Invalid PNG file: ' . $row->path);
                }
            } finally {
                fclose($file->stream);
            }
            
            $entries[$row->path] = [
                'contentId' => (int) $row->contentId,
                'size' => (int) $row->size,
                'sha256' => strtolower($row->checksum),
            ];
        }
        
        return $entries;
    }

    /**
     * Stores JSON once and derives both checksum and byte size from those exact bytes.
     *
     * @param array<string, mixed> $document JSON document.
     *
     * @return array{contentId: int, size: int, sha256: string}
     * @throws \Throwable When encoding or insertion fails.
     */
    private function storeJson(array $document): array
    {
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $id = $this->contents->store($bytes);
        
        return ['contentId' => $id, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}
