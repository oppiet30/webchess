<?php

declare(strict_types=1);

namespace WebChess\Tests\Chess;

use PHPUnit\Framework\TestCase;
use WebChess\Chess\PlayerColor;

final class PlayerColorTest extends TestCase
{
    public function testExplicitColorsPassThrough(): void
    {
        self::assertSame('white', PlayerColor::resolve('white'));
        self::assertSame('black', PlayerColor::resolve('black'));
    }

    public function testAnyNonRandomChoicePassesThrough(): void
    {
        /* legacy behavior: the raw post value is used as-is */
        self::assertSame('RED', PlayerColor::resolve('RED'));
    }

    public function testRandomResolvesToWhiteOrBlack(): void
    {
        self::assertSame('white', PlayerColor::resolve('random', static fn(): bool => true));
        self::assertSame('black', PlayerColor::resolve('random', static fn(): bool => false));
    }

    public function testDefaultRandomUsesMtRand(): void
    {
        mt_srand(1234);
        $result = PlayerColor::resolve('random');

        self::assertContains($result, ['white', 'black']);

        /* the default flip mirrors the legacy expression mt_rand(0, 1) == 1 */
        mt_srand(1234);
        $expected = mt_rand(0, 1) === 1 ? 'white' : 'black';
        self::assertSame($expected, $result);
    }
}