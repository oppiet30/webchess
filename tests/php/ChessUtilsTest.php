<?php

declare(strict_types=1);

namespace WebChess\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Baseline tests for the pure helpers in chessutils.php + chessconstants.php.
 *
 * Board coordinates follow the app-wide convention: board[row][col],
 * row = rank - 1, col = file (a=0).
 */
final class ChessUtilsTest extends TestCase
{
    public function testGetPieceName(): void
    {
        self::assertSame('pawn', getPieceName(PAWN));
        self::assertSame('pawn', getPieceName(BLACK | PAWN));
        self::assertSame('knight', getPieceName(KNIGHT));
        self::assertSame('bishop', getPieceName(BISHOP));
        self::assertSame('rook', getPieceName(ROOK));
        self::assertSame('queen', getPieceName(QUEEN));
        self::assertSame('king', getPieceName(KING));
    }

    public function testGetPieceCode(): void
    {
        self::assertSame(PAWN, getPieceCode('white', 'pawn'));
        self::assertSame(BLACK | PAWN, getPieceCode('black', 'pawn'));
        self::assertSame(BLACK | KING, getPieceCode('black', 'king'));
        self::assertSame(QUEEN, getPieceCode('white', 'queen'));
    }

    public function testGetPGNCode(): void
    {
        self::assertSame('N', getPGNCode('knight'));
        self::assertSame('B', getPGNCode('bishop'));
        self::assertSame('R', getPGNCode('rook'));
        self::assertSame('Q', getPGNCode('queen'));
        self::assertSame('K', getPGNCode('king'));
        self::assertSame('', getPGNCode('pawn'));
        self::assertSame('', getPGNCode(''));
    }

    public function testMoveToPGNString(): void
    {
        /* Long algebraic: piece letter + source, quiet moves use '-'. */
        self::assertSame('d2-d4', moveToPGNString('white', 'pawn', 1, 3, 3, 3, '', '', false));
        self::assertSame('Ra1-h1', moveToPGNString('white', 'rook', 0, 0, 0, 7, '', '', false));

        /* Captures use 'x'; pawn captures are detected even with no capture name. */
        self::assertSame('Ra1xh1', moveToPGNString('white', 'rook', 0, 0, 0, 7, 'rook', '', false));
        self::assertSame('e5xd6', moveToPGNString('white', 'pawn', 4, 4, 5, 3, '', '', false));

        /* Castling is spelled out; the move number is NOT part of this helper. */
        self::assertSame('O-O', moveToPGNString('white', 'king', 0, 4, 0, 6, '', '', false));
        self::assertSame('O-O-O', moveToPGNString('white', 'king', 0, 4, 0, 2, '', '', false));

        /* Promotion appends =Q/... via getPGNCode(). */
        self::assertSame('e7-e8=Q', moveToPGNString('white', 'pawn', 6, 4, 7, 4, '', 'queen', false));

        /* Check flag adds '+'. */
        self::assertSame('Qd1-d2+', moveToPGNString('white', 'queen', 0, 3, 1, 3, '', '', true));
    }

    public function testMoveToVerbousString(): void
    {
        self::assertSame(
            'white pawn from e5 to d6 eating pawn en-passant',
            moveToVerbousString('white', 'pawn', 4, 4, 5, 3, '', '', false),
        );

        self::assertSame(
            'white king from e1 to g1 (castled)',
            moveToVerbousString('white', 'king', 0, 4, 0, 6, '', '', false),
        );

        self::assertSame(
            'black rook from a8 to a2 eating pawn',
            moveToVerbousString('black', 'rook', 7, 0, 1, 0, 'pawn', '', false),
        );
    }

    public function testCoordsToSquare(): void
    {
        self::assertSame('a1', coordsToSquare(0, 0));
        self::assertSame('e4', coordsToSquare(3, 4));
        self::assertSame('h8', coordsToSquare(7, 7));
    }

    public function testMinimumVersion(): void
    {
        self::assertTrue(minimum_version('8.0'));
        self::assertFalse(minimum_version('99.0'));
    }

    public function testFixOldPHPVersionsIsNoOp(): void
    {
        self::assertNull(fixOldPHPVersions());
    }
}