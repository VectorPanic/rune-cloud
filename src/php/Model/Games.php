<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\Game\Validation;
use RuneCloud\MySQL\MySQLi;
use stdClass;
use RuneCloud\Http\HttpException;
use RuntimeException;

/**
 * Stores game metadata and reads game lists and OS documents.
 */
final class Games
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
     * Returns basic game metadata with public identifiers and optional year and player counts.
     *
     * @param int|null $developerId Optional owner scope; null includes all owners.
     * @return array{games: list<array<string, mixed>>}
     * @throws \mysqli_sql_exception When the database query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function all(?int $developerId = null): array
    {
        $rows = $this->database->fetchAll(
            <<<'SQL'
            SELECT
                g.identifier AS id,
                g.name,
                g.developer,
                g.year,
                g.min_players AS minimumPlayers,
                g.max_players AS maximumPlayers
            FROM games AS g
            WHERE (? IS NULL OR g.developer_id = ?)
            ORDER BY g.name COLLATE utf8mb4_bin, g.identifier COLLATE utf8mb4_bin
            SQL,
            [$developerId, $developerId]
        );

        $games = [];

        foreach ($rows as $row) {
            $games[] = ['id' => $row->id] + $this->format($row);
        }

        return ['games' => $games];
    }

    /**
     * Loads current-library selection and publication history with a fixed number of queries.
     * Missing games default to unpublished; map keys preserve public identifiers.
     * @param list<string> $identifiers Game identifiers.
     * @return array<string, array{published: bool, activeVersion: ?string, activeStage: ?string}> Status by identifier.
     * @throws \mysqli_sql_exception When the query fails.
     */
    public function status(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }
        $identifiers = array_values(array_unique($identifiers));
        $statuses = array_fill_keys($identifiers, ['published' => false, 'activeVersion' => null, 'activeStage' => null]);
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
                MAX(CASE WHEN membership.library_revision_id = ? THEN v.version END) AS activeVersion,
                MAX(CASE WHEN membership.library_revision_id = ? THEN v.stage END) AS activeStage
            FROM games g
            LEFT JOIN game_versions v ON v.game_id = g.id
            LEFT JOIN releases r ON r.game_version_id = v.id
            LEFT JOIN library_revision_releases membership ON membership.release_id = r.id
            LEFT JOIN library_revisions library ON library.id = membership.library_revision_id
            WHERE BINARY g.identifier IN ($placeholders)
            GROUP BY g.id, g.identifier
            SQL,
            [$current->id ?? null, $current->id ?? null, ...$identifiers]
        );
        foreach ($rows as $row) {
            $statuses[$row->id] = ['published' => (bool) $row->published, 'activeVersion' => $row->activeVersion,
                'activeStage' => $row->activeStage];
        }

        return $statuses;
    }

    /**
     * Returns one game's OS metadata using its public identifier.
     *
     * @param string $id Public game identifier.
     *
     * @return array<string, mixed>|null Metadata, or null when the game is missing.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function find(string $id): ?array
    {
        $row = $this->database->fetchOne(
            <<<'SQL'
            SELECT
                g.id AS gameId,
                g.name,
                g.developer,
                g.description,
                g.year,
                g.min_players AS minimumPlayers,
                g.max_players AS maximumPlayers,
                (
                    SELECT v.stage
                    FROM game_versions AS v
                    WHERE v.game_id = g.id
                    ORDER BY v.published_at DESC, v.id DESC
                    LIMIT 1
                ) AS stage
            FROM games AS g
            WHERE BINARY g.identifier = BINARY ?
            SQL,
            [$id]
        );

        if ($row === null) {
            return null;
        }

        $game = $this->format($row);
        $game['stage'] = $row->stage ?? 'gold';
        $game['description'] = $row->description ?? '';
        $game['type'] = 'game';
        $game['input'] = $this->getInputs((int) $row->gameId);
        $game['tags'] = (object) [];
        $game['baseTags'] = (object) [];

        foreach ($this->getTags((int) $row->gameId) as $tag) {
            $field = $tag->kind === 'base' ? 'baseTags' : 'tags';
            $game[$field]->{$tag->id} = (float) $tag->strength;
        }

        $game['modes'] = $this->getModes((int) $row->gameId);

        return $game;
    }

    /**
     * Creates an unpublished game owned by the authenticated developer.
     *
     * @param int $developerId Authenticated developer ID, never taken from submitted JSON.
     * @param array<string, mixed> $data Submitted game metadata.
     *
     * @return array<string, mixed> Complete created game metadata.
     * @throws HttpException When submitted metadata is invalid.
     * @throws \mysqli_sql_exception When the identifier is taken or the write fails.
     * @throws RuntimeException When the developer no longer exists.
     */
    public function create(int $developerId, array $data): array
    {
        $this->validation->validate($data);
        return (new \RuneCloud\Library\Lock($this->database))->run(fn (): array => $this->database->transaction(
            function () use ($developerId, $data): array {
                $developer = $this->requireDeveloper($developerId);
                $id = $this->insert($developerId, $developer, $data);
                $this->storeMetadata($id, $data);

                return $this->document($data['id']);
            }
        ));
    }

    /**
     * Updates an owned game's metadata without changing frozen library revisions.
     * Owns its transaction and serializes metadata changes with publication.
     * @param string $identifier Public game identifier.
     * @param int $developerId Authenticated owner.
     * @param array<string, mixed> $data Submitted metadata changes.
     * @return array<string, mixed> Complete updated metadata.
     * @throws \Throwable When ownership, validation or storage fails.
     */
    public function update(string $identifier, int $developerId, array $data): array
    {
        $this->validation->update($data);
        return (new \RuneCloud\Library\Lock($this->database))->run(fn (): array => $this->database->transaction(
            function () use ($identifier, $developerId, $data): array {
                $id = $this->lockOwned($identifier, $developerId);
                $this->updateDetails($id, $data);
                $this->storeMetadata($id, $data);

                return $this->document($identifier);
            }
        ));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Locks the game before checking the authenticated owner.
     * @param string $identifier Public identifier.
     * @param int $developerId Required owner.
     * @return int Locked game ID.
     * @throws HttpException When missing or owned by another developer.
     */
    private function lockOwned(string $identifier, int $developerId): int
    {
        $game = $this->database->fetchOne(
            'SELECT id, developer_id FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$identifier]
        ) ?? throw new HttpException('not_found', 404);
        if ((int) $game->developer_id !== $developerId) {
            throw new HttpException('forbidden', 403);
        }

        return (int) $game->id;
    }

    /**
     * Stores submitted scalar fields using a fixed column allowlist.
     * @param int $id Game ID.
     * @param array<string, mixed> $data Validated changes.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function updateDetails(int $id, array $data): void
    {
        $columns = [];
        $values = [];
        foreach (['name', 'description', 'year'] as $field) {
            if (array_key_exists($field, $data)) {
                $columns[] = $field . ' = ?';
                $values[] = $data[$field];
            }
        }
        if ($columns !== []) {
            $this->database->execute('UPDATE games SET ' . implode(', ', $columns) . ' WHERE id = ?', [...$values, $id]);
        }
    }

    /**
     * Stores submitted input, player, tag and mode data within the caller's transaction.
     * @param int $id Game ID.
     * @param array<string, mixed> $data Validated changes.
     * @return void
     * @throws \Throwable When a referenced tag or storage operation is invalid.
     */
    private function storeMetadata(int $id, array $data): void
    {
        if (array_key_exists('input', $data)) {
            $this->updateInputs($id, $data['input']);
        }
        if (array_key_exists('players', $data)) {
            $this->updatePlayers($id, $data['players']);
        }
        if (array_key_exists('baseTags', $data)) {
            $this->updateTags($id, 'base', $data['baseTags']);
        }
        if (array_key_exists('tags', $data)) {
            $this->updateTags($id, 'modifier', $data['tags']);
        }
        if (array_key_exists('modes', $data)) {
            $this->updateModes($id, $data['modes']);
        }
    }

    /**
     * Returns complete metadata after checking multiplayer consistency.
     * @param string $identifier Stored game identifier.
     * @return array<string, mixed> Game metadata with its identifier.
     * @throws HttpException When multiplayer has no compatible player count.
     */
    private function document(string $identifier): array
    {
        $game = $this->find($identifier);
        if (($game['modes']['coop'] || $game['modes']['versus']) && ($game['players']['max'] ?? 0) < 2) {
            throw new HttpException('multiplayer_requires_multiple_players', 422);
        }

        return ['id' => $identifier] + $game;
    }

    /**
     * Returns the name of the developer owning the new game.
     * @param int $developerId Authenticated developer ID.
     * @return string Developer name.
     * @throws RuntimeException When the developer no longer exists.
     * @throws \mysqli_sql_exception When the query fails.
     */
    private function requireDeveloper(int $developerId): string
    {
        $account = $this->database->fetchOne('SELECT name FROM developers WHERE id = ?', [$developerId]);

        if ($account === null) {
            throw new RuntimeException('Developer does not exist.');
        }

        return $account->name;
    }

    /**
     * Inserts basic game metadata before its related records are stored.
     * @param int $developerId Authenticated owner ID.
     * @param string $developer Developer name.
     * @param array<string, mixed> $data Validated metadata.
     * @return int New game ID.
     * @throws \mysqli_sql_exception When the identifier is taken or the write fails.
     */
    private function insert(int $developerId, string $developer, array $data): int
    {
        return $this->database->insert(
            'INSERT INTO games (developer_id, identifier, name, developer, description, year) VALUES (?, ?, ?, ?, ?, ?)',
            [$developerId, $data['id'], $data['name'], $developer, $data['description'] ?? '', $data['year'] ?? null]
        );
    }

    /**
     * Converts a database row to public game metadata for list and detail responses.
     *
     * @param stdClass $row Game database row.
     *
     * @return array<string, mixed>
     */
    private function format(stdClass $row): array
    {
        $game = [
            'name' => $row->name,
            'developer' => $row->developer,
        ];

        if ($row->year !== null) {
            $game['year'] = (int) $row->year;
        }

        if ($row->minimumPlayers !== null && $row->maximumPlayers !== null) {
            $game['players'] = [
                'min' => (int) $row->minimumPlayers,
                'max' => (int) $row->maximumPlayers,
            ];
        }

        return $game;
    }

    /**
     * Returns the game's supported input methods in stable alphabetical order.
     *
     * @param int $gameId Internal game ID.
     *
     * @return list<string>
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    private function getInputs(int $gameId): array
    {
        $rows = $this->database->fetchAll(
            <<<'SQL'
            SELECT i.input_type AS input
            FROM game_inputs AS i
            WHERE i.game_id = ?
            ORDER BY CAST(i.input_type AS CHAR) COLLATE utf8mb4_bin
            SQL,
            [$gameId]
        );

        return array_map(fn (stdClass $row): string => $row->input, $rows);
    }

    /**
     * Returns base and modifier tag strengths for the game.
     *
     * @param int $gameId Internal game ID.
     *
     * @return list<stdClass>
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    private function getTags(int $gameId): array
    {
        return $this->database->fetchAll(
            <<<'SQL'
            SELECT t.identifier AS id, t.kind, gt.strength
            FROM game_tags AS gt
            JOIN tags AS t ON t.id = gt.tag_id
            WHERE gt.game_id = ?
            ORDER BY t.identifier COLLATE utf8mb4_bin
            SQL,
            [$gameId]
        );
    }

    /**
     * Returns explicit cooperative and versus support flags.
     *
     * @param int $gameId Internal game ID.
     *
     * @return array{coop: bool, versus: bool}
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    private function getModes(int $gameId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT mode FROM game_modes WHERE game_id = ?',
            [$gameId]
        );

        $modes = ['coop' => false, 'versus' => false];

        foreach ($rows as $row) {
            $modes[$row->mode] = true;
        }

        return $modes;
    }

    /**
     * Stores validated supported input methods.
     * @param int $id Game ID.
     * @param mixed $inputs Submitted supported input methods.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function updateInputs(int $id, mixed $inputs): void
    {
        $this->database->execute('DELETE FROM game_inputs WHERE game_id=?', [$id]);
        foreach ($inputs as $input) {
            $this->database->execute('INSERT INTO game_inputs VALUES (?,?)', [$id, $input]);
        }
    }

    /**
     * Stores validated player counts.
     * @param int $id Game ID.
     * @param mixed $players Submitted player counts.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function updatePlayers(int $id, mixed $players): void
    {
        $this->database->execute(
            'UPDATE games SET min_players=?,max_players=? WHERE id=?', [$players->min, $players->max, $id]
        );
    }

    /**
     * Replaces the submitted tag kind while preserving the other kind.
     * @param int $id Game ID.
     * @param string $kind Base or modifier tag kind.
     * @param mixed $tags Submitted tag strengths.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function updateTags(int $id, string $kind, mixed $tags): void
    {
        $this->database->execute(
            'DELETE gt FROM game_tags gt JOIN tags t ON t.id=gt.tag_id WHERE gt.game_id=? AND t.kind=?',
            [$id, $kind]
        );
        foreach ((array) $tags as $tag => $strength) {
            $tagId = $this->requireTag((string) $tag, $kind);
            $this->database->execute('INSERT INTO game_tags VALUES (?,?,?)', [$id, $tagId, $strength]);
        }
    }

    /**
     * Returns the ID of a tag matching the requested kind.
     * @param string $tag Tag identifier.
     * @param string $kind Required tag kind.
     * @return int
     * @throws HttpException When metadata is invalid.
     */
    private function requireTag(string $tag, string $kind): int
    {
        $rows = $this->database->fetchAll(
            'SELECT id FROM tags WHERE BINARY identifier=BINARY ? AND kind=?', [$tag, $kind]
        );
        if ($rows === []) {
            throw new HttpException('invalid_game_tags', 422);
        }
        return (int) $rows[0]->id;
    }

    /**
     * Updates only the submitted cooperative or versus modes.
     * @param int $id Game ID.
     * @param mixed $modes Submitted mode flags.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function updateModes(int $id, mixed $modes): void
    {
        foreach ((array) $modes as $mode => $enabled) {
            $this->updateMode($id, $mode, $enabled);
        }
    }

    /**
     * Stores one validated mode flag.
     * @param int $id Game ID.
     * @param string $mode Mode name.
     * @param bool $enabled Validated flag.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function updateMode(int $id, string $mode, bool $enabled): void
    {
        $this->database->execute('DELETE FROM game_modes WHERE game_id=? AND mode=?', [$id, $mode]);
        if ($enabled) {
            $this->database->execute('INSERT INTO game_modes VALUES (?,?)', [$id, $mode]);
        }
    }
}
