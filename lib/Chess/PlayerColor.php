<?php

declare(strict_types=1);

namespace WebChess\Chess;

/**
 * Resolves the color a player chose when issuing a challenge: "random" flips a
 * coin, everything else is passed through unchanged (the caller validates that
 * it is "white" or "black").
 */
final class PlayerColor
{
    /**
     * @param (callable(): bool)|null $randomBool injectable coin flip; defaults
     *                                            to mt_rand(0, 1) === 1
     */
    public static function resolve(string $choice, ?callable $randomBool = null): string
    {
        if ($choice !== 'random') {
            return $choice;
        }

        $randomBool ??= static fn(): bool => mt_rand(0, 1) === 1;

        return $randomBool() ? 'white' : 'black';
    }
}