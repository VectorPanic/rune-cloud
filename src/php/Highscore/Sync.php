<?php

declare(strict_types=1);

namespace RuneCloud\Highscore;

use RuneCloud\Http\HttpException;
use RuneCloud\MySQL\MySQLi;

/** Merges SDK tables with stored scores for one registered game. */
final class Sync
{
    public function __construct(private readonly MySQLi $database, private readonly Validation $validation)
    {
    }

    /**
     * @param string $appId Exact SDK application ID.
     * @param mixed $input SDK highscore tables.
     * @return list<list<array{name:string,score:float,date:float}>> Merged tables.
     * @throws \Throwable When input, game lookup or database operations fail.
     */
    public function run(string $appId, mixed $input): array
    {
        $tables = $this->validation->tables($input);
        return $this->database->transaction(function () use ($appId, $tables): array {
            $game = $this->database->fetchOne('SELECT id FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$appId]);
            if ($game === null) throw new HttpException('game_not_found', 404);
            $result = [];
            foreach ($tables as $index => $table) {
                $result[] = $this->mergeTable((int) $game->id, $index, $table);
            }
            return $result;
        });
    }

    /**
     * @param int $gameId Internal game ID.
     * @param int $index SDK table index.
     * @param array $table Validated entries and placeholders.
     * @return array Merged table with its original length.
     */
    private function mergeTable(int $gameId, int $index, array $table): array
    {
        $placeholders = [];
        foreach ($table as $entry) {
            if ($entry['name'] === 'Rune' && $entry['score'] == 0.0) {
                $placeholders[] = $entry;
                continue;
            }
            $this->store($gameId, $index, $entry);
        }
        $limit = count($table);
        $rows = $this->database->fetchAll(
            'SELECT name, score, score_date FROM highscores WHERE game_id = ? AND table_index = ? '
            . 'ORDER BY score DESC, score_date ASC, fingerprint ASC LIMIT ' . $limit,
            [$gameId, $index]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['name' => $row->name, 'score' => (float) $row->score, 'date' => (float) $row->score_date];
        }
        while (count($result) < $limit) {
            $result[] = array_shift($placeholders) ?? ['name' => 'Rune', 'score' => 0.0, 'date' => 0.0];
        }
        return $result;
    }

    /**
     * Stores an entry once across repeated or concurrent syncs.
     * @param int $gameId Internal game ID.
     * @param int $index SDK table index.
     * @param array $entry Validated score entry.
     * @return void
     * @throws \Throwable When storage fails.
     */
    private function store(int $gameId, int $index, array $entry): void
    {
        $fingerprint = hash('sha256', json_encode([$entry['name'], $entry['score'], $entry['date']], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->database->execute(
            'INSERT INTO highscores (game_id, table_index, name, score, score_date, fingerprint) '
            . 'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE fingerprint = fingerprint',
            [$gameId, $index, $entry['name'], $entry['score'], $entry['date'], $fingerprint]
        );
    }
}
