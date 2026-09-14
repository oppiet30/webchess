<?php

declare(strict_types=1);

namespace WebChess\Tests\Game;

use PHPUnit\Framework\TestCase;
use WebChess\Game\GameService;
use WebChess\Tests\Http\TestDatabase;

/**
 * DB-backed integration tests for the GameService orchestration: move
 * application, castling / en-passant / promotion validation, undo, draw and
 * resign — delegating to the legacy chessdb.php / move.php / undo.php code.
 * Skipped unless WEBCHESS_DB_* env vars are set (CI has none).
 */
final class GameServiceTest extends TestCase
{
    private const PAWN = 1;
    private const KNIGHT = 2;
    private const BISHOP = 4;
    private const ROOK = 8;
    private const QUEEN = 16;
    private const KING = 32;
    private const WHITE = 0;
    private const BLACK = 128;

    private int $whiteId = 0;
    private int $blackId = 0;
    private int $gameId = 0;

    protected function setUp(): void
    {
        TestDatabase::boot();

        if (!TestDatabase::isAvailable()) {
            self::markTestSkipped(TestDatabase::reason());
        }

        if (!\defined('DEBUG')) {
            \define('DEBUG', 0);
        }

        $tables = $GLOBALS['CFG_TABLE'];
        foreach (['history', 'pieces', 'messages', 'games', 'preferences', 'players'] as $table) {
            \db_query('TRUNCATE TABLE ' . $tables[$table]);
        }

        \db_query('INSERT INTO ' . $tables['players'] . ' (password, firstName, lastName, nick, userlevel) VALUES (?, ?, ?, ?, ?)', [\hash_password('whitepw'), 'White', 'Player', 'whitetest', '(Novice)']);
        $this->whiteId = (int) \db_insert_id();
        \db_query('INSERT INTO ' . $tables['players'] . ' (password, firstName, lastName, nick, userlevel) VALUES (?, ?, ?, ?, ?)', [\hash_password('blackpw'), 'Black', 'Player', 'blacktest', '(Novice)']);
        $this->blackId = (int) \db_insert_id();

        \db_query(
            "INSERT INTO " . $tables['games'] . " (whitePlayer, blackPlayer, gameMessage, messageFrom, dateCreated, lastMove) VALUES (?, ?, '', '', NOW(), NOW())",
            [$this->whiteId, $this->blackId]
        );
        $this->gameId = (int) \db_insert_id();

        $_SESSION['gameID'] = $this->gameId;
        $_SESSION['isSharedPC'] = false;
        $this->startStandardGame();
    }

    public function testWhitePawnMovePersistsHistoryBoardAndPieces(): void
    {
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']
        );

        self::assertSame(0, $state['numMoves']);
        self::assertSame(self::WHITE | self::PAWN, $state['board'][3][4]);
        self::assertSame(0, $state['board'][1][4]);

        $row = $this->historyRow(0);
        self::assertSame('pawn', $row['curPiece']);
        self::assertSame('white', $row['curColor']);
        self::assertSame(1, (int) $row['fromRow']);
        self::assertSame(4, (int) $row['toCol']);
        self::assertNull($row['replaced']);
        self::assertNull($row['promotedTo']);

        $piece = $this->pieceAt(3, 4);
        self::assertNotNull($piece);
        self::assertSame('white', $piece['color']);
        self::assertSame('pawn', $piece['piece']);
    }

    public function testBlackReplyThenWhiteKnightAppliesFullTurn(): void
    {
        $service = $this->service();
        $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']);
        /* the history primary key is (timeOfMove, gameID) and the app writes
           NOW() — a second same-second ply would collide, so pause a second. */
        \sleep(1);
        $service->applyStateChange($this->gameId, $this->blackId, false, ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 4, 'toCol' => 4, 'isInCheck' => 'false']);
        \sleep(1);
        $state = $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 0, 'fromCol' => 6, 'toRow' => 2, 'toCol' => 5, 'isInCheck' => 'false']);

        self::assertSame(2, $state['numMoves']);
        self::assertSame('black', $this->historyRow(1)['curColor']);
        self::assertSame(self::WHITE | self::KNIGHT, $state['board'][2][5]);
        self::assertSame(0, $state['board'][0][6]);
    }

    public function testWrongColorMoveIsRejected(): void
    {
        /* it is white's move; a black move must be ignored */
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 4, 'toCol' => 4, 'isInCheck' => 'false']
        );

        self::assertSame(-1, $state['numMoves']);
        self::assertSame(self::BLACK | self::PAWN, $state['board'][6][4]);
        self::assertNull($this->historyRow(0));
    }

    public function testForgedCastlingIsRejectedWhenSquaresBlocked(): void
    {
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 0, 'fromCol' => 4, 'toRow' => 0, 'toCol' => 6, 'isInCheck' => 'false']
        );

        self::assertSame(-1, $state['numMoves']);
        self::assertNull($this->historyRow(0));
        self::assertSame(self::WHITE | self::KING, $state['board'][0][4]);
        self::assertSame(self::WHITE | self::BISHOP, $state['board'][0][5]);   // f1 still blocked
    }

    public function testValidKingsideCastlingMovesRook(): void
    {
        $this->setupBoard([
            [0, 4, self::WHITE | self::KING],
            [0, 7, self::WHITE | self::ROOK],
            [7, 4, self::BLACK | self::KING],
        ]);

        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 0, 'fromCol' => 4, 'toRow' => 0, 'toCol' => 6, 'isInCheck' => 'false']
        );

        self::assertSame(0, $state['numMoves']);
        self::assertSame(self::WHITE | self::KING, $state['board'][0][6]);
        self::assertSame(self::WHITE | self::ROOK, $state['board'][0][5]);
        self::assertSame(0, $state['board'][0][7]);
        self::assertSame('white', $this->historyRow(0)['curColor']);
    }

    public function testCastlingRejectedAfterRookOrKingMovedEarlier(): void
    {
        $this->setupBoard([
            [0, 4, self::WHITE | self::KING],
            [0, 7, self::WHITE | self::ROOK],
            [7, 4, self::BLACK | self::KING],
        ]);
        $this->persistHistory([
            ['pawn', 'white', 1, 4, 3, 4, null, null],
            ['pawn', 'black', 6, 4, 4, 4, null, null],
            ['rook', 'white', 0, 7, 0, 6, null, null],   // the rook (0,7) has moved
            ['pawn', 'black', 6, 0, 4, 0, null, null],
        ]);

        /* numMoves=3 → white to move; but the rook already moved (history row 2) */
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 0, 'fromCol' => 4, 'toRow' => 0, 'toCol' => 6, 'isInCheck' => 'false']
        );

        self::assertSame(3, $state['numMoves']);
        self::assertSame(self::WHITE | self::KING, $state['board'][0][4]);   // castle ignored
        self::assertSame(self::WHITE | self::ROOK, $state['board'][0][7]);
    }

    public function testEnPassantCaptureByBlackPawnIsApplied(): void
    {
        /* 1 d2-d4 by white; black pawn on e4 captures e.p. to d3. */
        $this->setupBoard([
            [3, 3, self::WHITE | self::PAWN],   // white d4 (just double-stepped)
            [3, 4, self::BLACK | self::PAWN],   // black e4
            [7, 4, self::BLACK | self::KING],
            [0, 4, self::WHITE | self::KING],
        ]);
        $this->persistHistory([
            ['pawn', 'white', 1, 3, 3, 3, null, null],
        ]);

        $state = $this->service()->applyStateChange(
            $this->gameId, $this->blackId, false,
            ['fromRow' => 3, 'fromCol' => 4, 'toRow' => 2, 'toCol' => 3, 'isInCheck' => 'false']
        );

        self::assertSame(self::BLACK | self::PAWN, $state['board'][2][3]);
        self::assertSame(0, $state['board'][3][4]);
        self::assertSame(0, $state['board'][3][3]);   // captured pawn removed

        /* PHP's clock and the DB NOW() can be hours apart (timezone), so the
           fixture row may sort after the live ply: pick the black move by color. */
        $rows = \db_all('SELECT * FROM ' . $this->historyTable() . ' WHERE gameID = ?', [$this->gameId]);
        self::assertCount(2, $rows);
        $blackMove = null;
        foreach ($rows as $row) {
            if ($row['curColor'] === 'black') {
                $blackMove = $row;
                break;
            }
        }
        self::assertNotNull($blackMove);
        self::assertSame(2, (int) $blackMove['toRow']);
        self::assertSame(3, (int) $blackMove['toCol']);
        self::assertNull($blackMove['replaced']);
    }

    public function testFideWhiteSideEnPassantIsRejectedOwingToLegacyQuirk(): void
    {
        /* 1 e4 d5 2 exd6 e.p. — legal at the board, rejected by this engine. */
        $this->setupBoard([
            [3, 4, self::WHITE | self::PAWN],
            [4, 3, self::BLACK | self::PAWN],
            [7, 4, self::BLACK | self::KING],
            [0, 4, self::WHITE | self::KING],
        ]);
        $this->persistHistory([
            ['pawn', 'black', 6, 3, 4, 3, null, null],
        ]);

        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 3, 'fromCol' => 4, 'toRow' => 5, 'toCol' => 3, 'isInCheck' => 'false']
        );

        self::assertSame(0, $state['numMoves']);   // only the fixture ply was loaded
        self::assertSame(self::WHITE | self::PAWN, $state['board'][3][4]);  // move ignored
        self::assertSame(self::BLACK | self::PAWN, $state['board'][4][3]);
        self::assertNull($this->historyRow(1));
    }

    public function testForgedDiagonalEnPassantCaptureIsRejected(): void
    {
        /* e2-f3 (diagonal onto an empty, friendly-adjacent square) is not a capture */
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 2, 'toCol' => 5, 'isInCheck' => 'false']
        );

        self::assertSame(-1, $state['numMoves']);
        self::assertNull($this->historyRow(0));
    }

    public function testPawnPromotionCompletesAndWritesPiece(): void
    {
        $this->setupBoard([
            [6, 4, self::WHITE | self::PAWN],
            [0, 4, self::WHITE | self::KING],
            [7, 7, self::BLACK | self::KING],
        ]);

        /* first leg: push g7-g8 sets isPromoting */
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 7, 'toCol' => 4, 'isInCheck' => 'false']
        );

        self::assertTrue($state['isPromoting']);
        self::assertNull($this->historyRow(0)['promotedTo']);

        /* second leg: choose queen */
        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 7, 'toCol' => 4, 'promotion' => self::QUEEN, 'isInCheck' => 'false']
        );

        self::assertSame('queen', $this->historyRow(0)['promotedTo']);
        self::assertSame(self::WHITE | self::QUEEN, $state['board'][7][4]);
        self::assertSame('queen', $this->pieceAt(7, 4)['piece']);
    }

    public function testForgedPromotionIntoKingIsRejected(): void
    {
        $this->setupBoard([
            [6, 4, self::WHITE | self::PAWN],
            [0, 4, self::WHITE | self::KING],
            [7, 7, self::BLACK | self::KING],
        ]);
        $this->service()->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 7, 'toCol' => 4, 'isInCheck' => 'false']);

        $state = $this->service()->applyStateChange(
            $this->gameId, $this->whiteId, false,
            ['fromRow' => 6, 'fromCol' => 4, 'toRow' => 7, 'toCol' => 4, 'promotion' => self::KING, 'isInCheck' => 'false']
        );

        self::assertNull($this->historyRow(0)['promotedTo']);
        self::assertSame(self::WHITE | self::PAWN, $state['board'][7][4]);
    }

    public function testUndoRequestOnSharedPcRollsBackTheLastMove(): void
    {
        $service = $this->service();
        $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']);

        $state = $service->applyStateChange($this->gameId, $this->whiteId, true, ['requestUndo' => 'yes']);

        self::assertSame(-1, $state['numMoves']);
        self::assertNull($this->historyRow(0));
        self::assertSame(self::WHITE | self::PAWN, $state['board'][1][4]);
        self::assertSame(0, $state['board'][3][4]);
    }

    public function testDrawRequestOnSharedPcEndsGame(): void
    {
        $service = $this->service();
        $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']);

        $state = $service->applyStateChange($this->gameId, $this->whiteId, true, ['requestDraw' => 'yes']);

        self::assertTrue($state['isGameOver']);
        self::assertSame('draw', $this->dbValue('SELECT gameMessage FROM ' . $this->gamesTable() . ' WHERE gameID = ?', [$this->gameId]));
    }

    public function testUndoRequestOnSeparatePcsQueuesMessageInstead(): void
    {
        $service = $this->service();
        $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']);

        $state = $service->applyStateChange($this->gameId, $this->whiteId, false, ['requestUndo' => 'yes']);

        self::assertSame(0, $state['numMoves']);
        self::assertSame(1, (int) $this->dbValue('SELECT COUNT(*) FROM ' . $this->messagesTable() . " WHERE gameID = ? AND msgType = 'undo' AND msgStatus = 'request'", [$this->gameId]));
    }

    public function testResignRecordsLoser(): void
    {
        $service = $this->service();
        $service->applyStateChange($this->gameId, $this->whiteId, false, ['fromRow' => 1, 'fromCol' => 4, 'toRow' => 3, 'toCol' => 4, 'isInCheck' => 'false']);

        $state = $service->applyStateChange($this->gameId, $this->blackId, false, ['resign' => 'yes']);

        self::assertTrue($state['isGameOver']);
        $game = \db_row('SELECT gameMessage, messageFrom FROM ' . $this->gamesTable() . ' WHERE gameID = ?', [$this->gameId]);
        self::assertSame('playerResigned', $game['gameMessage']);
        self::assertSame('black', $game['messageFrom']);
        self::assertStringContainsString('has resigned the game', $state['statusMessage']);
    }

    public function testIncompletePromotionDetectedOnReload(): void
    {
        $this->setupBoard([
            [7, 4, self::WHITE | self::PAWN],
            [0, 4, self::WHITE | self::KING],
            [7, 7, self::BLACK | self::KING],
        ]);
        $this->persistHistory([
            ['pawn', 'white', 6, 4, 7, 4, null, null],
        ]);

        $state = $this->service()->applyStateChange($this->gameId, $this->whiteId, false, []);

        self::assertTrue($state['isPromoting']);
    }

    /**
     * @param array<int, array{int, int, int}> $pieces [row, col, code]
     */
    private function setupBoard(array $pieces): void
    {
        global $board, $numMoves;

        $numMoves = -1;
        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                $board[$i][$j] = 0;
            }
        }
        foreach ($pieces as [$row, $col, $code]) {
            $board[$row][$col] = $code;
        }

        \db_query('DELETE FROM ' . $this->piecesTable() . ' WHERE gameID = ?', [$this->gameId]);
        \saveGame();
    }

    /**
     * @param array<int, array<string, int|string|null>> $moves
     */
    private function persistHistory(array $moves): void
    {
        $sql = 'INSERT INTO ' . $this->historyTable()
            . ' (timeOfMove, gameID, curPiece, curColor, fromRow, fromCol, toRow, toCol, replaced, promotedTo, isInCheck)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)';
        $count = \count($moves);
        foreach ($moves as $index => [$piece, $color, $fromRow, $fromCol, $toRow, $toCol, $replaced, $promotedTo]) {
            /* stagger timestamps one second apart: the history primary key is
               (timeOfMove, gameID) and the app writes NOW(), so same-second
               rows collide (pre-existing data-model limitation). */
            $offset = $count - $index;
            $timeOfMove = \date('Y-m-d H:i:s', \time() - $offset);
            \db_query($sql, [$timeOfMove, $this->gameId, $piece, $color, $fromRow, $fromCol, $toRow, $toCol, $replaced, $promotedTo]);
        }
    }

    private function startStandardGame(): void
    {
        global $board, $numMoves;

        \initBoard();
        $numMoves = -1;
        \saveGame();
    }

    private function service(): GameService
    {
        return new GameService();
    }

    private function historyRow(int $index): ?array
    {
        $rows = \db_all(
            'SELECT * FROM ' . $this->historyTable() . ' WHERE gameID = ? ORDER BY timeOfMove',
            [$this->gameId]
        );

        return $rows[$index] ?? null;
    }

    private function pieceAt(int $row, int $col): ?array
    {
        return \db_row(
            'SELECT * FROM ' . $this->piecesTable() . ' WHERE gameID = ? AND `row` = ? AND col = ?',
            [$this->gameId, $row, $col]
        );
    }

    private function dbValue(string $sql, array $params): mixed
    {
        return \db_value($sql, $params);
    }

    private function gamesTable(): string
    {
        return $GLOBALS['CFG_TABLE']['games'];
    }

    private function historyTable(): string
    {
        return $GLOBALS['CFG_TABLE']['history'];
    }

    private function piecesTable(): string
    {
        return $GLOBALS['CFG_TABLE']['pieces'];
    }

    private function messagesTable(): string
    {
        return $GLOBALS['CFG_TABLE']['messages'];
    }
}