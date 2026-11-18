<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Game\Identifier;
use RuneCloud\Highscore\Sync;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;

/** Handles requests to synchronize a game's highscore tables. */
final class HighscoreController
{
    /**
     * Receives the highscore synchronization service.
     * @param Sync $sync Service that validates and merges highscore tables.
     */
    public function __construct(private readonly Sync $sync)
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
}
