<?php

declare(strict_types=1);

namespace RuneCloud\Model;

use RuneCloud\MySQL\MySQLi;
use RuntimeException;
use stdClass;
use RuneCloud\Category\Validation;
use RuneCloud\Http\HttpException;

/**
 * Reads categories and their rules in the local library document format.
 */
final class Categories
{
    //--------------------------------------------------------------------------
    // Private constants
    //--------------------------------------------------------------------------

    /**
     * Selects category metadata and its ranking weights.
     * @var string
     */
    private const SELECT_SQL = <<<'SQL'
        SELECT
            c.id AS categoryId,
            c.identifier AS id,
            c.name,
            c.description,
            c.sort_order AS sortOrder,
            c.display_limit AS displayLimit,
            r.base_weight AS baseWeight,
            r.modifier_weight AS modifierWeight,
            r.player_weight AS playerWeight
        FROM categories AS c
        LEFT JOIN category_ranking AS r ON r.category_id = c.id
        SQL;

    //--------------------------------------------------------------------------
    // Private properties
    //--------------------------------------------------------------------------

    /**
     * Reads category records from the database.
     *
     * @var MySQLi
     */
    private readonly MySQLi $database;

    /**
     * Checks category input independently of storage.
     * @var Validation
     */
    private readonly Validation $validation;

    //--------------------------------------------------------------------------
    // Constructor
    //--------------------------------------------------------------------------

    /**
     * Receives the database connection from the caller.
     *
     * @param MySQLi $database Database connection shared by models.
     * @param Validation $validation Category validation.
     */
    public function __construct(MySQLi $database, Validation $validation)
    {
        $this->database = $database;
        $this->validation = $validation;
    }

    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Returns categories with eligibility rules, ranking and optional display limits.
     *
     * @return array{categories: list<array<string, mixed>>}
     * @throws \mysqli_sql_exception When a database query fails.
     * @throws RuntimeException When a category has missing ranking or unsupported rules.
     */
    public function all(): array
    {
        $rows = $this->database->fetchAll(self::SELECT_SQL . ' ORDER BY c.sort_order, c.id');
        
        return ['categories' => $this->complete($rows)];
    }

    /**
     * Returns one category with its editable sort order.
     * @param string $identifier Public identifier.
     * @return array<string, mixed>|null Category or null.
     * @throws \Throwable When lookup fails.
     */
    public function find(string $identifier): ?array
    {
        $row = $this->database->fetchOne(
            self::SELECT_SQL . ' WHERE BINARY c.identifier = BINARY ?', [$identifier]
        );
        if ($row === null) {
            return null;
        }
        
        return $this->complete([$row])[0] + ['sortOrder' => (int) $row->sortOrder];
    }

    /**
     * Creates a complete category and rule graph without changing frozen revisions.
     * @param array<string, mixed> $data Complete category input.
     * @return array<string, mixed> Created category.
     * @throws \Throwable When validation or storage fails; all writes roll back.
     */
    public function create(array $data): array
    {
        return $this->database->transaction(function () use ($data): array {
            $tags = $this->loadTags();
            $this->validation->validate($data, array_map(fn ($tag): string => $tag['kind'], $tags));
            $id = $this->database->insert('INSERT INTO categories (identifier,name,description,sort_order,display_limit) VALUES (?,?,?,?,?)',
                [$data['id'],$data['name'],$data['description'] ?? null,$data['sortOrder'] ?? 0,$data['limit'] ?? null]);
            $this->storeRules($id, $data, $tags);
            return $this->find($data['id']);
        });
    }

    /**
     * Replaces editable metadata and all rules while retaining the identifier.
     * @param string $identifier Category identifier from the route.
     * @param array<string, mixed> $data Complete replacement document.
     * @return array<string, mixed> Updated category.
     * @throws \Throwable When lookup, validation or writes fail.
     */
    public function update(string $identifier, array $data): array
    {
        return $this->database->transaction(function () use ($identifier, $data): array {
            $id = $this->require($identifier);
            if (array_key_exists('id', $data) && $data['id'] !== $identifier) {
                throw new HttpException('category_id_mismatch', 422);
            }
            $data['id'] = $identifier;
            $tags = $this->loadTags();
            $this->validation->validate($data, array_map(fn ($tag): string => $tag['kind'], $tags));
            $this->database->execute('UPDATE categories SET name=?,description=?,sort_order=?,display_limit=? WHERE id=?',
                [$data['name'],$data['description'] ?? null,$data['sortOrder'] ?? 0,$data['limit'] ?? null,$id]);
            $this->removeRules($id);
            $this->storeRules($id, $data, $tags);
            return $this->find($identifier);
        });
    }

    /**
     * Removes a live category and all its rules without rewriting frozen JSON.
     * @param string $identifier Category identifier.
     * @return void
     * @throws \Throwable When lookup or deletion fails.
     */
    public function delete(string $identifier): void
    {
        $this->database->transaction(function () use ($identifier): void {
            $id = $this->require($identifier);
            $this->removeRules($id);
            $this->database->execute('DELETE FROM categories WHERE id=?', [$id]);
        });
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Assembles rules only for the selected category rows.
     * @param list<stdClass> $rows Selected category metadata.
     * @return list<array<string, mixed>> Complete category documents.
     * @throws \Throwable When rules are invalid or cannot be read.
     */
    private function complete(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            $categories[$row->categoryId] = $this->format($row);
        }
        if ($categories === []) {
            return [];
        }
        
        $this->addEligibility($categories);
        $this->addRankingTags($categories);
        $this->addPlayerScores($categories);
        
        return array_values($categories);
    }

    /**
     * Formats metadata and ranking weights from one database row.
     * @param stdClass $row Category metadata.
     * @return array<string, mixed> Base category document.
     * @throws RuntimeException When ranking is missing.
     */
    private function format(stdClass $row): array
    {
        if ($row->baseWeight === null) {
            throw new RuntimeException('Missing ranking for category: ' . $row->id);
        }

        $category = [
            'id' => $row->id,
            'name' => $row->name,
            'eligibility' => (object) [],
            'ranking' => [
                'baseWeight' => (int) $row->baseWeight,
                'modifierWeight' => (int) $row->modifierWeight,
                'playerWeight' => (int) $row->playerWeight,
            ],
        ];
        
        if ($row->description !== null) {
            $category['description'] = $row->description;
        }
        
        if ($row->displayLimit !== null) {
            $category['limit'] = (int) $row->displayLimit;
        }
        
        return $category;
    }

    /**
     * Locks a category before replacement or deletion.
     * @param string $identifier Exact category identifier.
     * @return int Category ID.
     * @throws HttpException When missing.
     */
    private function require(string $identifier): int
    {
        $row = $this->database->fetchOne('SELECT id FROM categories WHERE BINARY identifier=BINARY ? FOR UPDATE', [$identifier]);
        if ($row === null) {
            throw new HttpException('not_found', 404);
        }
        return (int) $row->id;
    }

    /**
     * Loads identifiers, database IDs and kinds for rule validation and storage.
     * @return array<string, array{id: int, kind: string}> Tag dictionary.
     * @throws \Throwable When the dictionary cannot be read.
     */
    private function loadTags(): array
    {
        $tags = [];
        foreach ($this->database->fetchAll('SELECT id,identifier,kind FROM tags FOR SHARE') as $tag) {
            $tags[$tag->identifier] = ['id' => (int) $tag->id, 'kind' => $tag->kind];
        }
        return $tags;
    }

    /**
     * Stores eligibility and ranking as one category rule graph.
     * @param int $id Category ID.
     * @param array<string, mixed> $data Validated category.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \Throwable When storage fails.
     */
    private function storeRules(int $id, array $data, array $tags): void
    {
        $this->storeEligibility($id, (array) ($data['eligibility'] ?? new \stdClass()), $tags);
        $this->storeRanking($id, (array) $data['ranking'], $tags);
    }

    /**
     * Stores eligibility predicates and their referenced tags.
     * @param int $id Category ID.
     * @param array<string, mixed> $rules Validated eligibility.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \Throwable When storage fails.
     */
    private function storeEligibility(int $id, array $rules, array $tags): void
    {
        $this->storeTagRules($id, $rules, $tags);
        $this->storeMode($id, $rules);
        $this->storePlayerRules($id, $rules);
    }

    /**
     * Stores tag predicates and their referenced tags.
     * @param int $id Category ID.
     * @param array<string, mixed> $rules Validated eligibility.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeTagRules(int $id, array $rules, array $tags): void
    {
        foreach (['baseAny' => ['base_any', 'baseMinimum'], 'tagsAny' => ['tags_any', 'tagMinimum']] as $field => [$type, $minimum]) {
            if (isset($rules[$field])) {
                $ruleId = $this->database->insert(
                    'INSERT INTO category_rules (category_id,rule_type,minimum_strength) VALUES (?,?,?)',
                    [$id, $type, $rules[$minimum]]
                );
                $this->storeRuleTags($ruleId, $rules[$field], $tags);
            }
        }
    }

    /**
     * Links a tag predicate to its selected tags.
     * @param int $ruleId Stored rule ID.
     * @param list<string> $identifiers Selected tag identifiers.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeRuleTags(int $ruleId, array $identifiers, array $tags): void
    {
        foreach ($identifiers as $tag) {
            $this->database->execute(
                'INSERT INTO category_rule_tags (rule_id,tag_id) VALUES (?,?)', [$ruleId, $tags[$tag]['id']]
            );
        }
    }

    /**
     * Stores an optional required game mode.
     * @param int $id Category ID.
     * @param array<string, mixed> $rules Validated eligibility.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeMode(int $id, array $rules): void
    {
        if (isset($rules['requiredMode'])) {
            $this->database->insert(
                "INSERT INTO category_rules (category_id,rule_type,mode) VALUES (?,'required_mode',?)",
                [$id, $rules['requiredMode']]
            );
        }
    }

    /**
     * Stores the supplied player count predicates.
     * @param int $id Category ID.
     * @param array<string, mixed> $rules Validated eligibility.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storePlayerRules(int $id, array $rules): void
    {
        foreach ([
            'minimumMaxPlayers' => 'minimum_max_players',
            'maximumMinPlayers' => 'maximum_min_players',
            'maximumMaxPlayers' => 'maximum_max_players',
        ] as $field => $type) {
            if (isset($rules[$field])) {
                $this->database->insert(
                    'INSERT INTO category_rules (category_id,rule_type,player_count) VALUES (?,?,?)',
                    [$id, $type, $rules[$field]]
                );
            }
        }
    }

    /**
     * Stores component weights, tag scoring and player thresholds.
     * @param int $id Category ID.
     * @param array<string, mixed> $ranking Validated ranking.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \Throwable When storage fails.
     */
    private function storeRanking(int $id, array $ranking, array $tags): void
    {
        $this->storeWeights($id, $ranking);
        $this->storeBaseTags($id, $ranking['baseAny'] ?? [], $tags);
        $this->storeModifierWeights($id, (array) ($ranking['modifierWeights'] ?? []), $tags);
        $this->storePlayerScores($id, $ranking['playerScores'] ?? []);
    }

    /**
     * Stores the ranking component weights.
     * @param int $id Category ID.
     * @param array<string, mixed> $ranking Validated ranking.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeWeights(int $id, array $ranking): void
    {
        $this->database->execute(
            'INSERT INTO category_ranking (category_id,base_weight,modifier_weight,player_weight) VALUES (?,?,?,?)',
            [$id, $ranking['baseWeight'] ?? 0, $ranking['modifierWeight'] ?? 0, $ranking['playerWeight'] ?? 0]
        );
    }

    /**
     * Stores the base tags used for ranking.
     * @param int $id Category ID.
     * @param list<string> $identifiers Validated base tag identifiers.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeBaseTags(int $id, array $identifiers, array $tags): void
    {
        foreach ($identifiers as $tag) {
            $this->database->execute(
                "INSERT INTO category_ranking_tags (category_id,tag_id,component) VALUES (?,?,'base')",
                [$id, $tags[$tag]['id']]
            );
        }
    }

    /**
     * Stores the scoring weight of each modifier tag.
     * @param int $id Category ID.
     * @param array<string, int|float> $weights Validated modifier weights.
     * @param array<string, array{id: int, kind: string}> $tags Tag dictionary.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storeModifierWeights(int $id, array $weights, array $tags): void
    {
        foreach ($weights as $tag => $weight) {
            $this->database->execute(
                "INSERT INTO category_ranking_tags (category_id,tag_id,component,weight) VALUES (?,?,'modifier',?)",
                [$id, $tags[$tag]['id'], $weight]
            );
        }
    }

    /**
     * Stores the ranking scores for player count thresholds.
     * @param int $id Category ID.
     * @param list<\stdClass> $scores Validated thresholds and scores.
     * @return void
     * @throws \mysqli_sql_exception When storage fails.
     */
    private function storePlayerScores(int $id, array $scores): void
    {
        foreach ($scores as $score) {
            $this->database->execute(
                'INSERT INTO category_player_scores (category_id,min_players,score) VALUES (?,?,?)',
                [$id, $score->min, $score->score]
            );
        }
    }

    /**
     * Removes every child rule before replacing or deleting the parent category.
     * @param int $id Category ID.
     * @return void
     * @throws \Throwable When deletion fails.
     */
    private function removeRules(int $id): void
    {
        $this->database->execute(
            'DELETE rt FROM category_rule_tags rt JOIN category_rules r ON r.id=rt.rule_id WHERE r.category_id=?',
            [$id]
        );
        
        $this->database->execute('DELETE FROM category_rules WHERE category_id=?', [$id]);
        $this->database->execute('DELETE FROM category_ranking_tags WHERE category_id=?', [$id]);
        $this->database->execute('DELETE FROM category_player_scores WHERE category_id=?', [$id]);
        $this->database->execute('DELETE FROM category_ranking WHERE category_id=?', [$id]);
    }

    /**
     * Adds eligibility criteria and their tag lists to the categories.
     *
     * @param array<int, array<string, mixed>> $categories Categories indexed by database ID.
     *
     * @return void
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When a rule type is unsupported or its tag list is empty.
     */
    private function addEligibility(array &$categories): void
    {
        foreach ($this->loadEligibility(array_keys($categories)) as $row) {
            $this->applyRule($categories[$row->categoryId]['eligibility'], $row);
        }
    }

    /**
     * Reads eligibility rows for the selected categories in stable rule order.
     * @param list<int> $ids Selected category IDs.
     * @return list<stdClass> Rule rows with optional tag references.
     * @throws \mysqli_sql_exception When lookup fails.
     */
    private function loadEligibility(array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->database->fetchAll(
            <<<SQL
            SELECT
                r.category_id AS categoryId,
                r.rule_type AS ruleType,
                r.minimum_strength AS minimumStrength,
                r.mode,
                r.player_count AS playerCount,
                t.identifier AS tag
            FROM category_rules AS r
            LEFT JOIN category_rule_tags AS rt ON rt.rule_id = r.id
            LEFT JOIN tags AS t ON t.id = rt.tag_id
            WHERE r.category_id IN ($placeholders)
            ORDER BY r.category_id, r.id, t.identifier COLLATE utf8mb4_bin
            SQL,
            $ids
        );
    }

    /**
     * Applies one stored rule row to the category's eligibility document.
     * @param stdClass $eligibility Eligibility document being assembled.
     * @param stdClass $row Stored rule and optional tag.
     * @return void
     * @throws RuntimeException When a rule type is unsupported or its tag is missing.
     */
    private function applyRule(stdClass $eligibility, stdClass $row): void
    {
        switch ($row->ruleType) {
            case 'base_any':
            case 'tags_any':
                $this->applyTagRule($eligibility, $row);
                break;
                
            case 'required_mode':
                $eligibility->requiredMode = $row->mode;
                break;
                
            case 'minimum_max_players':
                $eligibility->minimumMaxPlayers = (int) $row->playerCount;
                break;
                
            case 'maximum_min_players':
                $eligibility->maximumMinPlayers = (int) $row->playerCount;
                break;
                
            case 'maximum_max_players':
                $eligibility->maximumMaxPlayers = (int) $row->playerCount;
                break;
                
            default:
                throw new RuntimeException('Unsupported category rule: ' . $row->ruleType);
        }
    }

    /**
     * Adds a tag and its minimum strength to a tag predicate.
     * @param stdClass $eligibility Eligibility document being assembled.
     * @param stdClass $row Base or modifier tag rule.
     * @return void
     * @throws RuntimeException When the rule has no referenced tag.
     */
    private function applyTagRule(stdClass $eligibility, stdClass $row): void
    {
        if ($row->tag === null) {
            throw new RuntimeException('Missing tags for category rule: ' . $row->ruleType);
        }
        
        $list = $row->ruleType === 'base_any' ? 'baseAny' : 'tagsAny';
        $minimum = $row->ruleType === 'base_any' ? 'baseMinimum' : 'tagMinimum';
        $eligibility->{$list}[] = $row->tag;
        $eligibility->{$minimum} = (float) $row->minimumStrength;
    }

    /**
     * Adds ranking families and modifier weights to the categories.
     *
     * @param array<int, array<string, mixed>> $categories Categories indexed by database ID.
     *
     * @return void
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When the query does not return rows.
     */
    private function addRankingTags(array &$categories): void
    {
        $ids = array_keys($categories);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->database->fetchAll(
            <<<SQL
            SELECT
                rt.category_id AS categoryId,
                rt.component,
                rt.weight,
                t.identifier AS tag
            FROM category_ranking_tags AS rt
            JOIN tags AS t ON t.id = rt.tag_id
            WHERE rt.category_id IN ($placeholders)
            ORDER BY rt.category_id, t.identifier COLLATE utf8mb4_bin
            SQL,
            $ids
        );
        
        foreach ($rows as $row) {
            if ($row->component === 'base') {
                $categories[$row->categoryId]['ranking']['baseAny'][] = $row->tag;
            } else {
                $categories[$row->categoryId]['ranking']['modifierWeights'][$row->tag] = (float) $row->weight;
            }
        }
    }

    /**
     * Adds player scores ordered by their minimum player counts.
     *
     * @param array<int, array<string, mixed>> $categories Categories indexed by database ID.
     *
     * @return void
     * @throws \mysqli_sql_exception When the query fails.
     * @throws RuntimeException When the query does not return rows.
     */
    private function addPlayerScores(array &$categories): void
    {
        $ids = array_keys($categories);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->database->fetchAll(
            <<<SQL
            SELECT category_id AS categoryId, min_players AS minimumPlayers, score
            FROM category_player_scores
            WHERE category_id IN ($placeholders)
            ORDER BY category_id, min_players
            SQL,
            $ids
        );
        
        foreach ($rows as $row) {
            $categories[$row->categoryId]['ranking']['playerScores'][] = [
                'min' => (int) $row->minimumPlayers,
                'score' => (float) $row->score,
            ];
        }
    }
}
