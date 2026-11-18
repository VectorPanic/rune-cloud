<?php

declare(strict_types=1);

namespace RuneCloud\Highscore;

use RuneCloud\Http\HttpException;
use stdClass;

/** Validates and normalizes SDK highscore tables before any writes. */
final class Validation
{
    /**
     * @param mixed $tables SDK highscore tables.
     * @return list<list<array{name:string,score:float,date:float}>> Validated entries.
     * @throws HttpException When input is invalid or exceeds the sync limits.
     */
    public function tables(mixed $tables): array
    {
        if (!is_array($tables) || !array_is_list($tables) || count($tables) < 1 || count($tables) > 32) {
            throw new HttpException('invalid_highscore_tables', 422);
        }
        $result = [];
        $total = 0;
        foreach ($tables as $table) {
            if (!is_array($table) || !array_is_list($table) || count($table) < 1 || count($table) > 100) {
                throw new HttpException('invalid_highscore_tables', 422);
            }
            $total += count($table);
            if ($total > 1000) throw new HttpException('invalid_highscore_tables', 422);
            $result[] = array_map($this->entry(...), $table);
        }
        return $result;
    }

    /**
     * @param mixed $entry Individual SDK highscore entry.
     * @return array{name:string,score:float,date:float} Normalized entry.
     * @throws HttpException When a value is invalid.
     */
    private function entry(mixed $entry): array
    {
        if ($entry instanceof stdClass) $entry = (array) $entry;
        if (!is_array($entry) || array_diff(array_keys($entry), ['name', 'score', 'date']) !== []
            || !isset($entry['name'], $entry['score'], $entry['date'])
            || !is_string($entry['name']) || strlen($entry['name']) > 1020
            || preg_match('/\A.{0,255}\z/us', $entry['name']) !== 1
            || !$this->number($entry['score']) || !$this->number($entry['date'])
            || $entry['score'] < 0 || $entry['score'] > 9007199254740991
            || $entry['date'] < 0 || $entry['date'] > 9007199254740991) {
            throw new HttpException('invalid_highscore_entry', 422);
        }
        return ['name' => $entry['name'], 'score' => (float) $entry['score'], 'date' => (float) $entry['date']];
    }

    /**
     * Checks whether a value is a finite JSON number.
     * @param mixed $value Candidate number.
     * @return bool Whether the number is finite.
     */
    private function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
