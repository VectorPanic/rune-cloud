<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Security\DeveloperAuth;
use RuneCloud\Developer\Deletion;
use RuneCloud\Developer\Registration;
use RuneCloud\Http\HttpException;
use RuneCloud\Http\JsonResponse;
use mysqli_sql_exception;
use RuneCloud\Http\Request;

/**
 * Handles developer registration and authenticated account requests.
 */
final class DeveloperController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Authenticates developer requests.
     *
     * @var DeveloperAuth
     */
    private readonly DeveloperAuth $authentication;

    /**
     * Registers accounts and initial tokens atomically.
     * @var Registration
     */
    private readonly Registration $registration;

    /**
     * Erases accounts and owned content atomically.
     * @var Deletion
     */
    private readonly Deletion $deletion;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the authentication service.
     *
     * @param DeveloperAuth $authentication DeveloperAuth service.
     * @param Registration $registration Account registration.
     * @param Deletion $deletion Account deletion.
     */
    public function __construct(
        DeveloperAuth $authentication, Registration $registration, Deletion $deletion
    )
    {
        $this->authentication = $authentication;
        $this->registration = $registration;
        $this->deletion = $deletion;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Registers a developer without requiring existing credentials.
     * @param Request $request JSON registration input.
     * @return JsonResponse Created identity and first token with HTTP 201.
     * @throws HttpException When input is invalid or the identifier is taken.
     * @throws \Throwable When registration cannot be committed.
     */
    public function create(Request $request): JsonResponse
    {
        $data = $request->json();
        if (array_diff(array_keys($data), ['identifier', 'name']) !== []
            || !is_string($data['identifier'] ?? null)
            || !is_string($data['name'] ?? null)
        ) {
            throw new HttpException('invalid_developer_metadata', 422);
        }
        try {
            $result = $this->registration->register($data['identifier'], $data['name']);
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new HttpException('developer_identifier_taken', 409);
            }
            
            throw $error;
        }
        return new JsonResponse($result, 201);
    }

    /**
     * Returns the developer identity without token information.
     *
     * @param Request $request Explicit authorization header.
     *
     * @return array<string, mixed>
     * @throws \RuneCloud\Http\HttpException When the credentials are invalid.
     * @throws \mysqli_sql_exception When the query fails.
     */
    public function show(Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);

        return ['developer' => [
            'id' => (int) $developer->id,
            'identifier' => $developer->identifier,
            'name' => $developer->name,
        ]];
    }

    /**
     * Erases the authenticated developer and all its owned content.
     * @param Request $request Bearer credentials.
     * @return array<string, bool>
     * @throws \Throwable When authentication or deletion fails.
     */
    public function delete(Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $this->deletion->delete((int) $developer->id);
        return ['deleted' => true];
    }
}
