<?php

declare(strict_types=1);

namespace RuneCloud\Library;

/**
 * Carries the locked parent, version and release selected for publication.
 * The version handler verifies ownership inside the caller's transaction.
 */
final class Selection
{
    //--------------------------------------------------------------------------
    // Public properties
    //--------------------------------------------------------------------------

    /**
     * Database ID of the game or screensaver.
     * @var int
     */
    public readonly int $parentId;

    /**
     * Database ID of the selected version.
     * @var int
     */
    public readonly int $versionId;

    /**
     * Database ID of the version's single release.
     * @var int
     */
    public readonly int $releaseId;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives IDs resolved by the owning version handler.
     * @param int $parentId Game or screensaver database ID.
     * @param int $versionId Selected version database ID.
     * @param int $releaseId Selected release database ID.
     */
    public function __construct(int $parentId, int $versionId, int $releaseId)
    {
        $this->parentId = $parentId;
        $this->versionId = $versionId;
        $this->releaseId = $releaseId;
    }
}
