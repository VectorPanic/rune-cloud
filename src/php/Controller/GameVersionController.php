<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\Request;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Security\DeveloperAuth;
use RuneCloud\Model\GameVersions;
use RuneCloud\Game\Deletion;
use RuneCloud\Http\HttpException;
use RuneCloud\Library\Publisher;

/**
 * Handles version metadata and authenticated multipart uploads.
 */
final class GameVersionController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Manages version metadata and files.
     *
     * @var GameVersions
     */
    private readonly GameVersions $versions;

    /**
     * Removes versions and maintains the current game library.
     * @var Deletion
     */
    private readonly Deletion $deletion;

    /**
     * Resolves the authenticated developer.
     *
     * @var DeveloperAuth
     */
    private readonly DeveloperAuth $authentication;

    /**
     * Publishes validated owned drafts as complete library revisions.
     * @var Publisher
     */
    private readonly Publisher $publisher;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives version handling and authentication.
     *
     * @param GameVersions $versions Version model.
     * @param DeveloperAuth $authentication DeveloperAuth service.
     * @param Publisher $publisher Library publication service.
     * @param Deletion $deletion Version deletion service.
     */
    public function __construct(
        GameVersions $versions, DeveloperAuth $authentication, Publisher $publisher, Deletion $deletion
    )
    {
        $this->versions = $versions;
        $this->deletion = $deletion;
        $this->authentication = $authentication;
        $this->publisher = $publisher;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Creates an owned draft version.
     * @param string $id Game identifier.
     * @param Request $request Request input.
     * @return JsonResponse
     * @throws \Throwable When authentication or creation fails.
     */
    public function create(string $id, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        return new JsonResponse($this->versions->create($id, (int) $developer->id, $request->json()), 201);
    }

    /**
     * Uploads files to an owned unpublished version.
     * @param string $id Game identifier.
     * @param string $version Version string.
     * @param Request $request Request input.
     * @return JsonResponse
     * @throws \Throwable When authentication, validation or storage fails.
     */
    public function upload(string $id, string $version, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        return new JsonResponse($this->versions->upload($id, $version, (int) $developer->id, $request->files()), 201);
    }

    /**
     * Lists public versions or the owner's drafts.
     * @param string $id Game identifier.
     * @param Request $request Request input.
     * @return array<string, mixed>
     * @throws \Throwable When authentication or lookup fails.
     */
    public function index(string $id, Request $request): array
    {
        return $this->versions->all($id, $this->authentication->optionalDeveloperId($request));
    }

    /**
     * Returns a public version or an owner's draft.
     * @param string $id Game identifier.
     * @param string $version Version string.
     * @param Request $request Request input.
     * @return array<string, mixed>
     * @throws \Throwable When authentication or lookup fails.
     */
    public function show(string $id, string $version, Request $request): array
    {
        return $this->versions->get($id, $version, $this->authentication->optionalDeveloperId($request));
    }

    /**
     * Publishes or reactivates an owned version using saved game metadata.
     * @param string $id Game identifier.
     * @param string $version Version string.
     * @param Request $request Authenticated input.
     * @return JsonResponse New complete manifest with HTTP 201.
     * @throws \Throwable When authorization or publication fails.
     */
    public function publish(string $id, string $version, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        if ($request->hasBody()) {
            throw new HttpException('body_not_allowed', 422);
        }
        return new JsonResponse($this->publisher->publishGame($id, $version, (int) $developer->id), 201);
    }

    /**
     * Permanently deletes a single owned version while retaining the game.
     * @param string $id Game identifier.
     * @param string $version Exact version string.
     * @param Request $request Request credentials.
     * @return array<string, mixed>
     * @throws HttpException When authentication, ownership or lookup fails.
     * @throws \Throwable When deletion fails and is rolled back.
     */
    public function delete(string $id, string $version, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->deletion->deleteVersion($id, $version, (int) $developer->id);
        return ['deleted' => true, 'id' => $id, 'version' => $version];
    }
}
