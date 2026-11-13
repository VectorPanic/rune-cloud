<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Http\HttpException;
use RuneCloud\MySQL\MySQLi;

/**
 * Serializes operations that change the published library.
 */
final class Lock
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Connection owning the MySQL advisory lock.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the same connection used by the protected operation.
     * @param MySQLi $database Shared database connection.
     */
    public function __construct(MySQLi $database)
    {
        $this->database = $database;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Runs an operation and releases its lock even when the operation fails.
     * MySQL counts nested acquisitions on the same connection; each run releases
     * only its own acquisition. Transaction boundaries belong to the caller.
     * @template T
     * @param callable(): T $operation Protected operation.
     * @return T Operation result.
     * @throws HttpException When the lock cannot be acquired within ten seconds.
     * @throws \Throwable When the operation fails.
     */
    public function run(callable $operation): mixed
    {
        $lock = $this->database->fetchOne(
            "SELECT GET_LOCK(CONCAT(DATABASE(), ':library-publication'),10) AS acquired"
        );
        
        if ((int) ($lock->acquired ?? 0) !== 1) {
            throw new HttpException('publication_busy', 503);
        }

        try {
            return $operation();
        } finally {
            $this->database->fetchOne("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':library-publication')) AS released");
        }
    }
}
