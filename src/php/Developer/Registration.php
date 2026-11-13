<?php

declare(strict_types=1);

namespace RuneCloud\Developer;

use RuneCloud\Model\ApiTokens;
use RuneCloud\Model\Developers;
use RuneCloud\MySQL\MySQLi;

/**
 * Registers a developer and its first API token atomically.
 */
final class Registration
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Coordinates account and token writes.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Validates and stores developer identities.
     * @var Developers
     */
    private readonly Developers $developers;

    /**
     * Issues hashed credentials.
     * @var ApiTokens
     */
    private readonly ApiTokens $tokens;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives dependencies sharing the same database connection.
     * @param MySQLi $database Shared connection.
     * @param Developers $developers Developer model.
     * @param ApiTokens $tokens Token model.
     */
    public function __construct(MySQLi $database, Developers $developers, ApiTokens $tokens)
    {
        $this->database = $database;
        $this->developers = $developers;
        $this->tokens = $tokens;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Creates an account and returns the initial plaintext token once.
     * Owns its transaction; nested workflows use savepoints on the shared connection.
     * @param string $identifier Public account identifier.
     * @param string $name Display name.
     * @return array<string, mixed>
     * @throws \Throwable When validation or persistence fails; both writes are rolled back.
     */
    public function register(string $identifier, string $name): array
    {
        return $this->database->transaction(function () use ($identifier, $name): array {
            $id = $this->developers->create($identifier, $name);
            $issued = $this->tokens->issue($id);
            return [
                'developer' => ['id' => $id, 'identifier' => $identifier, 'name' => $name],
                'token_id' => $issued['id'],
                'token' => $issued['token'],
            ];
        });
    }
}
