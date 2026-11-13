<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use stdClass;

/**
 * Issues opaque tokens and resolves their developer using stored hashes.
 */
final class ApiTokens
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores token hashes and developer references.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection.
     *
     * @param MySQLi $database Database connection.
     */
    public function __construct(MySQLi $database)
    {
        $this->database = $database;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Creates a token, returning its plaintext once to the issuing caller.
     *
     * @param int $developerId Token owner.
     *
     * @return array{id: int, token: string}
     * @throws \Throwable When secure randomness or database insertion fails.
     */
    public function issue(int $developerId): array
    {
        $token = 'rune_' . bin2hex(random_bytes(32));
        $id = $this->database->insert(
            'INSERT INTO api_tokens (developer_id, token_hash) VALUES (?, ?)',
            [$developerId, hash('sha256', $token)]
        );
        
        return ['id' => $id, 'token' => $token];
    }

    /**
     * Resolves an active token to its developer without exposing stored credentials.
     *
     * @param string $token Plaintext bearer token.
     *
     * @return stdClass|null Developer, or null for invalid or revoked credentials.
     * @throws \mysqli_sql_exception When the query fails.
     */
    public function findDeveloper(string $token): ?stdClass
    {
        if (preg_match('/^rune_[0-9a-f]{64}$/D', $token) !== 1) {
            return null;
        }
        
        return $this->database->fetchOne(
            <<<'SQL'
            SELECT p.id, p.identifier, p.name
            FROM api_tokens AS t
            JOIN developers AS p ON p.id = t.developer_id
            WHERE t.token_hash = ? AND t.revoked_at IS NULL
            SQL,
            [hash('sha256', $token)]
        );
    }

    /**
     * Revokes a token by its database ID without needing its plaintext value.
     *
     * @param int $id Token database ID.
     *
     * @return void
     * @throws \mysqli_sql_exception When the write fails.
     */
    public function revoke(int $id): void
    {
        $this->database->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$id]);
    }

    /**
     * Revokes an owned token, retaining existing revocation timestamps on repeat calls.
     * @param int $id Token ID.
     * @param int $developerId Authenticated owner.
     * @return bool Whether the owned token exists.
     * @throws \Throwable When persistence fails.
     */
    public function revokeOwned(int $id, int $developerId): bool
    {
        return $this->database->transaction(function () use ($id, $developerId): bool {
            $token = $this->database->fetchOne(
                'SELECT id FROM api_tokens WHERE id = ? AND developer_id = ? FOR UPDATE', [$id, $developerId]
            );
            
            if ($token === null) {
                return false;
            }
            
            $this->revoke($id);
            return true;
        });
    }
}
