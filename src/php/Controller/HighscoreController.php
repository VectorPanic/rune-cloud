<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Game\Identifier;
use RuneCloud\Highscore\Sync;
use RuneCloud\Highscore\Clear;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;
use RuneCloud\Security\DeveloperAuth;

/** Handles requests to synchronize a game's highscore tables. */
final class HighscoreController
{
    /**
     * Receives the highscore synchronization service.
     * @param Sync $sync Service that validates and merges highscore tables.
     * @param Clear $clear Service that deletes an owned game's highscores.
     * @param DeveloperAuth $authentication Developer token authentication.
     */
    public function __construct(
        private readonly Sync $sync,
        private readonly Clear $clear,
        private readonly DeveloperAuth $authentication
    )
    {
    }

    /**
     * Returns merged tables with the same dimensions as the request.
     * @param Request $request JSON containing appId and tables.
     * @return array{tables: array} Synchronized SDK tables.
     * @throws \Throwable When the request or synchronization fails.
     */
    public function synchronize(Request $request): array
    {
        $data = $request->json();
        if (array_diff(array_keys($data), ['appId', 'tables']) !== []
            || !Identifier::isValid($data['appId'] ?? null)) {
            throw new HttpException('invalid_highscore_request', 422);
        }

        return ['tables' => $this->sync->run($data['appId'], $data['tables'] ?? null)];
    }

    /**
     * Deletes all highscore tables for an owned game.
     * @param string $id Exact game identifier.
     * @param Request $request Developer bearer credentials.
     * @return array{deleted: bool, id: string} Deletion result.
     * @throws \Throwable When authentication, ownership or deletion fails.
     */
    public function delete(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->clear->run($id, (int) $developer->id);
        return ['deleted' => true, 'id' => $id];
    }
}
