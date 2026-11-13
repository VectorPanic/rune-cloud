<?php

declare(strict_types=1);

namespace RuneCloud\Category;

use RuneCloud\Http\HttpException;
use stdClass;

/**
 * Validates category metadata, eligibility and ranking before any writes.
 */
final class Validation
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Checks a complete category against the current tag dictionary.
     * @param array<string, mixed> $data Category input.
     * @param array<string, string> $tags Tag identifiers mapped to kinds.
     * @return void
     * @throws HttpException When the category is invalid.
     */
    public function validate(array $data, array $tags): void
    {
        $this->validateMetadata($data);
        $this->validateEligibility((array) ($data['eligibility'] ?? new stdClass()), $tags);
        $this->validateRanking((array) $data['ranking'], $tags);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks accepted metadata fields and object shapes.
     * @param array<string, mixed> $data Complete category.
     * @return void
     * @throws HttpException When metadata is invalid.
     */
    private function validateMetadata(array $data): void
    {
        if (array_diff(array_keys($data), ['id','name','description','sortOrder','limit','eligibility','ranking']) !== []
            || !is_string($data['id'] ?? null) || strlen($data['id']) > 100
            || preg_match('/^[a-z][a-z0-9-]*$/D', $data['id']) !== 1
            || !is_string($data['name'] ?? null) || trim($data['name']) === ''
            || preg_match('/\A.{1,255}\z/us', $data['name']) !== 1
            || (isset($data['description']) && (!is_string($data['description']) || strlen($data['description']) > 65535))
            || (array_key_exists('sortOrder', $data) && !$this->isInteger($data['sortOrder'], 0, 4294967295))
            || (isset($data['limit']) && !$this->isInteger($data['limit'], 1, 4294967295))
            || (array_key_exists('eligibility', $data) && !$data['eligibility'] instanceof stdClass)
            || !($data['ranking'] ?? null) instanceof stdClass
        ) {
            throw new HttpException('invalid_category_metadata', 422);
        }
    }

    /**
     * Checks tag thresholds, player boundaries and required mode.
     * @param array<string, mixed> $rules Eligibility rules.
     * @param array<string, string> $tags Known tag kinds.
     * @return void
     * @throws HttpException When an eligibility rule is invalid.
     */
    private function validateEligibility(array $rules, array $tags): void
    {
        if (array_diff(array_keys($rules), ['baseAny','baseMinimum','tagsAny','tagMinimum','requiredMode',
            'minimumMaxPlayers','maximumMinPlayers','maximumMaxPlayers']) !== []) {
            throw new HttpException('invalid_category_eligibility', 422);
        }
        foreach (['baseAny' => ['baseMinimum','base'], 'tagsAny' => ['tagMinimum','modifier']] as $field => [$threshold,$kind]) {
            if (array_key_exists($field, $rules) || array_key_exists($threshold, $rules)) {
                $this->validateTagList($rules[$field] ?? null, $kind, $tags);
                if ($rules[$field] === [] || !$this->isScore($rules[$threshold] ?? null, false)) {
                    throw new HttpException('invalid_category_eligibility', 422);
                }
            }
        }
        if (array_key_exists('requiredMode', $rules) && !in_array($rules['requiredMode'], ['coop','versus'], true)) {
            throw new HttpException('invalid_category_eligibility', 422);
        }
        foreach (['minimumMaxPlayers','maximumMinPlayers','maximumMaxPlayers'] as $field) {
            if (array_key_exists($field, $rules) && !$this->isInteger($rules[$field], 1, 255)) {
                throw new HttpException('invalid_category_eligibility', 422);
            }
        }
    }

    /**
     * Requires weights totaling 100 and complete active scoring components.
     * @param array<string, mixed> $ranking Ranking input.
     * @param array<string, string> $tags Known tag kinds.
     * @return void
     * @throws HttpException When ranking is invalid.
     */
    private function validateRanking(array $ranking, array $tags): void
    {
        if (array_diff(array_keys($ranking), ['baseWeight','modifierWeight','playerWeight','baseAny','modifierWeights','playerScores']) !== []) {
            throw new HttpException('invalid_category_ranking', 422);
        }
        foreach (['baseWeight','modifierWeight','playerWeight'] as $field) {
            if (array_key_exists($field, $ranking) && !$this->isInteger($ranking[$field], 0, 100)) {
                throw new HttpException('invalid_category_ranking', 422);
            }
        }
        if (($ranking['baseWeight'] ?? 0) + ($ranking['modifierWeight'] ?? 0) + ($ranking['playerWeight'] ?? 0) !== 100) {
            throw new HttpException('invalid_category_ranking', 422);
        }
        $this->validateTagList($ranking['baseAny'] ?? [], 'base', $tags);
        if (($ranking['baseWeight'] ?? 0) > 0 && empty($ranking['baseAny'])) {
            throw new HttpException('invalid_category_ranking', 422);
        }
        $this->validateModifierWeights($ranking, $tags);
        $this->validatePlayerScores($ranking);
    }

    /**
     * Checks positive modifier strengths and their normalization.
     * @param array<string, mixed> $ranking Ranking input.
     * @param array<string, string> $tags Known tag kinds.
     * @return void
     * @throws HttpException When modifiers are invalid.
     */
    private function validateModifierWeights(array $ranking, array $tags): void
    {
        if (array_key_exists('modifierWeights', $ranking) && !$ranking['modifierWeights'] instanceof stdClass) {
            throw new HttpException('invalid_category_ranking', 422);
        }
        $weights = (array) ($ranking['modifierWeights'] ?? new stdClass());
        $this->validateTagList(array_keys($weights), 'modifier', $tags);
        foreach ($weights as $weight) {
            if (!$this->isScore($weight, false)) {
                throw new HttpException('invalid_category_ranking', 422);
            }
        }
        if (($ranking['modifierWeight'] ?? 0) > 0 && abs(array_sum($weights) - 100) > 0.001) {
            throw new HttpException('invalid_category_ranking', 422);
        }
    }

    /**
     * Checks unique player-count score thresholds.
     * @param array<string, mixed> $ranking Ranking input.
     * @return void
     * @throws HttpException When player scores are invalid.
     */
    private function validatePlayerScores(array $ranking): void
    {
        $scores = $ranking['playerScores'] ?? [];
        if (!is_array($scores) || !array_is_list($scores) || (($ranking['playerWeight'] ?? 0) > 0 && $scores === [])) {
            throw new HttpException('invalid_category_ranking', 422);
        }
        $seen = [];
        foreach ($scores as $score) {
            if (!$score instanceof stdClass || array_diff(array_keys((array) $score), ['min','score']) !== []
                || !$this->isInteger($score->min ?? null, 1, 255) || !$this->isScore($score->score ?? null, true)
                || isset($seen[$score->min])) {
                throw new HttpException('invalid_category_ranking', 422);
            }
            $seen[$score->min] = true;
        }
    }

    /**
     * Requires a unique list of known tag identifiers of the intended kind.
     * @param mixed $values Submitted list.
     * @param string $kind Expected tag kind.
     * @param array<string, string> $tags Known tag kinds.
     * @return void
     * @throws HttpException When the list or a tag is invalid.
     */
    private function validateTagList(mixed $values, string $kind, array $tags): void
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new HttpException('invalid_category_tags', 422);
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || ($tags[$value] ?? null) !== $kind || isset($seen[$value])) {
                throw new HttpException('invalid_category_tags', 422);
            }
            $seen[$value] = true;
        }
    }

    /**
     * Checks an integer against database-safe bounds.
     * @param mixed $value Submitted number.
     * @param int $minimum Inclusive minimum.
     * @param int $maximum Inclusive maximum.
     * @return bool Whether valid.
     */
    private function isInteger(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    /**
     * Checks a finite score that can be stored exactly to two decimal places.
     * @param mixed $value Submitted number.
     * @param bool $allowZero Whether zero is valid.
     * @return bool Whether valid.
     */
    private function isScore(mixed $value, bool $allowZero): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value <= 100 && ($allowZero ? $value >= 0 : $value > 0)
            && abs($value * 100 - round($value * 100)) < 0.000001;
    }
}
