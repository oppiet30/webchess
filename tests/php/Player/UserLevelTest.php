<?php

declare(strict_types=1);

namespace WebChess\Tests\Player;

use PHPUnit\Framework\TestCase;
use WebChess\Player\UserLevel;

final class UserLevelTest extends TestCase
{
    public function testToLevelNumberMapsLabelsToNumbers(): void
    {
        self::assertSame('1', UserLevel::toLevelNumber('(Novice)'));
        self::assertSame('2', UserLevel::toLevelNumber('(Occasional)'));
        self::assertSame('3', UserLevel::toLevelNumber('(Hobbyist)'));
        self::assertSame('4', UserLevel::toLevelNumber('(Expert)'));
        self::assertSame('5', UserLevel::toLevelNumber('(Master)'));
    }

    public function testToLevelNumberDefaultsToZeroForUnknownLabel(): void
    {
        self::assertSame('0', UserLevel::toLevelNumber('(Unknown)'));
        self::assertSame('0', UserLevel::toLevelNumber('1'));
        self::assertSame('0', UserLevel::toLevelNumber(''));
    }

    public function testToLabelMapsNumbersToLabels(): void
    {
        self::assertSame('(Novice)', UserLevel::toLabel('1'));
        self::assertSame('(Occasional)', UserLevel::toLabel('2'));
        self::assertSame('(Hobbyist)', UserLevel::toLabel('3'));
        self::assertSame('(Expert)', UserLevel::toLabel('4'));
        self::assertSame('(Master)', UserLevel::toLabel('5'));
    }

    public function testToLabelDefaultsToEmptyForUnknownNumber(): void
    {
        self::assertSame('', UserLevel::toLabel('0'));
        self::assertSame('', UserLevel::toLabel('9'));
        self::assertSame('', UserLevel::toLabel(''));
    }

    public function testLabelsRoundTrip(): void
    {
        foreach (['1', '2', '3', '4', '5'] as $number) {
            self::assertSame($number, UserLevel::toLevelNumber(UserLevel::toLabel($number)));
        }
    }
}