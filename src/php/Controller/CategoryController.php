<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Model\Categories;
use RuneCloud\Security\AdminAuth;
use RuneCloud\Http\Request;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\JsonResponse;
use mysqli_sql_exception;

/**
 * Handles requests for all current database categories.
 */
final class CategoryController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads the categories document.
     *
     * @var Categories
     */
    private readonly Categories $categories;

    /**
     * Requires the separate administrative credential for category changes.
     * @var AdminAuth
     */
    private readonly AdminAuth $administrator;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the category model.
     *
     * @param Categories $categories Category data source.
     * @param AdminAuth $administrator Administrative authentication.
     */
    public function __construct(Categories $categories, AdminAuth $administrator)
    {
        $this->categories = $categories;
        $this->administrator = $administrator;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns all categories in the local library document format.
     *
     * @return array<string, mixed>
     * @throws \mysqli_sql_exception When the database query fails.
     * @throws \RuntimeException When the query does not return rows.
     */
    public function index(): array
    {
        return $this->categories->all();
    }

    /**
     * Returns one category with its editable sort order.
     * @param string $id Category identifier.
     * @return array<string, mixed> Category document.
     * @throws HttpException When missing.
     */
    public function show(string $id): array
    {
        return $this->categories->find($id) ?? throw new HttpException('not_found', 404);
    }

    /**
     * Creates category metadata and rules for later library publication.
     * @param Request $request Administrative JSON input.
     * @return JsonResponse Created category with HTTP 201.
     * @throws \Throwable When authentication or creation fails.
     */
    public function create(Request $request): JsonResponse
    {
        $this->administrator->require($request);
        
        try {
            return new JsonResponse($this->categories->create($request->json()), 201);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('category_identifier_taken', 409);
            }
            
            throw $error;
        }
    }

    /**
     * Replaces one category using a complete editable document.
     * @param string $id Category identifier.
     * @param Request $request Administrative JSON input.
     * @return array<string, mixed> Updated category with HTTP 200.
     * @throws \Throwable When authentication, lookup or validation fails.
     */
    public function update(string $id, Request $request): array
    {
        $this->administrator->require($request);
        return $this->categories->update($id, $request->json());
    }

    /**
     * Removes one live category and its rules.
     * @param string $id Category identifier.
     * @param Request $request Administrative credentials.
     * @return array<string, mixed> Deletion result.
     * @throws \Throwable When authentication or deletion fails.
     */
    public function delete(string $id, Request $request): array
    {
        $this->administrator->require($request);
        $this->categories->delete($id);
        return ['deleted' => true, 'id' => $id];
    }

}
