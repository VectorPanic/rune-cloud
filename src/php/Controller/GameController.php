<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Security\DeveloperAuth;
use mysqli_sql_exception;
use RuneCloud\Model\Games;
use RuneCloud\Game\Deletion;

/**
 * Handles requests for all current database games.
 */
final class GameController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads the games document.
     *
     * @var Games
     */
    private readonly Games $games;

    /**
     * Resolves credentials for game writes.
     * @var DeveloperAuth
     */
    private readonly DeveloperAuth $authentication;

    /**
     * Removes owned games and their library content.
     * @var Deletion
     */
    private readonly Deletion $deletion;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the game model.
     *
     * @param Games $games Game data source.
     */
    public function __construct(Games $games, DeveloperAuth $authentication, Deletion $deletion)
    {
        $this->games = $games;
        $this->deletion = $deletion;
        $this->authentication = $authentication;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns game summaries with publication status and the currently distributed version.
     *
     * @return array<string, mixed>
     * @throws \mysqli_sql_exception When the database query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function index(): array
    {
        $document = $this->games->all();
        $statuses = $this->games->status(array_column($document['games'], 'id'));
        $document['games'] = array_map(
            fn (array $game): array => $game + $statuses[$game['id']], $document['games']
        );

        return $document;
    }

    /**
     * Lists only the authenticated developer's content with publication status.
     * @param Request $request Developer bearer credentials.
     * @return array<string, mixed> Owned content summaries.
     * @throws \Throwable When authentication or database reads fail.
     */
    public function owned(Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $document = $this->games->all((int) $developer->id);
        $statuses = $this->games->status(array_column($document['games'], 'id'));
        $document['games'] = array_map(
            fn (array $item): array => $item + $statuses[$item['id']], $document['games']
        );

        return $document;
    }

    /**
     * Returns one game or reports a missing public identifier.
     *
     * @param string $id Public game identifier.
     *
     * @return array<string, mixed>
     * @throws HttpException When the game does not exist.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function show(string $id): array
    {
        $game = $this->games->find($id);

        if ($game === null) {
            throw new HttpException('not_found', 404);
        }

        return $game + $this->games->status([$id])[$id];
    }

    /**
     * Creates a game for the authenticated developer without modifying published revisions.
     *
     * @param Request $request Explicit headers and JSON input.
     *
     * @return JsonResponse Created game with HTTP 201.
     * @throws HttpException When authentication, JSON, metadata or identifier validation fails.
     * @throws \Throwable When an unexpected database failure occurs.
     */
    public function create(Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        $data = $request->json();

        try {
            $game = $this->games->create((int) $developer->id, $data);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('game_identifier_taken', 409);
            }

            throw $error;
        }

        return new JsonResponse($game, 201);
    }

    /**
     * Updates only submitted metadata for the authenticated owner's game.
     * @param string $id Public game identifier.
     * @param Request $request Developer JSON request.
     * @return array<string, mixed> Updated metadata.
     * @throws \Throwable When authentication, ownership or validation fails.
     */
    public function update(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);

        return $this->games->update($id, (int) $developer->id, $request->json());
    }

    /**
     * Permanently deletes an owned game, its versions and releases.
     * @param string $id Game identifier.
     * @param Request $request Explicit request credentials.
     * @return array<string, mixed>
     * @throws HttpException When credentials, ownership or the identifier are invalid.
     * @throws \Throwable When deletion fails and is rolled back.
     */
    public function delete(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->deletion->delete($id, (int) $developer->id);
        return ['deleted' => true, 'id' => $id];
    }
}
