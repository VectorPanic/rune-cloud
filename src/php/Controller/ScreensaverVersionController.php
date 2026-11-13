<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Http\Request;
use RuneCloud\Library\Publisher;
use RuneCloud\Model\ScreensaverVersions;
use RuneCloud\Security\DeveloperAuth;
use mysqli_sql_exception;
use RuneCloud\Screensaver\Deletion;

/**
 * Handles developer screensaver drafts, file uploads and publication.
 */
final class ScreensaverVersionController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Draft storage.
     * @var ScreensaverVersions
     */
    private readonly ScreensaverVersions $versions;

    /**
     * Atomic publication.
     * @var Publisher
     */
    private readonly Publisher $publisher;

    /**
     * Resolves Developer credentials for owned content.
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
     * Receives version storage, publication and authentication.
     * @param ScreensaverVersions $versions Draft model.
     * @param Publisher $publisher Publication service.
     * @param DeveloperAuth $authentication Developer authentication.
     * @param Deletion $deletion Owned content deletion.
     */
    public function __construct(ScreensaverVersions $versions, Publisher $publisher, DeveloperAuth $authentication, Deletion $deletion)
    {
        $this->versions = $versions;
        $this->publisher = $publisher;
        $this->authentication = $authentication;
        $this->deletion = $deletion;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Registers a new draft version.
     * @param string $id Screensaver identifier.
     * @param Request $request Developer JSON request.
     * @return JsonResponse Created draft.
     * @throws \Throwable When authentication or creation fails.
     */
    public function create(string $id, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        try {
            return new JsonResponse($this->versions->create($id, (int) $developer->id, $request->json()), 201);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('version_exists', 409);
            }
            throw $error;
        }
    }

    /**
     * Lists published versions and includes drafts for the authenticated owner.
     * @param string $id Screensaver identifier.
     * @param Request $request Developer credentials.
     * @return array<string, mixed> Versions.
     * @throws \Throwable When authentication or lookup fails.
     */
    public function index(string $id, Request $request): array
    {
        return $this->versions->all($id, $this->authentication->optionalDeveloperId($request));
    }

    /**
     * Returns a published version or an authenticated owner's draft.
     * @param string $id Screensaver identifier.
     * @param string $version Exact version name.
     * @param Request $request Optional Developer credentials.
     * @return array<string, mixed> Version metadata and file information.
     * @throws \Throwable When authentication or lookup fails.
     */
    public function show(string $id, string $version, Request $request): array
    {
        return $this->versions->get($id, $version, $this->authentication->optionalDeveloperId($request));
    }

    /**
     * Uploads the single executable to its canonical draft filename.
     * @param string $id Screensaver identifier.
     * @param string $version Version string.
     * @param Request $request Multipart request containing file.
     * @return JsonResponse Updated version.
     * @throws \Throwable When authentication, validation or storage fails.
     */
    public function upload(string $id, string $version, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        $files = $request->files(['file']);
        return new JsonResponse($this->versions->upload($id, $version, (int) $developer->id, $files['file']), 201);
    }

    /**
     * Publishes or reactivates the selected screensaver version.
     * @param string $id Screensaver identifier.
     * @param string $version Version string.
     * @param Request $request Developer request without a body.
     * @return JsonResponse Complete new manifest.
     * @throws \Throwable When authentication or publication fails.
     */
    public function publish(string $id, string $version, Request $request): JsonResponse
    {
        $developer = $this->authentication->requireDeveloper($request);
        if ($request->hasBody()) {
            throw new HttpException('body_not_allowed', 422);
        }
        
        return new JsonResponse($this->publisher->publishScreensaver($id, $version, (int) $developer->id), 201);
    }

    /**
     * Permanently deletes owned content and reconciles the active library.
     * @param string $id Screensaver identifier.
     * @param string $version Exact version.
     * @param Request $request Developer Bearer credentials.
     * @return array<string, mixed> Deletion result.
     * @throws \Throwable When authentication or deletion fails.
     */
    public function delete(string $id, string $version, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->deletion->deleteVersion($id, $version, (int) $developer->id);
        return ['deleted' => true, 'id' => $id, 'version' => $version];
    }

}
