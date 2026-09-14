<?php

declare(strict_types=1);

namespace WebChess\Player;

/**
 * Maps the players.userlevel column between its human-readable label and the
 * numeric level stored in the session. Labels were introduced with the 2026
 * migration (players.userlevel became VARCHAR(20)).
 */
final class UserLevel
{
    private const LEVELS_BY_NUMBER = [
        '1' => '(Novice)',
        '2' => '(Occasional)',
        '3' => '(Hobbyist)',
        '4' => '(Expert)',
        '5' => '(Master)',
    ];

    /**
     * Converts a stored label like "(Novice)" to its numeric level ("1").
     * Returns "0" for anything unrecognized so callers can handle the
     * "level not set" case explicitly.
     */
    public static function toLevelNumber(string $label): string
    {
        $result = \array_search($label, self::LEVELS_BY_NUMBER, true);

        return $result !== false ? (string) $result : '0';
    }

    /**
     * Converts a numeric level ("1".."5") to its stored label. Returns an
     * empty string for unknown values, matching the legacy behavior where an
     * unmatched level cleared the column.
     */
    public static function toLabel(string $levelNumber): string
    {
        return self::LEVELS_BY_NUMBER[$levelNumber] ?? '';
    }
}