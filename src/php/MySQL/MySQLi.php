<?php

declare(strict_types=1);

namespace RuneCloud\MySQL;

use mysqli as Connection;
use mysqli_sql_exception;
use mysqli_result;
use RuntimeException;
use stdClass;

/**
 * Provides a database connection using configuration supplied by the caller.
 */
final class MySQLi
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Holds the database connection.
     *
     * @var Connection
     */
    private Connection $connection;

    /**
     * Counts active transaction scopes so composed operations use savepoints.
     * @var int
     */
    private int $transactionDepth = 0;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Opens a database connection using the supplied configuration.
     *
     * @param string $host Database hostname.
     * @param string $username Database username.
     * @param string $password Database password.
     * @param string $database Database name.
     * @param int $port Database port.
     *
     * @throws mysqli_sql_exception When connection or charset initialization fails.
     */
    public function __construct(
        string $host,
        string $username,
        string $password,
        string $database,
        int $port = 3306
    )
    {
        $this->connection = new Connection($host, $username, $password, $database, $port);
        
        if ($this->connection->connect_errno !== 0) {
            throw new mysqli_sql_exception($this->connection->connect_error, $this->connection->connect_errno);
        }
        
        if (!$this->connection->set_charset('utf8mb4')) {
            throw new mysqli_sql_exception($this->connection->error, $this->connection->errno);
        }
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns all rows from a prepared query as objects.
     *
     * @param string $sql SQL query with placeholders for parameter values.
     * @param list<string|int|float|null> $parameters Values bound to the placeholders.
     *
     * @return list<stdClass>
     * @throws mysqli_sql_exception When query execution fails.
     * @throws RuntimeException When the query fails or does not return rows.
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $result = $this->connection->execute_query($sql, $parameters);
        
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('The query did not return a result set.');
        }
        
        try {
            $rows = [];
            
            while (($row = $result->fetch_object()) !== null) {
                if ($row === false) {
                    throw new RuntimeException('Unable to fetch a result row.');
                }
                
                $rows[] = $row;
            }
            
            return $rows;
        } finally {
            $result->free();
        }
    }

    /**
     * Returns the first row as an object, or null when the query finds no rows.
     *
     * @param string $sql SQL query with placeholders; use LIMIT 1 when appropriate.
     * @param list<string|int|float|null> $parameters Bound values.
     * @return stdClass|null First result row, or null when missing.
     * @throws mysqli_sql_exception When query execution fails.
     * @throws RuntimeException When the statement does not return rows or fetching fails.
     */
    public function fetchOne(string $sql, array $parameters = []): ?stdClass
    {
        $result = $this->connection->execute_query($sql, $parameters);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('The query did not return a result set.');
        }
        
        try {
            $row = $result->fetch_object();
            if ($row === false) {
                throw new RuntimeException('Unable to fetch a result row.');
            }
            
            return $row;
        } finally {
            $result->free();
        }
    }

    /**
     * Executes a prepared write and returns the number of affected rows.
     *
     * @param string $sql SQL write statement.
     * @param list<string|int|float|null> $parameters Bound values.
     *
     * @return int
     * @throws mysqli_sql_exception When execution fails.
     * @throws RuntimeException When the statement does not produce a write result.
     */
    public function execute(string $sql, array $parameters = []): int
    {
        $result = $this->connection->execute_query($sql, $parameters);
        
        if ($result !== true) {
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            
            throw new RuntimeException('Expected a successful write statement.');
        }

        return $this->connection->affected_rows;
    }

    /**
     * Inserts a record and returns its generated ID.
     *
     * @param string $sql SQL insert statement.
     * @param list<string|int|float|null> $parameters Bound values.
     *
     * @return int
     * @throws mysqli_sql_exception When execution fails.
     * @throws RuntimeException When the insert fails or does not generate an ID.
     */
    public function insert(string $sql, array $parameters = []): int
    {
        $this->execute($sql, $parameters);
        $id = (int) $this->connection->insert_id;
        
        if ($id === 0) {
            throw new RuntimeException('The insert did not generate an ID.');
        }
        
        return $id;
    }

    /**
     * Inserts binary file bytes through a prepared BLOB parameter in bounded chunks.
     *
     * @param string $sql Insert statement with one BLOB placeholder.
     * @param string $path PHP-managed temporary upload path.
     * @return int Generated content ID.
     * @throws \Throwable When file reading or insertion fails.
     */
    public function insertFile(string $sql, string $path): int
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read uploaded bytes.');
        }
        
        $statement = null;
        
        try {
            $statement = $this->connection->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare binary insertion.');
            }
            
            $value = null;
            $statement->bind_param('b', $value);
            while (!feof($stream)) {
                $bytes = fread($stream, 1024 * 1024);
                if ($bytes === false || !$statement->send_long_data(0, $bytes)) {
                    throw new RuntimeException('Unable to send uploaded bytes.');
                }
            }
            
            if (!$statement->execute()) {
                throw new RuntimeException('Unable to store uploaded bytes.');
            }
            
            return (int) $this->connection->insert_id;
        } finally {
            $statement?->close();
            fclose($stream);
        }
    }

    /**
     * Rejects a storage step outside a transaction managed by this connection.
     * @return void
     * @throws \LogicException When no transaction scope is active.
     */
    public function requireTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            throw new \LogicException('This storage operation requires an active transaction on the same connection.');
        }
    }

    /**
     * Commits related writes together, rolling back when the operation fails.
     * Nested calls use savepoints and never commit the outer transaction.
     *
     * @template T
     * @param callable(): T $operation Database operation using this connection.
     *
     * @return T
     * @throws \Throwable When the operation or transaction fails.
     */
    public function transaction(callable $operation): mixed
    {
        $level = $this->transactionDepth;
        $savepoint = 'rune_transaction_' . $level;
        if ($level === 0) {
            if (!$this->connection->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
                throw new RuntimeException('Unable to start database transaction.');
            }
        } else {
            $this->connection->query('SAVEPOINT ' . $savepoint);
        }
        
        $this->transactionDepth++;
        
        try {
            $result = $operation();
            if ($level === 0) {
                if (!$this->connection->commit()) {
                    throw new RuntimeException('Unable to commit database transaction.');
                }
            } else {
                $this->connection->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            
            return $result;
        } catch (\Throwable $error) {
            if ($level === 0) {
                $this->connection->rollback();
            } else {
                $this->connection->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->connection->query('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $error;
        } finally {
            $this->transactionDepth--;
        }
    }
}
