<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\Tag\Validation;
use RuneCloud\Library\Lock;
use RuneCloud\Library\References;
use RuneCloud\Http\HttpException;
use RuneCloud\MySQL\MySQLi;
use stdClass;

/**
 * Stores the tag dictionary while protecting live and current frozen references.
 */
final class Tags
{
    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Stores tags and reads their live references.
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Resolves the current frozen game metadata before destructive changes.
     * @var References
     */
    private readonly References $references;

    /**
     * Validates submitted metadata.
     * @var Validation
     */
    private readonly Validation $validation;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives explicitly composed persistence dependencies.
     * @param MySQLi $database Shared connection.
     * @param References $references Current library reference checks.
     * @param Validation $validation Metadata rules.
     */
    public function __construct(MySQLi $database, References $references, Validation $validation)
    {
        $this->validation = $validation;
        $this->database = $database;
        $this->references = $references;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns the dictionary in the local library document format.
     * @return array{tags: list<array<string, mixed>>} Tag document.
     * @throws \Throwable When lookup fails.
     */
    public function all(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT t.identifier AS id, t.name, t.kind, tg.identifier AS tagGroup, t.description
                FROM tags t LEFT JOIN tag_groups tg ON tg.id=t.group_id
                ORDER BY t.identifier COLLATE utf8mb4_bin'
        );
        return ['tags' => array_map(fn ($row): array => $this->format($row), $rows)];
    }

    /**
     * Returns one editable tag, or null when missing.
     * @param string $identifier Exact public identifier.
     * @return array<string, mixed>|null Tag document.
     * @throws \Throwable When lookup fails.
     */
    public function find(string $identifier): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT t.identifier AS id, t.name, t.kind, tg.identifier AS tagGroup, t.description
                FROM tags t LEFT JOIN tag_groups tg ON tg.id=t.group_id WHERE BINARY t.identifier=BINARY ?', [$identifier]
        );
        return $row === null ? null : $this->format($row);
    }

    /**
     * Creates a dictionary entry without changing frozen library revisions.
     * @param array<string, mixed> $data Tag metadata.
     * @return array<string, mixed> Created tag.
     * @throws \Throwable When validation or persistence fails.
     */
    public function create(array $data): array
    {
        $this->validation->validate($data);
        return $this->change(function () use ($data): array {
            $groupId = $this->requireGroupId($data['group'] ?? null);
            $this->database->insert('INSERT INTO tags (identifier,name,kind,group_id,description) VALUES (?,?,?,?,?)',
                [$data['id'],$data['name'],$data['kind'],$groupId,$data['description'] ?? null]);
            return $this->find($data['id']);
        });
    }

    /**
     * Replaces tag metadata and refuses a kind change while references exist.
     * @param string $identifier Tag identifier from the route.
     * @param array<string, mixed> $data Complete replacement document.
     * @return array<string, mixed> Updated tag.
     * @throws \Throwable When lookup, validation or writes fail.
     */
    public function update(string $identifier, array $data): array
    {
        if (array_key_exists('id', $data) && $data['id'] !== $identifier) {
            throw new HttpException('tag_id_mismatch', 422);
        }
        $data['id'] = $identifier;
        $this->validation->validate($data);
        return $this->change(function () use ($identifier, $data): array {
            $tag = $this->require($identifier);
            if ($tag->kind !== $data['kind']) {
                $this->requireUnused($tag);
            }
            $groupId = $this->requireGroupId($data['group'] ?? null);
            $this->database->execute('UPDATE tags SET name=?,kind=?,group_id=?,description=? WHERE id=?',
                [$data['name'],$data['kind'],$groupId,$data['description'] ?? null,(int) $tag->id]);
            return $this->find($identifier);
        });
    }

    /**
     * Deletes a tag only after every live/current game reference is removed.
     * @param string $identifier Exact tag identifier.
     * @return void
     * @throws \Throwable When missing, referenced or deletion fails.
     */
    public function delete(string $identifier): void
    {
        $this->change(function () use ($identifier): void {
            $tag = $this->require($identifier);
            $this->requireUnused($tag);
            $this->database->execute('DELETE FROM tags WHERE id=?', [(int) $tag->id]);
        });
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Locks a tag before replacing or deleting it.
     * @param string $identifier Exact identifier.
     * @return stdClass Locked tag with internal ID and current kind.
     * @throws HttpException When missing.
     */
    private function require(string $identifier): stdClass
    {
        return $this->database->fetchOne('SELECT id,identifier,kind FROM tags WHERE BINARY identifier=BINARY ? FOR UPDATE', [$identifier])
            ?? throw new HttpException('not_found', 404);
    }

    /**
     * Resolves an existing modifier group without creating groups implicitly.
     * @param string|null $identifier Optional group identifier.
     * @return int|null Group ID.
     * @throws HttpException When the group is unknown.
     */
    private function requireGroupId(?string $identifier): ?int
    {
        if ($identifier === null) {
            return null;
        }
        $group = $this->database->fetchOne('SELECT id FROM tag_groups WHERE BINARY identifier=BINARY ? FOR SHARE', [$identifier]);
        if ($group === null) {
            throw new HttpException('unknown_tag_group', 422);
        }
        return (int) $group->id;
    }

    /**
     * Protects game strengths, category rules and current frozen game metadata.
     * @param stdClass $tag Locked tag.
     * @return void
     * @throws HttpException When a live or current frozen reference exists.
     */
    private function requireUnused(stdClass $tag): void
    {
        foreach (['game_tags','category_rule_tags','category_ranking_tags'] as $table) {
            if ($this->database->fetchOne("SELECT tag_id FROM $table WHERE tag_id=? LIMIT 1 FOR SHARE", [(int) $tag->id]) !== null) {
                throw new HttpException('tag_in_use', 409);
            }
        }
        if ($this->references->usesTag($tag->identifier)) {
            throw new HttpException('tag_in_use', 409);
        }
    }

    /**
     * Serializes dictionary changes with publication and commits writes atomically.
     * @param callable(): mixed $operation Tag operation.
     * @return mixed Operation result.
     * @throws \Throwable When locking or persistence fails.
     */
    private function change(callable $operation): mixed
    {
        return (new Lock($this->database))->run(
            fn (): mixed => $this->database->transaction($operation)
        );
    }

    /**
     * Maps database fields to the public tag format.
     * @param stdClass $row Joined tag/group row.
     * @return array<string, mixed> Public tag document.
     */
    private function format(stdClass $row): array
    {
        $tag = ['id'=>$row->id, 'name'=>$row->name, 'kind'=>$row->kind];
        if ($row->kind === 'modifier' && $row->tagGroup !== null) {
            $tag['group'] = $row->tagGroup;
        }
        if ($row->description !== null) {
            $tag['description'] = $row->description;
        }
        return $tag;
    }
}
