'use strict';

/*
 * Regression suite for the client-side chess engine. Runs the exact code the
 * browser uses (validation.js / isCheckMate.js / chessutils.js) under node --test.
 *
 * Board coordinates (as the engine and the gui.php JS contract use them):
 *   board[row][col], row = rank - 1, col = file (a=0 .. h=7).
 *   board[0] is White's back rank; white pawns move toward increasing row.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { loadEngine } = require('./loadEngine');

const e = loadEngine();

const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

/* square helper: sq(rank, file) -> [row, col] */
function sq(rank, file) {
  return [rank - 1, 'abcdefgh'.indexOf(file)];
}

function reset(fen) {
  e.FENToBoard(fen);
  e.numMoves = -1;
  e.chessHistory = [];
  e.errMsg = '';
}

function move(from, to, epCol) {
  return e.isValidMove(from[0], from[1], to[0], to[1], epCol);
}

test('FENToBoard maps the starting position', () => {
  reset(START_FEN);

  assert.equal(e.curColor, 'white');
  assert.equal(e.board[0][4], 32, 'Ke1');
  assert.equal(e.board[7][4], 32 | 128, 'ke8');
  assert.equal(e.board[1][0], 1, 'Pa2');
  assert.equal(e.board[6][7], 1 | 128, 'ph7');
});

test('white and black each have 20 legal moves from the start', () => {
  reset(START_FEN);

  assert.equal(e.countMoves('white'), 20);
  assert.equal(e.countMoves('black'), 20);
});

test('pawn movement', () => {
  reset(START_FEN);

  assert.equal(move(sq(2, 'e'), sq(3, 'e')), true, 'e2-e3');
  assert.equal(move(sq(2, 'e'), sq(4, 'e')), true, 'e2-e4');
  assert.equal(move(sq(2, 'e'), sq(5, 'e')), false, 'e2-e5 forbidden');
  assert.equal(move(sq(2, 'e'), sq(1, 'e')), false, 'pawns cannot move backwards');
  assert.equal(move(sq(2, 'e'), sq(3, 'f')), false, 'no capture available on f3');
  assert.equal(move(sq(7, 'a'), sq(5, 'a')), true, 'a7-a5 black double advance');
  assert.equal(move(sq(7, 'a'), sq(3, 'a')), false, 'a7-a4 forbidden');
});

test('pawn captures and en passant', () => {
  /* White pawn e5, black pawn d5 (just advanced d7-d5), en passant d6 available. */
  reset('4k3/8/8/3pP2/8/8/8/4K3 w - d6 0 1');
  assert.equal(e.isValidMove(4, 4, 5, 3, 3), true, 'e5xd6 en passant');
  assert.equal(e.isValidMove(4, 4, 5, 3, -1), false, 'en passant not available without the ep target');
  assert.equal(e.board[4][3], 1 | 128, 'captured pawn restored after validation');

  /* Regular diagonal capture: black pawn d5 takes the white pawn on e4. */
  reset('4k3/8/8/3p4/4P3/8/8/4K3 b - - 0 1');
  assert.equal(e.isValidMove(4, 3, 3, 4, -1), true, 'black d5xe4 capture');
});

test('king cannot move into check and check is detected', () => {
  /* Rook e8 pins the file in front of Ke1. */
  reset('4r2k/8/8/8/8/8/8/4K3 w - - 0 1');

  assert.equal(e.isInCheck('white'), true);
  assert.equal(move(sq(1, 'e'), sq(2, 'e')), false, 'e1-e2 into the rook file');
  assert.equal(move(sq(1, 'e'), sq(1, 'f')), true, 'e1-f1');
  assert.equal(move(sq(1, 'e'), sq(2, 'd')), true, 'e1-d2');
});

test('knight jumps from the starting position', () => {
  reset(START_FEN);
  assert.equal(move(sq(1, 'g'), sq(3, 'f')), true, 'Ng1-f3');
  assert.equal(move(sq(1, 'g'), sq(3, 'h')), true, 'Ng1-h3');
  assert.equal(move(sq(1, 'b'), sq(3, 'a')), true, 'Nb1-a3');
  assert.equal(move(sq(1, 'b'), sq(3, 'c')), true, 'Nb1-c3');
});

test('bishop is blocked by pieces on the same diagonal', () => {
  reset(START_FEN);
  assert.equal(move(sq(1, 'c'), sq(3, 'e')), false, 'Bc1-e3 blocked by d2 pawn');

  /* Free bishop; d3 pawn blocks the c2 diagonal. */
  reset('4k3/8/8/8/4B3/3P4/8/4K3 w - - 0 1');
  assert.equal(move(sq(4, 'e'), sq(5, 'f')), true, 'Be4-f5 clear diagonal');
  assert.equal(move(sq(4, 'e'), sq(6, 'g')), true, 'Be4-g6 clear diagonal');
  assert.equal(move(sq(4, 'e'), sq(2, 'c')), false, 'Be4-c2 blocked by d3 pawn');
  assert.equal(move(sq(4, 'e'), sq(4, 'h')), false, 'Be4-h4 not diagonal');
});

test('rook is blocked by pieces along the rank or file', () => {
  reset('4k3/8/8/8/8/8/8/R3K3 w - - 0 1');
  assert.equal(move(sq(1, 'a'), sq(1, 'c')), true, 'Ra1-c1 clear path');
  assert.equal(move(sq(1, 'a'), sq(8, 'a')), true, 'Ra1-a8 clear file');
  assert.equal(move(sq(1, 'a'), sq(1, 'f')), false, 'Ra1-f1 blocked by Ke1');
  assert.equal(move(sq(1, 'a'), sq(1, 'h')), false, 'Ra1-h1 blocked by Ke1');

  /* Black pawn on a5 blocks the a-file. */
  reset('4k3/8/8/8/p7/8/8/R3K3 w - - 0 1');
  assert.equal(move(sq(1, 'a'), sq(1, 'c')), true, 'Ra1-c1 still clear');
  assert.equal(move(sq(1, 'a'), sq(3, 'a')), true, 'Ra1-a3 before the pawn');
  assert.equal(move(sq(1, 'a'), sq(8, 'a')), false, 'Ra1-a8 blocked by a5 pawn');
});

test('queen combines rook and bishop movement', () => {
  reset(START_FEN);
  assert.equal(move(sq(1, 'd'), sq(3, 'd')), false, 'Qd1-d3 blocked by d2 pawn');
  assert.equal(move(sq(1, 'd'), sq(3, 'f')), false, 'Qd1-f3 blocked by e2 pawn');
  assert.equal(move(sq(1, 'd'), sq(2, 'c')), true, 'Qd1-c2 open diagonal');
  assert.equal(move(sq(1, 'd'), sq(1, 'c')), true, 'Qd1-c1 along open rank');
});

test('castling', () => {
  /* Full rights: both rooks present, nothing in between, e/f/g not attacked. */
  reset('r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1');
  assert.equal(move(sq(1, 'e'), sq(1, 'g')), true, 'white kingside');
  assert.equal(move(sq(1, 'e'), sq(1, 'c')), true, 'white queenside');
  assert.equal(move(sq(8, 'e'), sq(8, 'g')), true, 'black kingside');
  assert.equal(move(sq(8, 'e'), sq(8, 'c')), true, 'black queenside');

  /* No queenside rook: castling short must fail. */
  reset('r3k3/8/8/8/8/8/8/4K2R w K - 0 1');
  assert.equal(move(sq(1, 'e'), sq(1, 'c')), false, 'no rook on a1');

  /* King may not cross an attacked square: black rook f2 covers f1. */
  reset('r3k2r/8/8/8/8/8/5r2/4K2R w K - 0 1');
  assert.equal(move(sq(1, 'e'), sq(1, 'g')), false, 'f1 is attacked');

  /* Piece between king and rook. */
  reset('r3k2r/8/8/8/8/8/8/R3KB1R w KQ - 0 1');
  assert.equal(move(sq(1, 'e'), sq(1, 'g')), false, 'f1 occupied');
});

test('stalemate is detected as zero moves while not in check', () => {
  reset('7k/5Q2/8/8/8/8/8/7K b - - 0 1');

  assert.equal(e.isInCheck('black'), false);
  assert.equal(e.countMoves('black'), 0);
});

test('back-rank mate is detected', () => {
  /* Black Ka8: Ra1 checks along the a-file, Rb7 covers a7/b8 and is itself
     defended by Rb6 so the king cannot capture it. */
  reset('k7/1R6/1R6/8/8/8/8/R6K w - - 0 1');

  assert.equal(e.isInCheck('black'), true);
  assert.equal(e.isCheckMate('black', -1), true);
});

test('draw helpers', () => {
  assert.equal(e.isFiftyMoveDraw('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 100 1'), true);
  assert.equal(e.isFiftyMoveDraw('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 99 1'), false);

  const fen = '8/8/8/8/8/8/8/K6k w - - 0 1';
  assert.equal(e.isThirdTimePosDraw([fen, fen.replace(' 0 1', ' 5 1'), fen.replace(' 0 1', ' 9 1')]), true);
  assert.equal(e.isThirdTimePosDraw([fen, fen.replace(' 0 1', ' 5 1')]), false);
});