<?php

declare(strict_types=1);

namespace WebChess\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Baseline tests for the legacy PHP engine (chess.inc), which gui.php still
 * uses for replay/PGN rendering. It reads standard FEN via set_FEN() and
 * consumes WebChess long-algebraic moves via add_Move().
 */
final class LegacyEngineTest extends TestCase
{
    private const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    private function notation(): string
    {
        return $GLOBALS['nkNotation'];
    }

    public function testStartFenRoundTrips(): void
    {
        set_FEN(self::START_FEN);

        self::assertSame(self::START_FEN, get_FEN());
    }

    public function testPlaysAnOpeningAndBuildsNotation(): void
    {
        set_FEN(self::START_FEN);

        self::assertTrue(add_Move('e2-e4'));
        self::assertSame('rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1', get_FEN());
        self::assertSame('1.e4 ', $this->notation());

        self::assertTrue(add_Move('e7-e5'));
        self::assertTrue(add_Move('g1-f3'));
        self::assertTrue(add_Move('b8-c6'));

        self::assertSame('1.e4 e5 2.Nf3 Nc6 ', $this->notation());
    }

    public function testIllegalMovesAreRejected(): void
    {
        set_FEN(self::START_FEN);

        self::assertFalse(add_Move('e2-e5'), 'pawn cannot jump two-and-one squares');
        self::assertFalse(add_Move('e4xd5'), 'no pawn on d5 to capture');
        self::assertFalse(add_Move('g1-e4'), 'knight cannot move there');

        /* nothing was recorded */
        self::assertSame('', $this->notation());
    }

    public function testCastlingNotation(): void
    {
        set_FEN('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');

        self::assertTrue(add_Move('e1-g1'));
        self::assertSame('1.O-O ', $this->notation());
        self::assertSame('r3k2r/8/8/8/8/8/8/R4RK1 b kq - 0 1', get_FEN());
    }

    public function testQueensideCastlingNotation(): void
    {
        set_FEN('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');

        self::assertTrue(add_Move('e1-c1'));
        self::assertSame('1.O-O-O ', $this->notation());
        self::assertSame('r3k2r/8/8/8/8/8/8/2KR3R b kq - 0 1', get_FEN());
    }

    public function testPromotionNotation(): void
    {
        set_FEN('8/4P3/8/8/8/8/8/4K2k w - - 0 1');

        self::assertTrue(add_Move('e7-e8Q'));
        self::assertSame('1.e8=Q ', $this->notation());
    }

    public function testCheckIsDetected(): void
    {
        set_FEN('k7/8/8/8/8/8/7Q/3K4 w - - 0 1');

        self::assertTrue(add_Move('h2-h8'));
        self::assertSame('Check', State_to_String($GLOBALS['nkState']));
    }
}