<?php

declare(strict_types=1);

namespace RuneCloud\System;

use RuneCloud\Library\Lock;
use RuneCloud\MySQL\MySQLi;

/**
 * Clears application content atomically while preserving the sorting catalog.
 */
final class Reset
{
    //--------------------------------------------------------------------------
    // Private constants
    //--------------------------------------------------------------------------

    /**
     * Reset scope in foreign-key deletion order. Catalog tables are deliberately excluded.
     * Extend this plan when the reset scope changes; table names never come from requests.
     * @var list<string>
     */
    private const TABLES = [
        'library_revision_files', 'library_revision_releases', 'library_revisions',
        'release_files', 'releases', 'game_versions', 'screensaver_versions',
        'highscores', 'game_tags', 'game_inputs', 'game_modes', 'games', 'screensavers',
        'api_tokens', 'developers', 'file_contents',
    ];

    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Shared transaction connection.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Serializes reset with publication and content deletion.
     * @var Lock
     */
    private readonly Lock $lock;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives database and publication coordination explicitly.
     * @param MySQLi $database Shared connection.
     * @param Lock $lock Publication lock using the same connection.
     */
    public function __construct(MySQLi $database, Lock $lock)
    {
        $this->database = $database;
        $this->lock = $lock;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Clears the reset scope in one transaction without disabling foreign keys.
     * Owns its transaction; failure rolls back every deletion.
     * @return void
     * @throws \Throwable When locking or deletion fails.
     */
    public function run(): void
    {
        $this->lock->run(fn () => $this->database->transaction(fn () => $this->clear()));
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Deletes content in dependency order, leaving schema and ID sequences intact.
     * @return void
     * @throws \mysqli_sql_exception When deletion fails.
     */
    private function clear(): void
    {
        foreach (self::TABLES as $table) {
            $this->database->execute('DELETE FROM `' . $table . '`');
        }
    }
}
