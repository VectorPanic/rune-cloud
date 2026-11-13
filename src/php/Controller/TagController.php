<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Model\Tags;
use RuneCloud\Security\AdminAuth;
use RuneCloud\Http\Request;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\JsonResponse;
use mysqli_sql_exception;

/**
 * Handles requests for all current database tags.
 */
final class TagController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads the tags document.
     *
     * @var Tags
     */
    private readonly Tags $tags;

    /**
     * Requires the separate administrator token for dictionary changes.
     * @var AdminAuth
     */
    private readonly AdminAuth $administrator;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the tag model.
     *
     * @param Tags $tags Tag data source.
     * @param AdminAuth $administrator Administrative authentication.
     */
    public function __construct(Tags $tags, AdminAuth $administrator)
    {
        $this->tags = $tags;
        $this->administrator = $administrator;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns all tags in the local library document format.
     *
     * @return array<string, mixed>
     * @throws \mysqli_sql_exception When the database query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function index(): array
    {
        return $this->tags->all();
    }

    /**
     * Returns one dictionary entry for editing.
     * @param string $id Tag identifier.
     * @return array<string, mixed> Tag document.
     * @throws HttpException When missing.
     */
    public function show(string $id): array
    {
        return $this->tags->find($id) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Registers a dictionary entry without modifying frozen revisions.
     * @param Request $request Administrative JSON input.
     * @return JsonResponse Created tag with HTTP 201.
     * @throws \Throwable When authentication, validation or creation fails.
     */
    public function create(Request $request): JsonResponse
    {
        $this->administrator->require($request);
        try {
            return new JsonResponse($this->tags->create($request->json()), 201);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('tag_identifier_taken', 409);
            }
            throw $error;
        }
    }

    /**
     * Replaces editable tag metadata while retaining its identifier.
     * @param string $id Tag identifier.
     * @param Request $request Administrative JSON input.
     * @return array<string, mixed> Updated tag.
     * @throws \Throwable When authentication or replacement fails.
     */
    public function update(string $id, Request $request): array
    {
        $this->administrator->require($request);
        return $this->tags->update($id, $request->json());
    }

    /**
     * Deletes an unused tag without modifying games or category rules.
     * @param string $id Tag identifier.
     * @param Request $request Administrative credentials.
     * @return array<string, mixed> Deletion result.
     * @throws \Throwable When authentication or deletion fails.
     */
    public function delete(string $id, Request $request): array
    {
        $this->administrator->require($request);
        $this->tags->delete($id);
        return ['deleted' => true, 'id' => $id];
    }

}
