<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use RuneCloud\Library\Selection;
use RuneCloud\Http\HttpException;
use RuneCloud\Upload\Validation;
use RuneCloud\Game\Validation as MetadataValidation;
use stdClass;

/**
 * Manages owned draft versions and their release-file references.
 */
final class GameVersions
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores version and release metadata.
     *
     * @var MySQLi
     */
    private MySQLi $database;

    /**
     * Validates incoming HTTP uploads.
     *
     * @var Validation
     */
    private Validation $validator;

    /**
     * Stores validated draft release files.
     * @var ReleaseFiles
     */
    private readonly ReleaseFiles $files;

    /**
     * Validates version metadata and domain input.
     * @var MetadataValidation
     */
    private readonly MetadataValidation $validation;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database and upload validator.
     *
     * @param MySQLi $database Database connection.
     * @param Validation $validator Upload validator.
     * @param ReleaseFiles $files Draft release file storage.
     * @param MetadataValidation $validation Version input rules.
     */
    public function __construct(MySQLi $database, Validation $validator, ReleaseFiles $files, MetadataValidation $validation)
    {
        $this->validation = $validation;
        $this->database = $database;
        $this->files = $files;
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
        $game = $this->lockOwnedGame($identifier, $developerId);
        $row = $this->lockVersion((int) $game->id, $version);
        $release = $this->lockRelease((int) $row->id);

        return new Selection((int) $game->id, (int) $row->id, (int) $release->id);
    }

    /**
     * Creates an owned draft version and its draft release atomically.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     *
     * @param string $identifier Public game identifier.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $data Version and stage.
     *
     * @return array<string, mixed>
     * @throws \Throwable When ownership, validation or database operations fail.
     */
    public function create(string $identifier, int $developerId, array $data): array
    {
        $this->validation->version($data);
        $version = $data['version'];
        $stage = $data['stage'] ?? 'alpha';
        return $this->database->transaction(function () use ($identifier, $developerId, $version, $stage): array {
            $game = $this->lockOwnedGame($identifier, $developerId);
            $this->requireAvailable((int) $game->id, $version);
            $row = $this->insert((int) $game->id, $version, $stage);
            $this->createRelease((int) $row->id);

            return $this->format($row, false, []);
        });
    }

    /**
     * Lists public versions, or all versions for the authenticated owner.
     *
     * @param string $identifier Game identifier.
     * @param int|null $developerId Optional authenticated developer.
     *
     * @return array<string, mixed>
     * @throws HttpException When the game is missing.
     */
    public function all(string $identifier, ?int $developerId): array
    {
        $game = $this->requireGame($identifier);
        $owner = $developerId !== null && (int) $game->developerId === $developerId;
        $rows = $this->loadVersions((int) $game->id);
        $visible = array_filter($rows, fn (stdClass $row): bool => $owner || (bool) $row->published);
        $files = $this->filesFor(array_column($visible, 'id'));

        $versions = [];
        foreach ($visible as $row) {
            $versions[] = $this->format($row, (bool) $row->published, $files[(int) $row->id] ?? []);
        }

        return ['versions' => $versions];
    }

    /**
     * Returns a version, hiding drafts from anyone except the owner.
     *
     * @param string $identifier Game identifier.
     * @param string $version Version string.
     * @param int|null $developerId Optional authenticated developer.
     *
     * @return array<string, mixed>
     * @throws HttpException When the game/version is missing or private.
     */
    public function get(string $identifier, string $version, ?int $developerId): array
    {
        $game = $this->requireGame($identifier);
        $row = $this->requireVersion((int) $game->id, $version);
        $published = $this->isPublished((int) $row->id, $row->published_at);
        if (!$published && ($developerId === null || (int) $game->developerId !== $developerId)) {
            throw new HttpException('not_found', 404);
        }
        return $this->document($row, $published);
    }

    /**
     * Replaces draft file references while retaining immutable old content objects.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     *
     * @param string $identifier Game identifier.
     * @param string $version Version string.
     * @param int $developerId Authenticated owner.
     * @param array<string, array<string, mixed>> $uploads PHP upload records.
     *
     * @return array<string, mixed>
     * @throws \Throwable When validation, storage or persistence fails; all new BLOBs are rolled back.
     */
    public function upload(string $identifier, string $version, int $developerId, array $uploads): array
    {
        return $this->database->transaction(function () use ($identifier, $version, $developerId, $uploads): array {
            $game = $this->lockOwnedGame($identifier, $developerId);
            $row = $this->lockDraftVersion((int) $game->id, $version);
            $release = $this->lockDraftRelease((int) $row->id);
            $releaseId = (int) $release->id;
            $this->requireGameFile($releaseId, $identifier, $uploads);
            $this->storeUploads($releaseId, $identifier, $uploads);
            return $this->document($row, false);
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
            'SELECT id FROM releases WHERE game_version_id = ? FOR UPDATE', [$versionId]
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
            SELECT v.id, v.version, v.stage, v.published_at,
                (v.published_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM releases r
                    WHERE r.game_version_id = v.id
                        AND (r.published_at IS NOT NULL OR EXISTS (
                            SELECT 1 FROM library_revision_releases vr
                            JOIN library_revisions library ON library.id = vr.library_revision_id
                            WHERE vr.release_id = r.id AND library.published_at IS NOT NULL
                        ))
                )) AS published
            FROM game_versions v
            WHERE v.game_id = ?
            ORDER BY v.id DESC
            SQL,
            [$parentId]
        );
    }

    /**
     * Rejects a version name already used by the locked parent.
     * @param int $gameId Parent database ID.
     * @param string $version Validated version name.
     * @return void
     * @throws HttpException When the version already exists.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireAvailable(int $gameId, string $version): void
    {
        $existing = $this->database->fetchOne(
            'SELECT id FROM game_versions WHERE game_id = ? AND version = ? FOR UPDATE', [$gameId, $version]
        );
        if ($existing !== null) {
            throw new HttpException('version_exists', 409);
        }
    }

    /**
     * Inserts one draft version and returns its initial metadata.
     * @param int $gameId Parent database ID.
     * @param string $version Validated version name.
     * @param string $stage Validated release stage.
     * @return stdClass New draft version.
     * @throws \mysqli_sql_exception When insertion fails.
     */
    private function insert(int $gameId, string $version, string $stage): stdClass
    {
        $id = $this->database->insert(
            'INSERT INTO game_versions (game_id, version, stage) VALUES (?, ?, ?)',
            [$gameId, $version, $stage]
        );

        return (object) ['id' => $id, 'version' => $version, 'stage' => $stage];
    }

    /**
     * Creates the single draft release belonging to the new version.
     * @param int $versionId New version database ID.
     * @return void
     * @throws \mysqli_sql_exception When insertion fails.
     */
    private function createRelease(int $versionId): void
    {
        $this->database->insert('INSERT INTO releases (game_version_id) VALUES (?)', [$versionId]);
    }

    /**
     * Locks a game and requires the authenticated developer to own it.
     * @param string $identifier Game identifier.
     * @param int $developerId Authenticated owner.
     * @return stdClass Locked game.
     * @throws HttpException When the game is missing or owned by another developer.
     */
    private function lockOwnedGame(string $identifier, int $developerId): stdClass
    {
        $game = $this->lockGame($identifier);
        if ((int) $game->developerId !== $developerId) {
            throw new HttpException('forbidden', 403);
        }

        return $game;
    }

    /**
     * Locks a version and rejects any existing publication reference.
     * @param int $gameId Game ID.
     * @param string $version Exact version string.
     * @return stdClass Locked unpublished version.
     * @throws HttpException When the version is missing or published.
     */
    private function lockDraftVersion(int $gameId, string $version): stdClass
    {
        $row = $this->lockVersion($gameId, $version);
        if ($this->isPublished((int) $row->id, $row->published_at)) {
            throw new HttpException('version_published', 409);
        }
        return $row;
    }

    /**
     * Locks the version's releases and requires exactly one unpublished release.
     * @param int $versionId Version ID.
     * @return stdClass Locked draft release.
     * @throws HttpException When the release is ambiguous or published.
     */
    private function lockDraftRelease(int $versionId): stdClass
    {
        $releases = $this->database->fetchAll(
            'SELECT id, published_at FROM releases WHERE game_version_id = ? FOR UPDATE', [$versionId]
        );
        if (count($releases) !== 1) {
            throw new HttpException('ambiguous_draft_release', 409);
        }
        if ($releases[0]->published_at !== null) {
            throw new HttpException('version_published', 409);
        }
        return $releases[0];
    }

    /**
     * Requires an executable in either the incoming upload or the existing draft.
     * @param int $releaseId Draft release ID.
     * @param string $identifier Game identifier.
     * @param array<string, array<string, mixed>> $uploads Incoming upload records.
     * @return void
     * @throws HttpException When no game executable is available.
     */
    private function requireGameFile(int $releaseId, string $identifier, array $uploads): void
    {
        $path = 'games/' . $identifier . '/' . $identifier . '.js';
        $existingGame = $this->database->fetchOne(
            'SELECT id FROM release_files WHERE release_id = ? AND path = ?', [$releaseId, $path]
        );
        if (!isset($uploads['game']) && $existingGame === null) {
            throw new HttpException('game_file_required', 422);
        }
    }

    /**
     * Stores each supplied file in the selected game's draft release.
     * @param int $releaseId Draft release ID.
     * @param string $identifier Game identifier.
     * @param array<string, array<string, mixed>> $uploads Incoming upload records.
     * @return void
     * @throws \Throwable When validation or storage fails.
     */
    private function storeUploads(int $releaseId, string $identifier, array $uploads): void
    {
        $prefix = 'games/' . $identifier . '/' . $identifier;
        foreach ($uploads as $kind => $upload) {
            $path = $prefix . ($kind === 'game' ? '.js' : '.png');
            $this->storeUpload($releaseId, $path, $kind, $upload);
        }
    }

    /**
     * Validates one upload, stores its bytes and replaces its release reference.
     * @param int $releaseId Draft release ID.
     * @param string $path Destination path within the release.
     * @param string $kind Upload kind.
     * @param array<string, mixed> $upload PHP upload record.
     * @return void
     * @throws \Throwable When validation or storage fails.
     */
    private function storeUpload(int $releaseId, string $path, string $kind, array $upload): void
    {
        $file = $this->validator->validate($kind, $upload);
        $this->files->replace($releaseId, $path, $file, $kind);
    }

    /**
     * Reads without acquiring a row lock the selected game.
     * @param string $identifier Game identifier.
     * @return stdClass Required record.
     * @throws HttpException When the record is missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireGame(string $identifier): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, developer_id AS developerId FROM games WHERE BINARY identifier = BINARY ?', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Locks the selected game.
     * @param string $identifier Game identifier.
     * @return stdClass Locked record.
     * @throws HttpException When the record is missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function lockGame(string $identifier): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, developer_id AS developerId FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Reads without acquiring a row lock the selected version.
     * @param int $gameId Game database ID.
     * @param string $version Version string.
     * @return stdClass Required record.
     * @throws HttpException When the record is missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function requireVersion(int $gameId, string $version): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, version, stage, published_at FROM game_versions WHERE game_id = ? AND BINARY version = BINARY ?', [$gameId, $version]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Locks the selected version.
     * @param int $gameId Game database ID.
     * @param string $version Version string.
     * @return stdClass Locked record.
     * @throws HttpException When the record is missing.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function lockVersion(int $gameId, string $version): stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, version, stage, published_at FROM game_versions WHERE game_id = ? AND BINARY version = BINARY ? FOR UPDATE', [$gameId, $version]
        ) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Treats any publication reference as an immutable version, including legacy fixtures.
     *
     * @param int $id Version ID.
     * @param string|null $date Version publication timestamp.
     * @return bool
     */
    private function isPublished(int $id, ?string $date): bool
    {
        if ($date !== null) {
            return true;
        }
        return $this->database->fetchAll(
            'SELECT r.id FROM releases AS r WHERE r.game_version_id = ? AND
                (r.published_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM library_revision_releases AS vr
                    JOIN library_revisions AS v ON v.id = vr.library_revision_id
                    WHERE vr.release_id = r.id AND v.published_at IS NOT NULL)) LIMIT 1', [$id]
        ) !== [];
    }

    /**
     * Loads file metadata and assembles one version response.
     * @param stdClass $row Version row.
     * @param bool $published Whether the version is immutable.
     * @return array<string, mixed> Version document.
     * @throws \mysqli_sql_exception When file metadata cannot be read.
     */
    private function document(stdClass $row, bool $published): array
    {
        $files = $this->getFiles((int) $row->id);

        return $this->format($row, $published, $files);
    }

    /**
     * Loads file metadata without binary contents.
     * @param int $versionId Version ID.
     * @return list<stdClass> Stored file metadata in insertion order.
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
            "SELECT r.game_version_id AS versionId, f.path, f.file_type AS kind, f.size_bytes AS size, f.checksum, f.uploaded_at AS uploadedAt
                FROM release_files f JOIN releases r ON r.id = f.release_id
                WHERE r.game_version_id IN ($placeholders) ORDER BY f.id",
            $versionIds
        );
        $files = [];
        foreach ($rows as $file) {
            $files[(int) $file->versionId][] = $file;
        }

        return $files;
    }

    /**
     * Formats previously loaded version and file metadata without database queries.
     *
     * @param stdClass $row Version row.
     * @param bool $published Whether the version is immutable.
     * @param list<stdClass> $rows Stored file metadata.
     * @return array<string, mixed>
     */
    private function format(stdClass $row, bool $published, array $rows): array
    {
        $files = [];
        foreach ($rows as $file) {
            $kind = $file->kind ?? (str_ends_with($file->path, '.js') ? 'game' : 'icon');
            if (!in_array(pathinfo($file->path, PATHINFO_EXTENSION), ['js', 'png'], true)) {
                continue;
            }
            $files[$kind] = [
                'filename' => $kind === 'game' ? 'game.js' : 'icon.png',
                'size' => (int) $file->size, 'sha256' => $file->checksum, 'uploaded_at' => $file->uploadedAt,
            ];
        }
        return [
            'version' => $row->version,
            'stage' => $row->stage,
            'published' => $published,
            'files' => (object) $files,
        ];
    }
}
