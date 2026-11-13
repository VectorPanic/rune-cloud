<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;
use RuneCloud\Model\ApiTokens;
use RuneCloud\Security\DeveloperAuth;

/**
 * Handles token revocation for the authenticated developer.
 */
final class TokenController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores token credentials and revocation state.
     * @var ApiTokens
     */
    private readonly ApiTokens $tokens;

    /**
     * Resolves the developer making the request.
     * @var DeveloperAuth
     */
    private readonly DeveloperAuth $authentication;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives token handling and authentication.
     * @param ApiTokens $tokens Token model.
     * @param DeveloperAuth $authentication DeveloperAuth service.
     */
    public function __construct(ApiTokens $tokens, DeveloperAuth $authentication)
    {
        $this->tokens = $tokens;
        $this->authentication = $authentication;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Revokes an owned token, including the token used for this request.
     * @param string $id Numeric token ID.
     * @param Request $request Bearer credentials.
     * @return array<string, mixed>
     * @throws HttpException When authentication, validation or ownership lookup fails.
     */
    public function delete(string $id, Request $request): array
    {
        $developer = $this->authentication->requireDeveloper($request);
        $tokenId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tokenId === false) {
            throw new HttpException('invalid_token_id', 422);
        }
        
        if (!$this->tokens->revokeOwned($tokenId, (int) $developer->id)) {
            throw new HttpException('not_found', 404);
        }
        
        return ['revoked' => true, 'token_id' => $tokenId];
    }
}
