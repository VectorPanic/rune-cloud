<?php

declare(strict_types=1);

namespace RuneCloud\Controller;

use RuneCloud\Http\HttpException;
use RuneCloud\Http\Request;
use RuneCloud\Security\AdminAuth;
use RuneCloud\System\Reset;

/**
 * Requires separate administrative credentials and confirmation before a database reset.
 */
final class ResetController
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Administrative Bearer authentication.
     * @var AdminAuth
     */
    private readonly AdminAuth $authentication;

    /**
     * Atomic reset workflow.
     * @var Reset
     */
    private readonly Reset $reset;

    /**
     * Configured confirmation secret; empty disables reset.
     * @var string
     */
    private readonly string $passcode;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives authentication, reset workflow and confirmation secret explicitly.
     * @param AdminAuth $authentication Administrative authentication.
     * @param Reset $reset Reset workflow.
     * @param string $passcode Confirmation secret supplied by configuration.
     */
    public function __construct(AdminAuth $authentication, Reset $reset, string $passcode)
    {
        $this->authentication = $authentication;
        $this->reset = $reset;
        $this->passcode = $passcode;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Authenticates and confirms reset before changing any stored data.
     * @param Request $request Administrative JSON request.
     * @return array{reset: bool, preserved: list<string>} Completed reset response.
     * @throws \Throwable When credentials, confirmation or reset fail.
     */
    public function reset(Request $request): array
    {
        $this->authentication->require($request);
        $this->requirePasscode($request->json());
        $this->reset->run();

        return ['reset' => true, 'preserved' => ['categories', 'tags']];
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Accepts only a matching passcode and rejects unsupported reset options.
     * @param array<string, mixed> $data Submitted confirmation.
     * @return void
     * @throws HttpException When reset is disabled or confirmation is invalid.
     */
    private function requirePasscode(array $data): void
    {
        if ($this->passcode === '') {
            throw new HttpException('reset_unavailable', 503);
        }
        
        if (array_diff(array_keys($data), ['passcode']) !== [] || !is_string($data['passcode'] ?? null)) {
            throw new HttpException('invalid_reset_request', 422);
        }
        
        if (!hash_equals($this->passcode, $data['passcode'])) {
            throw new HttpException('invalid_passcode', 403);
        }
    }
}
