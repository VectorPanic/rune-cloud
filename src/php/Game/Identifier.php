<?php

declare(strict_types=1);

namespace RuneCloud\Game;

/** 
 * Validates SDK application identifiers used as public game identifiers. 
 */
final class Identifier
{
    /**
     * Checks the SDK format and the database's identifier length limit.
     * @param mixed $value Candidate identifier.
     * @return bool Whether the identifier can be registered.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 100
            && preg_match('/^[a-z]{2,3}\.[a-z0-9-]{1,50}\.[a-zA-Z0-9_-]+$/D', $value) === 1;
    }
}
