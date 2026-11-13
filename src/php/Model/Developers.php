<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use RuneCloud\Http\HttpException;
use stdClass;

/**
 * Registers developers independently of personal or studio account workflows.
 */
final class Developers
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores developer records.
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
     * Creates a developer and returns its database ID.
     *
     * @param string $identifier Stable public developer identifier.
     * @param string $name Display name for a person or studio.
     *
     * @return int
     * @throws HttpException When input is invalid.
     * @throws \mysqli_sql_exception When the identifier already exists or a write fails.
     */
    public function create(string $identifier, string $name): int
    {
        if (strlen($identifier) > 100 || preg_match('/^[a-z][a-z0-9-]*$/D', $identifier) !== 1) {
            throw new HttpException('invalid_developer_metadata', 422);
        }
        
        if (trim($name) === '' || preg_match('/\A.{1,255}\z/us', $name) !== 1) {
            throw new HttpException('invalid_developer_metadata', 422);
        }
        
        return $this->database->insert(
            'INSERT INTO developers (identifier, name) VALUES (?, ?)',
            [$identifier, $name]
        );
    }

    /**
     * Returns an account by its permanent public identifier.
     *
     * @param string $identifier Developer identifier.
     *
     * @return stdClass|null Account, or null when missing.
     * @throws \mysqli_sql_exception When the query fails.
     */
    public function find(string $identifier): ?stdClass
    {
        return $this->database->fetchOne(
            'SELECT id, identifier, name, created_at FROM developers WHERE BINARY identifier = BINARY ?',
            [$identifier]
        );
    }

    /**
     * Deletes an empty developer and all its tokens in one transaction.
     *
     * Locks the developer before checking games, preventing new foreign-key
     * references while deletion is being prepared. Owned games are never deleted.
     *
     * @param string $identifier Exact public developer identifier.
     *
     * @return bool Whether a developer was found and deleted.
     * @throws HttpException When the identifier is invalid.
     * @throws HttpException When the developer owns content.
     * @throws \Throwable When database operations fail; token deletion is rolled back.
     */
    public function delete(string $identifier): bool
    {
        if (strlen($identifier) > 100 || preg_match('/^[a-z][a-z0-9-]*$/D', $identifier) !== 1) {
            throw new HttpException('invalid_developer_metadata', 422);
        }
        
        return $this->database->transaction(function () use ($identifier): bool {
            $developer = $this->database->fetchOne(
                'SELECT id FROM developers WHERE BINARY identifier = BINARY ? FOR UPDATE',
                [$identifier]
            );

            if ($developer === null) {
                return false;
            }

            $id = (int) $developer->id;
            $games = $this->database->fetchAll(
                'SELECT id FROM games WHERE developer_id = ? LIMIT 1 FOR UPDATE',
                [$id]
            );

            $savers = $this->database->fetchOne('SELECT id FROM screensavers WHERE developer_id=? LIMIT 1 FOR UPDATE', [$id]);
            if ($games !== []) {
                throw new HttpException('developer_has_content', 409);
            }
            if ($savers !== null) {
                throw new HttpException('developer_has_content', 409);
            }

            $this->database->execute('DELETE FROM api_tokens WHERE developer_id = ?', [$id]);
            $this->database->execute('DELETE FROM developers WHERE id = ?', [$id]);

            return true;
        });
    }
}
