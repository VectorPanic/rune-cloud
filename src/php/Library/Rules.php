<?php

declare(strict_types=1);

namespace RuneCloud\Library;

use RuntimeException;

/**
 * Checks catalog and game consistency using already loaded metadata.
 */
final class Rules
{
    //--------------------------------------------------------------------------
    // Public methods
    //--------------------------------------------------------------------------

    /**
     * Checks category references and ranking against the tag catalog.
     * @param array<string, mixed> $tags Tag document.
     * @param array<string, mixed> $categories Category document.
     * @return void
     * @throws RuntimeException When references or ranking are inconsistent.
     */
    public function catalog(array $tags, array $categories): void
    {
        $kinds = array_column($tags['tags'], 'kind', 'id');
        foreach ($categories['categories'] as $category) {
            $this->category($category, $kinds);
        }
    }

    /**
     * Checks game tag references and supported multiplayer counts.
     * @param array<string, mixed> $tags Tag document.
     * @param array<string, mixed> $game Game metadata.
     * @return void
     * @throws RuntimeException When tag references or game modes are inconsistent.
     */
    public function game(array $tags, array $game): void
    {
        $kinds = array_column($tags['tags'], 'kind', 'id');
        $this->references(array_keys((array) $game['baseTags']), 'base', $kinds);
        $this->references(array_keys((array) $game['tags']), 'modifier', $kinds);
        $this->modes($game);
    }

    //--------------------------------------------------------------------------
    // Private methods
    //--------------------------------------------------------------------------

    /**
     * Checks one category's eligibility, scoring references and ranking.
     * @param array<string, mixed> $category Category metadata.
     * @param array<string, string> $kinds Tag identifiers mapped to kinds.
     * @return void
     * @throws RuntimeException When category references or ranking are inconsistent.
     */
    private function category(array $category, array $kinds): void
    {
        $eligibility = (array) $category['eligibility'];
        $ranking = $category['ranking'];
        $this->references($eligibility['baseAny'] ?? [], 'base', $kinds);
        $this->references($eligibility['tagsAny'] ?? [], 'modifier', $kinds);
        $this->references($ranking['baseAny'] ?? [], 'base', $kinds);
        $this->references(array_keys($ranking['modifierWeights'] ?? []), 'modifier', $kinds);
        $this->ranking($category['id'], $ranking);
    }

    /**
     * Requires every reference to exist with the expected tag kind.
     * @param list<string|int> $ids Referenced tag identifiers.
     * @param string $kind Expected tag kind.
     * @param array<string, string> $kinds Tag identifiers mapped to kinds.
     * @return void
     * @throws RuntimeException When a tag is missing or has the wrong kind.
     */
    private function references(array $ids, string $kind, array $kinds): void
    {
        foreach ($ids as $id) {
            if (($kinds[$id] ?? null) !== $kind) {
                throw new RuntimeException('Unknown tag or incorrect tag kind: ' . $id);
            }
        }
    }

    /**
     * Requires complete scoring inputs and component weights totaling one hundred.
     * @param string $identifier Category identifier.
     * @param array<string, mixed> $ranking Stored ranking metadata.
     * @return void
     * @throws RuntimeException When ranking inputs are incomplete.
     */
    private function ranking(string $identifier, array $ranking): void
    {
        if ($ranking['baseWeight'] + $ranking['modifierWeight'] + $ranking['playerWeight'] !== 100
            || ($ranking['baseWeight'] > 0 && empty($ranking['baseAny']))
            || ($ranking['modifierWeight'] > 0 && abs(array_sum($ranking['modifierWeights'] ?? []) - 100) > 0.001)
            || ($ranking['playerWeight'] > 0 && empty($ranking['playerScores']))
        ) {
            throw new RuntimeException('Incomplete category ranking: ' . $identifier);
        }
    }

    /**
     * Requires multiplayer games to support at least two players.
     * @param array<string, mixed> $game Stored game metadata.
     * @return void
     * @throws RuntimeException When player counts contradict the selected modes.
     */
    private function modes(array $game): void
    {
        if (($game['modes']['coop'] || $game['modes']['versus']) && $game['players']['max'] < 2) {
            throw new RuntimeException('Multiplayer modes require at least two supported players.');
        }
    }
}
