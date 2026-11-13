<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\FileResponse;
use RuneCloud\Http\Request;
use RuneCloud\Http\JsonResponse;
use RuneCloud\Library\Publisher;
use RuneCloud\Security\AdminAuth;
use RuneCloud\Http\HttpException;
use RuneCloud\Library\Files;
use RuneCloud\Model\Revisions;

/**
 * Handles downloads of registered library revision files.
 */
final class LibraryController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Resolves published library files.
     *
     * @var Files
     */
    private readonly Files $files;

    /**
     * Reads the current frozen manifest.
     *
     * @var Revisions
     */
    private readonly Revisions $libraries;

    /**
     * Publishes complete library snapshots.
     * @var Publisher
     */
    private readonly Publisher $publisher;

    /**
     * Requires the separate administrative credential.
     * @var AdminAuth
     */
    private readonly AdminAuth $administrator;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the published file model.
     *
     * @param Files $files Published file model.
     * @param Revisions $libraries Frozen manifest model.
     * @param Publisher $publisher Library publication service.
     * @param AdminAuth $administrator Administrative authentication.
     */
    public function __construct(Files $files, Revisions $libraries, Publisher $publisher, AdminAuth $administrator)
    {
        $this->files = $files;
        $this->libraries = $libraries;
        $this->publisher = $publisher;
        $this->administrator = $administrator;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns the current manifest or reports an unpublished library.
     *
     * @return array<string, mixed>
     * @throws HttpException When no complete revision has been published.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \JsonException When the stored manifest is invalid JSON.
     * @throws \RuntimeException When stored manifest data is inconsistent.
     */
    public function index(): array
    {
        $manifest = $this->libraries->getCurrentManifest();

        if ($manifest === null) {
            throw new HttpException('library_unavailable', 503);
        }

        return $manifest;
    }

    /**
     * Republishes the current library for an authenticated administrator.
     * @param Request $request Administrative Bearer credentials, without a body.
     * @return JsonResponse Complete manifest with HTTP 201.
     * @throws \Throwable When authentication, validation or publication fails.
     */
    public function publish(Request $request): JsonResponse
    {
        $this->administrator->require($request);
        if ($request->hasBody()) {
            throw new HttpException('body_not_allowed', 422);
        }
        return new JsonResponse($this->publisher->publishCurrent(), 201);
    }

    /**
     * Returns a file descriptor or reports an unknown file.
     *
     * @param string $revision Immutable revision key.
     * @param string $path Revision-relative file path.
     *
     * @return FileResponse
     * @throws HttpException When the file is unknown or disallowed.
     * @throws \mysqli_sql_exception When the query fails.
     * @throws \RuntimeException When published file storage is invalid.
     */
    public function download(string $revision, string $path): FileResponse
    {
        $file = $this->files->find($revision, $path);

        if ($file === null) {
            throw new HttpException('not_found', 404);
        }

        return $file;
    }
}
