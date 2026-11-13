<?php

declare(strict_types=1);

namespace RuneCloud\Security;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;

/**
 * Authenticates administrative requests independently of Developer tokens.
 */
final class AdminAuth
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Receives the configured administrative secret without reading configuration.
     * @var string
     */
    private readonly string $token;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the separate administrative credential.
     * @param string $token Configured secret; empty disables administrative publication.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Requires a matching Bearer token using a constant-time comparison.
     * @param Request $request Explicit credentials.
     * @return void
     * @throws HttpException When administration is disabled or credentials do not match.
     */
    public function require(Request $request): void
    {
        if ($this->token === '') {
            throw new HttpException('administration_unavailable', 503);
        }
        
        if (!hash_equals($this->token, $request->bearerToken())) {
            throw new HttpException('unauthorized', 401, ['WWW-Authenticate' => 'Bearer']);
        }
    }
}
