<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\HttpException;
use RuneCloud\Model\Screensavers;
use RuneCloud\Http\Request;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Security\DeveloperAuth;
use mysqli_sql_exception;
use RuneCloud\Screensaver\Deletion;

/**
 * Handles requests for all current database screensavers.
 */
final class ScreensaverController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads the screensavers document.
     *
     * @var Screensavers
     */
    private readonly Screensavers $screensavers;

    /**
     * Authenticates screensaver registration.
     * @var DeveloperAuth
     */
    private readonly DeveloperAuth $authentication;

    /**
     * Deletes owned screensavers and selected versions atomically.
     * @var Deletion
     */
    private readonly Deletion $deletion;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the screensaver model.
     *
     * @param Screensavers $screensavers Screensaver data source.
     * @param DeveloperAuth $authentication Developer authentication.
     * @param Deletion $deletion Owned content deletion.
     */
    public function __construct(Screensavers $screensavers, DeveloperAuth $authentication, Deletion $deletion)
    {
        $this->screensavers = $screensavers;
        $this->authentication = $authentication;
        $this->deletion = $deletion;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Creates screensaver metadata without publishing a version.
     * @param Request $request Developer JSON input.
     * @return JsonResponse Created screensaver.
     * @throws \Throwable When authentication, validation or creation fails.
     */
    public function create(Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        try {
            return new JsonResponse($this->screensavers->create((int) $developer->id, $request->json()), 201);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('screensaver_identifier_taken', 409);
            }
            throw $error;
        }
    }

    /**
     * Returns basic metadata for all screensavers.
     *
     * @return array<string, mixed>
     * @throws \mysqli_sql_exception When the database query fails.
     * @throws \RuntimeException When metadata or required resource paths are invalid.
     * @throws \JsonException When required file JSON is invalid.
     */
    public function index(): array
    {
        return $this->screensavers->all();
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
        $document = $this->screensavers->all((int) $developer->id);
        $statuses = $this->screensavers->status(array_column($document['screensavers'], 'id'));
        $document['screensavers'] = array_map(
            fn (array $item): array => $item + $statuses[$item['id']], $document['screensavers']
        );

        return $document;
    }

    /**
     * Returns one screensaver or reports a missing public identifier.
     *
     * @param string $id Public screensaver identifier.
     *
     * @return array<string, mixed>
     * @throws HttpException When the screensaver does not exist.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When metadata or required resource paths are invalid.
     * @throws \JsonException When required file JSON is invalid.
     */
    public function show(string $id): array
    {
        $screensaver = $this->screensavers->find($id);

        if ($screensaver === null) {
            throw new HttpException('not_found', 404);
        }

        return $screensaver;
    }

    /**
     * Updates only submitted metadata for the authenticated owner's screensaver.
     * @param string $id Public screensaver identifier.
     * @param Request $request Developer JSON request.
     * @return array<string, mixed> Updated metadata.
     * @throws \Throwable When authentication, ownership or validation fails.
     */
    public function update(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);

        return $this->screensavers->update($id, (int) $developer->id, $request->json());
    }

    /**
     * Permanently deletes owned content and reconciles the active library.
     * @param string $id Screensaver identifier.
     * @param Request $request Developer Bearer credentials.
     * @return array<string, mixed> Deletion result.
     * @throws \Throwable When authentication or deletion fails.
     */
    public function delete(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->deletion->delete($id, (int) $developer->id);
        return ['deleted' => true, 'id' => $id];
    }

}
