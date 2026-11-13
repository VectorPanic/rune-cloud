<?php

declare(strict_types=1);

namespace RuneCloud\Security;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;
use RuneCloud\Model\ApiTokens;
use stdClass;

/**
 * Resolves the authenticated developer for protected controller actions.
 */
final class DeveloperAuth
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Resolves active developer tokens.
     *
     * @var ApiTokens
     */
    private readonly ApiTokens $tokens;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the token model.
     *
     * @param ApiTokens $tokens Token model.
     */
    public function __construct(ApiTokens $tokens)
    {
        $this->tokens = $tokens;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Requires an active bearer token and returns its developer.
     *
     * @param Request $request Explicit request input.
     *
     * @return stdClass
     * @throws HttpException When the token is absent, invalid or revoked.
     * @throws \mysqli_sql_exception When the token query fails.
     */
    public function requireDeveloper(Request $request): stdClass
    {
        $developer = $this->tokens->findDeveloper($request->bearerToken());
        
        if ($developer === null) {
            throw new HttpException('unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }
        
        return $developer;
    }

    /**
     * Resolves optional credentials without treating invalid tokens as anonymous.
     * @param Request $request Request input.
     * @return int|null
     * @throws HttpException When supplied credentials are invalid.
     */
    public function optionalDeveloperId(Request $request): ?int
    {
        return $request->hasAuthorization() ? (int) $this->requireDeveloper($request)->id : null;
    }
}
