<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuneCloud\Model\Revisions;
use RuntimeException;

/**
 * Checks references in the current library's frozen game metadata.
 */
final class References
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads frozen metadata from the current published revision.
     * @var Revisions
     */
    private readonly Revisions $revisions;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives revision storage on the caller's shared connection.
     * @param Revisions $revisions Revision storage.
     */
    public function __construct(Revisions $revisions)
    {
        $this->revisions = $revisions;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Checks whether a current game's metadata references the tag.
     * @param string $identifier Exact tag identifier.
     * @return bool Whether a frozen base or modifier tag reference exists.
     * @throws \Throwable When frozen metadata is missing, invalid or cannot be read.
     */
    public function usesTag(string $identifier): bool
    {
        foreach ($this->revisions->getCurrentGameFiles() as $file) {
            if (!$this->isGameMetadata($file->path)) {
                continue;
            }
            
            $game = $this->decode($file->content);
            if (array_key_exists($identifier, (array) ($game['tags'] ?? []))
                || array_key_exists($identifier, (array) ($game['baseTags'] ?? []))) {
                return true;
            }
        }
        
        return false;
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Recognizes the canonical games/{identifier}/{identifier}.json path.
     * @param string $path Frozen file path.
     * @return bool Whether the path represents game metadata.
     */
    private function isGameMetadata(string $path): bool
    {
        $parts = explode('/', $path);
        
        return count($parts) === 3 && $parts[0] === 'games' && $parts[2] === $parts[1] . '.json';
    }

    /**
     * Decodes frozen metadata while retaining integrity errors.
     * @param string|null $content Stored metadata bytes.
     * @return array<string, mixed> Decoded metadata.
     * @throws RuntimeException When content is missing or has the wrong shape.
     * @throws \JsonException When stored JSON is invalid.
     */
    private function decode(?string $content): array
    {
        if ($content === null) {
            throw new RuntimeException('Current game metadata content is missing.');
        }
        
        $game = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($game)) {
            throw new RuntimeException('Current game metadata must be an object.');
        }
        
        return $game;
    }
}
