<?php

declare(strict_types=1);

namespace RuneCloud\Highscore;

use RuneCloud\Http\HttpException;
use RuneCloud\MySQL\MySQLi;

/** Deletes all highscore tables belonging to an owned game. */
final class Clear
{
    /**
     * @param MySQLi $database Shared database connection.
     */
    public function __construct(private readonly MySQLi $database)
    {
    }

    /**
     * Locks the game to serialize deletion with highscore synchronization.
     * @param string $id Exact game identifier.
     * @param int $developerId Authenticated developer ID.
     * @return void
     * @throws \Throwable When the game is missing, not owned or deletion fails.
     */
    public function run(string $id, int $developerId): void
    {
        $this->database->transaction(function () use ($id, $developerId): void {
            $game = $this->database->fetchOne(
                'SELECT id, developer_id FROM games WHERE BINARY identifier = BINARY ? FOR UPDATE', [$id]
            ) ?? throw new HttpException('not_found', 404);
            if ((int) $game->developer_id !== $developerId) {
                throw new HttpException('forbidden', 403);
            }
            $this->database->execute('DELETE FROM highscores WHERE game_id = ?', [(int) $game->id]);
        });
    }
}
