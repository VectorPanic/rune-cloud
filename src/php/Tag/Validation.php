<?php

declare(strict_types=1);

namespace RuneCloud\Tag;

use RuneCloud\Http\HttpException;

/**
 * Validates tag input without changing stored data.
 */
final class Validation
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Validates tag identity, kind and optional metadata.
     * @param array<string, mixed> $data Tag input.
     * @return void
     * @throws HttpException When submitted metadata is invalid.
     */
    public function validate(array $data): void
    {
        $this->fields($data);
        $this->identifier($data['id'] ?? null);
        $this->name($data['name'] ?? null);
        $this->kind($data['kind'] ?? null);
        $this->description($data['description'] ?? null);
        $this->group($data['group'] ?? null, $data['kind']);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Rejects unsupported registration fields.
     * @param array<string, mixed> $data Submitted fields.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function fields(array $data): void
    {
        if (array_diff(array_keys($data), ['id', 'name', 'kind', 'group', 'description']) !== []) {
            throw new HttpException('invalid_tag_metadata', 422);
        }
    }

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
            throw new HttpException('invalid_tag_metadata', 422);
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
            || preg_match('/\A.{1,100}\z/us', $name) !== 1) {
            throw new HttpException('invalid_tag_metadata', 422);
        }
    }

    /**
     * Checks the supported tag kind.
     * @param mixed $kind Submitted kind.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function kind(mixed $kind): void
    {
        if (!in_array($kind, ['base', 'modifier'], true)) {
            throw new HttpException('invalid_tag_metadata', 422);
        }
    }

    /**
     * Checks the optional description.
     * @param mixed $description Submitted description or null.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function description(mixed $description): void
    {
        if ($description !== null && (!is_string($description) || strlen($description) > 65535)) {
            throw new HttpException('invalid_tag_metadata', 422);
        }
    }

    /**
     * Checks that an optional group belongs to a modifier tag.
     * @param mixed $group Submitted group or null.
     * @param string $kind Validated tag kind.
     * @return void
     * @throws HttpException When the value is invalid.
     */
    private function group(mixed $group, string $kind): void
    {
        if ($group !== null && (!is_string($group) || strlen($group) > 50
            || preg_match('/^[a-z][a-z0-9-]*$/D', $group) !== 1 || $kind !== 'modifier')) {
            throw new HttpException('invalid_tag_metadata', 422);
        }
    }
}
