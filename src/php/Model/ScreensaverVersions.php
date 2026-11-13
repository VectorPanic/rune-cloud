<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\Http\HttpException;
use RuneCloud\MySQL\MySQLi;
use RuneCloud\Library\Selection;
use RuneCloud\Upload\Validation;
use RuneCloud\Screensaver\Validation as MetadataValidation;
use stdClass;

/**
 * Stores screensaver drafts and uploads while preserving published content.
 */
final class ScreensaverVersions
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
     * Draft release file storage.
     * @var ReleaseFiles
     */
    private readonly ReleaseFiles $files;

    /**
     * Frozen release membership.
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Incoming file validation.
     * @var Validation
     */
    private readonly Validation $validator;

    /**
     * Validates version metadata and domain input.
     * @var MetadataValidation
     */
    private readonly MetadataValidation $validation;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives explicitly composed storage and validation dependencies.
     * @param MySQLi $database Shared connection.
     * @param ReleaseFiles $files Draft release file storage.
     * @param Revisions $libraries Revision storage.
     * @param Validation $validator Upload validation.
     * @param MetadataValidation $validation Version input rules.
     */
    public function __construct(MySQLi $database, ReleaseFiles $files, Revisions $libraries, Validation $validator, MetadataValidation $validation)
    {
        $this->validation = $validation;
        $this->database = $database;
        $this->files = $files;
        $this->libraries = $libraries;
        $this->validator = $validator;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Selects an owned version and its single release for publication or reactivation.
     * @param string $identifier Parent public identifier.
     * @param string $version Exact version name.
     * @param int $developerId Authenticated owner.
     * @return Selection Locked publication selection.
     * @internal Storage step inside the publication lock and transaction.
     * @see \RuneCloud\Library\Publisher
     * @throws \LogicException When no transaction is active on this connection.
     * @throws HttpException When missing, owned by another developer or ambiguous.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function select(string $identifier, string $version, int $developerId): Selection
    {
        $this->database->requireTransaction();
        $saver = $this->lockOwnedScreensaver($identifier, $developerId);
        $row = $this->lockVersion((int) $saver->id, $version);
        $release = $this->lockRelease((int) $row->id);

        return new Selection((int) $saver->id, (int) $row->id, (int) $release->id);
    }

    /**
     * Creates a draft version and its single release atomically.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Screensaver identifier.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $data Version input.
     * @return array<string, mixed> Draft document.
     * @throws \Throwable When validation or storage fails.
     */
    public function create(string $identifier, int $developerId, array $data): array
    {
        $this->validation->version($data);
        $version = $data['version'];
        return $this->database->transaction(function () use ($identifier, $developerId, $version): array {
            $saver = $this->lockOwnedScreensaver($identifier, $developerId);
            $this->requireAvailable((int) $saver->id, $version);
            $row = $this->insert((int) $saver->id, $version);
            $this->createRelease((int) $row->id);

            return $this->format($row, false, []);
        });
    }

    /**
     * Lists versions for administrative draft inspection.
     * @param string $identifier Screensaver identifier.
     * @param int|null $developerId Optional authenticated developer.
     * @return array<string, mixed> Version documents.
     * @throws \Throwable When lookup fails.
     */
    public function all(string $identifier, ?int $developerId): array
    {
        $saver = $this->requireScreensaver($identifier);
        $owner = $developerId !== null && (int) $saver->developer_id === $developerId;
        $rows = $this->loadVersions((int) $saver->id);
        $visible = array_filter($rows, fn (stdClass $row): bool => $owner || (bool) $row->published);
        $files = $this->filesFor(array_column($visible, 'id'));

        $versions = [];
        foreach ($visible as $row) {
            $versions[] = $this->format($row, (bool) $row->published, $files[(int) $row->id] ?? []);
        }

        return ['versions' => $versions];
    }

    /**
     * Returns a version while hiding drafts from everyone except the owner.
     * @param string $identifier Screensaver identifier.
     * @param string $version Exact version name.
     * @param int|null $developerId Optional authenticated developer.
     * @return array<string, mixed> Version metadata and uploaded file information.
     * @throws HttpException When the screensaver/version is missing or private.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    public function get(string $identifier, string $version, ?int $developerId): array
    {
        $saver = $this->requireScreensaver($identifier);
        $row = $this->requireVersion((int) $saver->id, $version);
        $published = $this->isPublished($row);
        if (!$published && ($developerId === null || (int) $saver->developer_id !== $developerId)) {
            throw new HttpException('not_found', 404);
        }

        return $this->format($row, $published, $this->getFiles((int) $row->id));
    }

    /**
     * Stores the single executable inside an unpublished version.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Screensaver identifier.
     * @param string $version Exact version.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $upload PHP upload record.
     * @return array<string, mixed> Updated version document.
     * @throws \Throwable When validation or storage fails.
     */
    public function upload(string $identifier, string $version, int $developerId, array $upload): array
    {
        return $this->database->transaction(function () use ($identifier, $version, $developerId, $upload): array {
            $saver = $this->lockOwnedScreensaver($identifier, $developerId);
            $row = $this->lockVersion((int) $saver->id, $version);
            $release = $this->lockDraftRelease($row);
            $this->storeUpload((int) $release->id, $identifier, $upload);
            return $this->document($row);
        });
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Locks the single release without excluding already published versions.
     * @param int $versionId Selected version ID.
     * @return stdClass Locked release.
     * @throws HttpException When the version does not have exactly one release.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function lockRelease(int $versionId): stdClass
    {
        $releases = $this->database->fetchAll(
            'SELECT id FROM releases WHERE screensaver_version_id = ? FOR UPDATE', [$versionId]
        );
        if (count($releases) !== 1) {
            throw new HttpException('ambiguous_version_release', 409);
        }

        return $releases[0];
    }

    /**
     * Loads versions and publication state together without row locks.
     * @param int $parentId Parent database ID.
     * @return list<stdClass> Versions in descending creation order.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function loadVersions(int $parentId): array
    {
        return $this->database->fetchAll(
            <<<'SQL'
            SELECT v.id, v.version, v.published_at,
                (v.published_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM releases r
                    WHERE r.screensaver_version_id = v.id
                        AND (r.published_at IS NOT NULL OR EXISTS (
                            SELECT 1 FROM library_revision_releases vr
                            JOIN library_revisions library ON library.id = vr.library_revision_id
                            WHERE vr.release_id = r.id AND library.published_at IS NOT NULL
                        ))
                )) AS published
            FROM screensaver_versions v
            WHERE v.screensaver_id = ?
            ORDER BY v.id DESC
            SQL,
            [$parentId]
        );
    }

    /**
     * Rejects a version name already used by the locked parent.
     * @param int $saverId Parent database ID.
     * @param string $version Validated version name.
     * @return void
     * @throws HttpException When the version already exists.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireAvailable(int $saverId, string $version): void
    {
        $existing = $this->database->fetchOne(
            'SELECT id FROM screensaver_versions WHERE screensaver_id = ? AND version = ?', [$saverId, $version]
        );
        if ($existing !== null) {
            throw new HttpException('version_exists', 409);
        }
    }

    /**
     * Inserts one draft version and returns its initial metadata.
     * @param int $saverId Parent database ID.
     * @param string $version Validated version name.
     * @return stdClass New draft version.
     * @throws \mysqli_sql_exception When insertion fails.
     */
    private function insert(int $saverId, string $version): stdClass
    {
        $id = $this->database->insert(
            'INSERT INTO screensaver_versions (screensaver_id, version) VALUES (?, ?)',
            [$saverId, $version]
        );

        return (object) ['id' => $id, 'version' => $version];
    }

    /**
     * Creates the single draft release belonging to the new version.
     * @param int $versionId New version database ID.
     * @return void
     * @throws \mysqli_sql_exception When insertion fails.
     */
    private function createRelease(int $versionId): void
    {
        $this->database->insert('INSERT INTO releases (screensaver_version_id) VALUES (?)', [$versionId]);
    }

    /**
     * Reads the selected screensaver without acquiring a row lock.
     * @param string $identifier Public identifier.
     * @return stdClass Required screensaver.
     * @throws HttpException When missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireScreensaver(string $identifier): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, developer_id FROM screensavers WHERE BINARY identifier=BINARY ?', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Locks the selected screensaver for changes inside the caller's transaction.
     * @param string $identifier Public identifier.
     * @return stdClass Locked screensaver.
     * @throws HttpException When missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function lockScreensaver(string $identifier): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, developer_id FROM screensavers WHERE BINARY identifier=BINARY ? FOR UPDATE', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Locks the screensaver and verifies the authenticated owner.
     * @param string $identifier Public identifier.
     * @param int $developerId Required owner.
     * @return stdClass Locked owned screensaver.
     * @throws HttpException When missing or owned by another developer.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function lockOwnedScreensaver(string $identifier, int $developerId): stdClass
    {
        $saver = $this->lockScreensaver($identifier);
        if ((int) $saver->developer_id !== $developerId) {
            throw new HttpException('forbidden', 403);
        }

        return $saver;
    }

    /**
     * Reads one exact version without acquiring a write lock.
     * @param int $saverId Screensaver ID.
     * @param string $version Exact version name.
     * @return stdClass Required version.
     * @throws HttpException When missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireVersion(int $saverId, string $version): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, version, published_at FROM screensaver_versions
                WHERE screensaver_id = ? AND BINARY version = BINARY ?',
            [$saverId, $version]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Locks the selected version or reports a missing version.
     * @param int $saverId Screensaver ID.
     * @param string $version Exact version.
     * @return stdClass Locked version.
     * @throws HttpException When missing.
     */
    private function lockVersion(int $saverId, string $version): stdClass
    {
        return $this->database->fetchOne('SELECT id,version,published_at FROM screensaver_versions WHERE screensaver_id=? AND BINARY version=BINARY ? FOR UPDATE',
            [$saverId, $version]) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Rejects published or ambiguous release states before upload.
     * @param stdClass $version Selected version.
     * @return stdClass Locked draft release.
     * @throws HttpException When immutable or ambiguous.
     */
    private function lockDraftRelease(stdClass $version): stdClass
    {
        $releases = $this->database->fetchAll('SELECT id,published_at FROM releases WHERE screensaver_version_id=? FOR UPDATE',
            [(int) $version->id]);
        if (count($releases) !== 1) {
            throw new HttpException('ambiguous_version_release', 409);
        }
        $release = $releases[0];
        $this->libraries->lockReleaseLinks((int) $release->id);
        if ($this->isPublished($version)) {
            throw new HttpException('version_published', 409);
        }
        return $release;
    }

    /**
     * Validates and stores the executable at its canonical bundle path.
     * @param int $releaseId Draft release ID.
     * @param string $identifier Screensaver identifier.
     * @param array<string, mixed> $upload PHP upload record.
     * @return void
     * @throws \Throwable When validation or storage fails.
     */
    private function storeUpload(int $releaseId, string $identifier, array $upload): void
    {
        $file = $this->validator->validate('game', $upload);
        $path = 'screensaver/' . $identifier . '/' . $identifier . '.js';
        $this->files->replace($releaseId, $path, $file);
    }

    /**
     * Loads the publication status and files for a version response.
     * @param stdClass $row Version row.
     * @return array<string, mixed>
     * @throws \Throwable When metadata lookup fails.
     */
    private function document(stdClass $row): array
    {
        $files = $this->getFiles((int) $row->id);
        $published = $this->isPublished($row);

        return $this->format($row, $published, $files);
    }

    /**
     * Loads file metadata without binary contents.
     * @param int $versionId Version ID.
     * @return list<stdClass>
     * @throws \mysqli_sql_exception When the query fails.
     */
    private function getFiles(int $versionId): array
    {
        return $this->filesFor([$versionId])[$versionId] ?? [];
    }

    /**
     * Loads file metadata for selected versions in one query and groups it by version.
     * @param list<int|string> $versionIds Selected version IDs.
     * @return array<int, list<stdClass>> File rows indexed by version ID.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function filesFor(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($versionIds), '?'));
        $rows = $this->database->fetchAll(
            "SELECT r.screensaver_version_id AS versionId, f.path, f.checksum AS sha256, f.size_bytes AS size
                FROM release_files f JOIN releases r ON r.id = f.release_id
                WHERE r.screensaver_version_id IN ($placeholders) ORDER BY f.path",
            $versionIds
        );
        $files = [];
        foreach ($rows as $file) {
            $files[(int) $file->versionId][] = $file;
        }

        return $files;
    }

    /**
     * Checks version/release timestamps and membership in a published revision.
     * @param stdClass $row Version row.
     * @return bool
     * @throws \mysqli_sql_exception When the query fails.
     */
    private function isPublished(stdClass $row): bool
    {
        if ($row->published_at !== null) {
            return true;
        }

        return $this->database->fetchOne(
            'SELECT r.id FROM releases r WHERE r.screensaver_version_id = ? AND
                (r.published_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM library_revision_releases vr
                    JOIN library_revisions library ON library.id = vr.library_revision_id
                    WHERE vr.release_id = r.id AND library.published_at IS NOT NULL)) LIMIT 1',
            [(int) $row->id]
        ) !== null;
    }

    /**
     * Formats previously loaded version metadata without database queries.
     * @param stdClass $row Version row.
     * @param bool $published Publication status.
     * @param list<stdClass> $files File metadata.
     * @return array<string, mixed>
     */
    private function format(stdClass $row, bool $published, array $files): array
    {
        return [
            'version' => $row->version,
            'published' => $published,
            'files' => array_map(fn (stdClass $file): array => [
                'path' => $file->path,
                'sha256' => $file->sha256,
                'size' => (int) $file->size,
            ], $files),
        ];
    }
}
