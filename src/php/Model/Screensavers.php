<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\Screensaver\Validation;
use RuneCloud\MySQL\MySQLi;
use stdClass;
use RuntimeException;
use RuneCloud\Http\HttpException;

/**
 * Reads screensaver lists and independent OS metadata.
 */
final class Screensavers
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads screensaver records from the database.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Validates submitted metadata.
     * @var Validation
     */
    private readonly Validation $validation;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection from the caller.
     *
     * @param MySQLi $database Shared database connection.
     * @param Validation $validation Metadata rules.
     */
    public function __construct(MySQLi $database, Validation $validation)
    {
        $this->validation = $validation;
        $this->database = $database;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Registers independent screensaver metadata without publishing content.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $data Screensaver metadata.
     * @return array<string, mixed> Created public document.
     * @throws \Throwable When metadata is invalid or the identifier exists.
     */
    public function create(int $developerId, array $data): array
    {
        $this->validation->validate($data);
        $id = $data['id'];
        $this->database->insert(
            'INSERT INTO screensavers (developer_id, identifier, name, author, year, enabled) VALUES (?,?,?,?,?,?)',
            [$developerId, $id, $data['name'], $data['author'] ?? null, $data['year'] ?? null, (int) ($data['enabled'] ?? true)]
        );
        return ['id' => $id] + $this->find($id);
    }

    /**
     * Updates an owned screensaver without changing frozen library metadata.
     * Owns its transaction and serializes changes with publication.
     * @param string $identifier Public screensaver identifier.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $data Submitted metadata changes.
     * @return array<string, mixed> Updated metadata.
     * @throws \Throwable When ownership, validation or storage fails.
     */
    public function update(string $identifier, int $developerId, array $data): array
    {
        $this->validation->update($data);
        return (new \RuneCloud\Library\Lock($this->database))->run(fn (): array => $this->database->transaction(
            function () use ($identifier, $developerId, $data): array {
                $id = $this->lockOwned($identifier, $developerId);
                $this->storeChanges($id, $data);

                return ['id' => $identifier] + $this->find($identifier);
            }
        ));
    }

    /**
     * Returns all screensavers with public identifiers, including disabled entries.
     *
     * @param int|null $developerId Optional owner scope; null includes all owners.
     * @return array{screensavers: list<array<string, mixed>>}
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When stored metadata is invalid.
     */
    public function all(?int $developerId = null): array
    {
        $rows = $this->database->fetchAll(
            <<<'SQL'
            SELECT s.identifier AS id, s.name, COALESCE(s.author, d.name) AS author, s.year, s.enabled
            FROM screensavers AS s
            LEFT JOIN developers AS d ON d.id = s.developer_id
            WHERE (? IS NULL OR s.developer_id = ?)
            ORDER BY s.name COLLATE utf8mb4_bin, s.identifier COLLATE utf8mb4_bin
            SQL,
            [$developerId, $developerId]
        );

        $screensavers = [];

        foreach ($rows as $row) {
            $screensavers[] = ['id' => $row->id] + $this->format($row);
        }

        return ['screensavers' => $screensavers];
    }

    /**
     * Loads current-library selection and publication history with a fixed number of queries.
     * Missing screensavers default to unpublished; map keys are lowercase public identifiers.
     * @param list<string> $identifiers Screensaver identifiers.
     * @return array<string, array{published: bool, activeVersion: ?string}> Status by identifier.
     * @throws \mysqli_sql_exception When the query fails.
     */
    public function status(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }
        $identifiers = array_values(array_unique(array_map('strtolower', $identifiers)));
        $statuses = array_fill_keys($identifiers, ['published' => false, 'activeVersion' => null]);
        $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
        $current = $this->database->fetchOne(
            'SELECT id FROM library_revisions
                WHERE published_at IS NOT NULL AND manifest_content_id IS NOT NULL
                ORDER BY published_at DESC, id DESC LIMIT 1'
        );
        $rows = $this->database->fetchAll(
            <<<SQL
            SELECT g.identifier AS id,
                MAX(v.published_at IS NOT NULL OR r.published_at IS NOT NULL
                    OR library.published_at IS NOT NULL) AS published,
                MAX(CASE WHEN membership.library_revision_id = ? THEN v.version END) AS activeVersion
            FROM screensavers g
            LEFT JOIN screensaver_versions v ON v.screensaver_id = g.id
            LEFT JOIN releases r ON r.screensaver_version_id = v.id
            LEFT JOIN library_revision_releases membership ON membership.release_id = r.id
            LEFT JOIN library_revisions library ON library.id = membership.library_revision_id
            WHERE g.identifier IN ($placeholders)
            GROUP BY g.id, g.identifier
            SQL,
            [$current->id ?? null, ...$identifiers]
        );
        foreach ($rows as $row) {
            $statuses[$row->id] = ['published' => (bool) $row->published, 'activeVersion' => $row->activeVersion];
        }

        return $statuses;
    }

    /**
     * Returns one screensaver's OS metadata without game-specific fields.
     *
     * @param string $id Public screensaver identifier.
     *
     * @return array<string, mixed>|null Metadata, or null when missing.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When stored metadata is invalid.
     */
    public function find(string $id): ?array
    {
        $row = $this->database->fetchOne(
            <<<'SQL'
            SELECT s.name, COALESCE(s.author, d.name) AS author, s.year, s.enabled
            FROM screensavers AS s
            LEFT JOIN developers AS d ON d.id = s.developer_id
            WHERE BINARY s.identifier = BINARY ?
            SQL,
            [$id]
        );

        return $row === null ? null : $this->format($row);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Locks the selected screensaver before checking its owner.
     * @param string $identifier Public identifier.
     * @param int $developerId Required owner.
     * @return int Locked screensaver ID.
     * @throws HttpException When missing or owned by another developer.
     */
    private function lockOwned(string $identifier, int $developerId): int
    {
        $saver = $this->database->fetchOne(
            'SELECT id, developer_id FROM screensavers WHERE BINARY identifier = BINARY ? FOR UPDATE', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
        if ((int) $saver->developer_id !== $developerId) {
            throw new HttpException('forbidden', 403);
        }

        return (int) $saver->id;
    }

    /**
     * Stores only submitted fields using a fixed column allowlist.
     * @param int $id Screensaver ID.
     * @param array<string, mixed> $data Validated metadata changes.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeChanges(int $id, array $data): void
    {
        $columns = [];
        $values = [];
        foreach (['name', 'author', 'year', 'enabled'] as $field) {
            if (array_key_exists($field, $data)) {
                $columns[] = $field . ' = ?';
                $values[] = $field === 'enabled' ? (int) $data[$field] : $data[$field];
            }
        }
        if ($columns !== []) {
            $this->database->execute('UPDATE screensavers SET ' . implode(', ', $columns) . ' WHERE id = ?', [...$values, $id]);
        }
    }

    /**
     * Converts a database row to the independent screensaver metadata format.
     *
     * @param stdClass $row Screensaver database row.
     *
     * @return array<string, mixed>
     * @throws RuntimeException When stored metadata is invalid.
     */
    private function format(stdClass $row): array
    {
        if (!is_string($row->name) || trim($row->name) === '') {
            throw new RuntimeException('Screensaver name must be nonempty text.');
        }

        if (!in_array($row->enabled, [0, 1, '0', '1'], true)) {
            throw new RuntimeException('Screensaver enabled must be 0 or 1.');
        }

        $screensaver = ['name' => $row->name, 'enabled' => (bool) $row->enabled];

        if ($row->author !== null) {
            if (!is_string($row->author) || trim($row->author) === '') {
                throw new RuntimeException('Screensaver author must be nonempty text when provided.');
            }

            $screensaver['author'] = $row->author;
        }

        if ($row->year !== null) {
            $year = filter_var($row->year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($year === false) {
                throw new RuntimeException('Screensaver year must be a nonnegative integer.');
            }

            $screensaver['year'] = $year;
        }

        return $screensaver;
    }

}
