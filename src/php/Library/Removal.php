<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Model\Revisions;
use RuneCloud\Model\FileContents;
use RuntimeException;
use stdClass;

/**
 * Removes content references while retaining other immutable library files.
 */
final class Removal
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores revision membership and manifests.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Stores new manifest bytes.
     * @var FileContents
     */
    private readonly FileContents $contents;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives storage sharing the caller's transaction connection.
     * @param Revisions $libraries Revision storage.
     * @param FileContents $contents Binary storage.
     */
    public function __construct(Revisions $libraries, FileContents $contents)
    {
        $this->libraries = $libraries;
        $this->contents = $contents;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Publishes a revision without the folder when selected content is active.
     * The caller holds the publication lock and transaction throughout deletion.
     * @param string $prefix Exact content folder including its trailing slash.
     * @param list<int>|null $selectedReleases Selected version releases, or null for the whole item.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see \RuneCloud\Game\Deletion
     * @see \RuneCloud\Screensaver\Deletion
     * @throws \LogicException When the shared connection has no active transaction.
     * @return void
     * @throws \Throwable When stored data is inconsistent or a write fails.
     */
    public function remove(string $prefix, ?array $selectedReleases = null): void
    {
        $this->libraries->requireTransaction();
        
        $current = $this->libraries->findCurrent();
        if ($current === null) {
            return;
        }
        
        $releases = $this->libraries->getReleases((int) $current->id);
        if (!$this->isSelected($releases, $selectedReleases)) {
            return;
        }
        
        $manifest = $this->libraries->getCurrentManifest();
        $remaining = array_values(array_filter($manifest['files'],
            fn (array $file): bool => !str_starts_with($file['path'], $prefix)));
        if (count($remaining) === count($manifest['files'])) {
            return;
        }
        
        $files = $this->libraries->getFiles((int) $current->id);
        $this->requireMatchingFiles($manifest['files'], $files);
        $this->store($remaining, $files, $releases, $prefix);
    }

    /**
     * Removes historical references only for the selected versions' revisions.
     * The caller holds the publication lock and transaction and cleans returned
     * BLOB IDs after release and version references have also been removed.
     * @param string $prefix Content folder prefix.
     * @param list<stdClass>|null $revisions Restriction for one version, or null for all history.
     * @internal Storage step; use the corresponding complete application workflow.
     * @see \RuneCloud\Game\Deletion
     * @see \RuneCloud\Screensaver\Deletion
     * @throws \LogicException When the shared connection has no active transaction.
     * @return list<int> Candidate BLOB IDs.
     * @throws \Throwable When reference cleanup fails.
     */
    public function removeHistory(string $prefix, ?array $revisions): array
    {
        $this->libraries->requireTransaction();
        
        $allowed = $revisions === null ? null : array_map(fn ($row): int => (int) $row->id, $revisions);
        $contents = [];
        
        foreach ($this->libraries->lockFiles($prefix) as $file) {
            if ($allowed !== null && !in_array((int) $file->library_revision_id, $allowed, true)) {
                continue;
            }
            $contents[] = (int) $file->content_id;
            $this->libraries->removeFile((int) $file->library_revision_id, $file->path);
        }
        
        return $contents;
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks whether the active revision includes a selected version.
     * @param list<\stdClass> $releases Active release links.
     * @param list<int>|null $selected Selected release IDs; null selects the whole item.
     * @return bool
     */
    private function isSelected(array $releases, ?array $selected): bool
    {
        return $selected === null || array_intersect($selected,
            array_map(fn ($release): int => (int) $release->id, $releases)) !== [];
    }

    /**
     * Rejects inconsistent manifest entries before changing the active library.
     * @param list<array<string, mixed>> $manifest Stored manifest files.
     * @param list<\stdClass> $files Stored file references.
     * @return void
     * @throws RuntimeException When the two representations differ.
     */
    private function requireMatchingFiles(array $manifest, array $files): void
    {
        $expected = array_map(fn ($file): array => [
            'path' => $file->path, 'size' => (int) $file->size_bytes, 'sha256' => $file->checksum,
        ], $files);
        
        if ($expected !== $manifest) {
            throw new RuntimeException('Current manifest and files differ; library removal rolled back.');
        }
    }

    /**
     * Stores an immutable revision retaining all other files and releases.
     * @param list<array<string, mixed>> $remaining Remaining manifest entries.
     * @param list<stdClass> $files Current file references.
     * @param list<stdClass> $releases Current release links.
     * @param string $prefix Removed folder.
     * @return void
     * @throws \Throwable When storage fails.
     */
    private function store(array $remaining, array $files, array $releases, string $prefix): void
    {
        $revision = 'r-' . bin2hex(random_bytes(12));
        $manifest = ['schemaVersion' => 1, 'revision' => $revision, 'files' => $remaining];
        $content = $this->contents->store(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $id = $this->libraries->create($revision);
        $this->copyFiles($id, $files, $prefix);
        $this->copyReleases($id, $releases, $prefix);
        $this->libraries->publish($id, $content);
    }

    /**
     * Copies references outside the removed folder into the new revision.
     * @param int $id New revision ID.
     * @param list<\stdClass> $files Existing references.
     * @param string $prefix Removed folder prefix.
     * @return void
     * @throws \Throwable When references cannot be stored.
     */
    private function copyFiles(int $id, array $files, string $prefix): void
    {
        foreach ($files as $file) {
            if (!str_starts_with($file->path, $prefix)) {
                $this->libraries->addFile($id, $file->path, (int) $file->content_id, $file->checksum, (int) $file->size_bytes);
            }
        }
    }

    /**
     * Copies references outside the removed folder into the new revision.
     * @param int $id New revision ID.
     * @param list<\stdClass> $releases Existing references.
     * @param string $prefix Removed folder prefix.
     * @return void
     * @throws \Throwable When references cannot be stored.
     */
    private function copyReleases(int $id, array $releases, string $prefix): void
    {
        foreach ($releases as $release) {
            if (($release->folder ?? '') !== $prefix) {
                $this->libraries->addRelease($id, (int) $release->id);
            }
        }
    }
}
