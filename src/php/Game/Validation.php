<?php

declare(strict_types=1);

namespace RuneCloud\Game;

use RuneCloud\Http\HttpException;
use stdClass;

/**
 * Validates game input without changing stored data.
 */
final class Validation
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Validates metadata for a new game.
     * @param array<string, mixed> $data Registration metadata.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function validate(array $data): void
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || strlen($id) > 100 || preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1) {
            throw new HttpException('invalid_game_id', 422);
        }
        unset($data['id']);
        $this->update($data);
        $this->name($data['name'] ?? null);
    }

    /**
     * Validates only submitted editable metadata, preserving omitted values.
     * @param array<string, mixed> $data Metadata changes.
     * @return void
     * @throws HttpException When a submitted field is invalid or unsupported.
     */
    public function update(array $data): void
    {
        if (array_diff(array_keys($data), ['name', 'description', 'year', 'input', 'players', 'baseTags', 'tags', 'modes']) !== []) {
            throw new HttpException('unknown_game_field', 422);
        }
        if (array_key_exists('name', $data)) {
            $this->name($data['name']);
        }
        if (array_key_exists('description', $data)
            && (!is_string($data['description']) || strlen($data['description']) > 65535)) {
            throw new HttpException('invalid_game_description', 422);
        }
        $year = $data['year'] ?? null;
        if ($year !== null && (!is_int($year) || $year < 0 || $year > 65535)) {
            throw new HttpException('invalid_game_year', 422);
        }
        if (array_key_exists('players', $data)) {
            $this->players($data['players']);
        }
        if (array_key_exists('input', $data)) {
            $this->inputs($data['input']);
        }
        foreach (['baseTags', 'tags'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->tags($data[$field]);
            }
        }
        if (array_key_exists('modes', $data)) {
            $this->modes($data['modes']);
        }
    }

    /**
     * Validates player counts for creation and editing.
     * @param mixed $players Submitted player object.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function players(mixed $players): void
    {
        if (!$players instanceof stdClass || array_diff(array_keys((array) $players), ['min', 'max']) !== []) {
            throw new HttpException('invalid_game_players', 422);
        }
        
        $minimumPlayers = $players->min ?? null;
        $maximumPlayers = $players->max ?? null;
        
        if (!is_int($minimumPlayers) || !is_int($maximumPlayers)
            || $minimumPlayers < 1 || $maximumPlayers > 255 || $minimumPlayers > $maximumPlayers
        ) {
            throw new HttpException('invalid_game_players', 422);
        }
    }

    /**
     * Validates supported game input methods.
     * @param mixed $inputs Submitted inputs.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function inputs(mixed $inputs): void
    {
        if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []
            || count(array_filter($inputs, 'is_string')) !== count($inputs)
            || count(array_unique($inputs, SORT_REGULAR)) !== count($inputs)
            || array_diff($inputs, ['keyboard', 'gamepad']) !== []
        ) {
            throw new HttpException('invalid_game_input', 422);
        }
    }

    /**
     * Validates tag object shape and strengths.
     * @param mixed $tags Submitted tag strengths.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function tags(mixed $tags): void
    {
        if (!$tags instanceof stdClass) {
            throw new HttpException('invalid_game_tags', 422);
        }
        foreach ((array) $tags as $strength) {
            if ((!is_int($strength) && !is_float($strength)) || !is_finite((float) $strength)
                || $strength < 0 || $strength > 100) {
                throw new HttpException('invalid_game_tags', 422);
            }
        }
    }

    /**
     * Validates optional cooperative and versus flags.
     * @param mixed $modes Submitted mode flags.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function modes(mixed $modes): void
    {
        if (!$modes instanceof stdClass || array_diff(array_keys((array) $modes), ['coop', 'versus']) !== []) {
            throw new HttpException('invalid_game_modes', 422);
        }
        foreach ((array) $modes as $enabled) {
            if (!is_bool($enabled)) {
                throw new HttpException('invalid_game_modes', 422);
            }
        }
    }

    /**
     * Validates metadata for a new draft version.
     * @param array<string, mixed> $data Version input.
     * @return void
     * @throws HttpException When version metadata is invalid.
     */
    public function version(array $data): void
    {
        $version = $data['version'] ?? null;
        $stage = $data['stage'] ?? 'alpha';
        if (array_diff(array_keys($data), ['version', 'stage']) !== []
            || !is_string($version) || strlen($version) > 50
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $version) !== 1
            || !in_array($stage, ['alpha', 'beta', 'gold'], true)
        ) {
            throw new HttpException('invalid_version_metadata', 422);
        }
    }
    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Requires a nonblank game title.
     * @param mixed $name Submitted name.
     * @return void
     * @throws HttpException When the name is invalid.
     */
    private function name(mixed $name): void
    {
        if (!is_string($name) || trim($name) === '' || preg_match('/\A.{1,255}\z/us', $name) !== 1) {
            throw new HttpException('invalid_game_name', 422);
        }
    }

}
