<?php

declare(strict_types=1);

namespace RuneCloud\Screensaver;

use RuneCloud\Http\HttpException;

/**
 * Validates screensaver input without changing stored data.
 */
final class Validation
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Validates screensaver registration metadata.
     * @param array<string, mixed> $data Registration input.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function validate(array $data): void
    {
        $this->identifier($data['id'] ?? null);
        $this->name($data['name'] ?? null);
        unset($data['id']);
        $this->update($data);
    }

    /**
     * Validates submitted editable fields without requiring omitted metadata.
     * @param array<string, mixed> $data Metadata changes.
     * @return void
     * @throws HttpException When a field is invalid or unsupported.
     */
    public function update(array $data): void
    {
        if (array_diff(array_keys($data), ['name', 'author', 'year', 'enabled']) !== []) {
            throw new HttpException('invalid_screensaver_metadata', 422);
        }
        
        if (array_key_exists('name', $data)) {
            $this->name($data['name']);
        }
        
        if (isset($data['author'])) {
            $this->name($data['author']);
        }
        
        $this->year($data['year'] ?? null);
        if (array_key_exists('enabled', $data)) {
            $this->enabled($data['enabled']);
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
        if (array_diff(array_keys($data), ['version']) !== [] || !is_string($version) || strlen($version) > 50
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $version) !== 1) {
            throw new HttpException('invalid_version_metadata', 422);
        }
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks the public identifier.
     * @param mixed $id Submitted identifier.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function identifier(mixed $id): void
    {
        if (!is_string($id) || strlen($id) > 100
            || preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1) {
            throw new HttpException('invalid_screensaver_metadata', 422);
        }
    }

    /**
     * Checks a nonempty display name.
     * @param mixed $name Submitted name.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function name(mixed $name): void
    {
        if (!is_string($name) || trim($name) === ''
            || preg_match('/\A.{1,255}\z/us', $name) !== 1) {
            throw new HttpException('invalid_screensaver_metadata', 422);
        }
    }

    /**
     * Checks the optional release year.
     * @param mixed $year Submitted year or null.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function year(mixed $year): void
    {
        if ($year !== null && (!is_int($year) || $year < 0 || $year > 65535)) {
            throw new HttpException('invalid_screensaver_metadata', 422);
        }
    }

    /**
     * Checks the explicit enabled flag.
     * @param mixed $enabled Submitted flag.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function enabled(mixed $enabled): void
    {
        if (!is_bool($enabled)) {
            throw new HttpException('invalid_screensaver_metadata', 422);
        }
    }

}
