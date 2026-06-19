<?php
// $Id: move.php,v 1.4 2010/08/14 16:57:54 sandking Exp $

/*
    This file is part of WebChess. https://github.com/thorium/webchess
	Copyright 2010 Jonathan Evraire, Rodrigo Flores

    WebChess is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    WebChess is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with WebChess.  If not, see <http://www.gnu.org/licenses/>.
*/

/* these functions deal specifically with moving a piece */

	/*
	 * Server-side validation of a castling move. Move legality in WebChess is
	 * otherwise checked in the browser (validation.js); a forged request that
	 * "castles" is uniquely dangerous because doMove() relocates whatever sits
	 * on the rook square, which can destroy pieces and corrupt the board. This
	 * enforces the structural castling rules on the server:
	 *   - the moving piece is a king stepping exactly two files from e-file,
	 *   - a friendly, un-moved rook actually sits on the corner,
	 *   - the king has not moved earlier in the game,
	 *   - the squares between king and rook are empty.
	 *
	 * (The "cannot castle out of / through / into check" rule still relies on
	 * the client, like every other check-related rule in the app.)
	 *
	 * Returns true if the castling move is structurally legal.
	 */
	function isValidCastlingServer($fromRow, $fromCol, $toRow, $toCol)
	{
		global $board, $history, $numMoves;

		$fromRow = (int)$fromRow; $fromCol = (int)$fromCol;
		$toRow   = (int)$toRow;   $toCol   = (int)$toCol;

		/* must be a king moving exactly two files along its own rank, from e */
		if ($fromRow != $toRow || $fromCol != 4 || abs($toCol - $fromCol) != 2)
			return false;

		$movingPiece = $board[$fromRow][$fromCol];
		if (($movingPiece & COLOR_MASK) != KING)
			return false;

		$color = $movingPiece & BLACK; /* 128 for black, 0 for white */

		/* king-side vs queen-side: rook corner, its destination, squares between */
		if ($toCol > $fromCol)
		{
			$rookCol = 7;
			$between = array(5, 6);
		}
		else
		{
			$rookCol = 0;
			$between = array(1, 2, 3);
		}

		/* a friendly rook must actually sit on the corner */
		$cornerPiece = $board[$fromRow][$rookCol];
		if (($cornerPiece & COLOR_MASK) != ROOK || ($cornerPiece & BLACK) != $color)
			return false;

		/* all squares between king and rook must be empty */
		foreach ($between as $c)
			if ($board[$fromRow][$c] != 0)
				return false;

		/* neither this king nor this rook may have moved earlier in the game */
		for ($i = 0; $i <= $numMoves; $i++)
		{
			$thisColor = ($history[$i]['curColor'] == 'black') ? BLACK : 0;
			if ($thisColor != $color)
				continue;

			if ($history[$i]['curPiece'] == 'king')
				return false; /* the king has already moved */

			if (((int)$history[$i]['fromRow'] == $fromRow) && ((int)$history[$i]['fromCol'] == $rookCol))
				return false; /* this rook has already moved */
		}

		return true;
	}

	/*
	 * Server-side validation of a pawn promotion. The client otherwise sends an
	 * arbitrary `promotion` value and target square, which let a forged request
	 * conjure pieces (even a second king) on any square. This enforces:
	 *   - the chosen piece is a real promotable piece (queen/rook/bishop/knight),
	 *   - a pawn actually sits on the target square,
	 *   - that square is a promotion rank (0 or 7).
	 */
	function isValidPromotionServer($toRow, $toCol, $promotion)
	{
		global $board;

		$toRow = (int)$toRow; $toCol = (int)$toCol; $promotion = (int)$promotion;

		if (!in_array($promotion, array(QUEEN, ROOK, KNIGHT, BISHOP), true))
			return false;

		if ($toRow < 0 || $toRow > 7 || $toCol < 0 || $toCol > 7)
			return false;

		if (($board[$toRow][$toCol] & COLOR_MASK) != PAWN)
			return false;

		if ($toRow != 0 && $toRow != 7)
			return false;

		return true;
	}

	/*
	 * Server-side validation of an en-passant capture. doMove() removes the pawn
	 * beside the destination whenever a pawn steps diagonally onto an empty
	 * square; a forged move could thus delete a pawn illegally. This confirms a
	 * genuine en passant: an enemy pawn beside us that, on the opponent's most
	 * recent move, advanced two squares onto that file.
	 */
	function isValidEnPassantServer($fromRow, $fromCol, $toRow, $toCol)
	{
		global $board, $history, $numMoves;

		$fromRow = (int)$fromRow; $fromCol = (int)$fromCol;
		$toRow   = (int)$toRow;   $toCol   = (int)$toCol;

		$mover = $board[$fromRow][$fromCol];
		if (($mover & COLOR_MASK) != PAWN)
			return false;
		if ($toCol == $fromCol)            /* not a diagonal move */
			return false;
		if ($board[$toRow][$toCol] != 0)   /* occupied => ordinary capture, not e.p. */
			return false;

		/* an enemy pawn must sit beside the mover, on the mover's own rank */
		$captured = $board[$fromRow][$toCol];
		if (($captured & COLOR_MASK) != PAWN)
			return false;
		if (($captured & BLACK) == ($mover & BLACK))
			return false;

		/* the opponent's last move must have been that pawn's two-square advance */
		if ($numMoves < 0)
			return false;
		$last = $history[$numMoves];
		if (($last['curPiece'] ?? '') != 'pawn')
			return false;
		if (abs((int)$last['toRow'] - (int)$last['fromRow']) != 2)
			return false;
		if ((int)$last['toRow'] != $fromRow || (int)$last['toCol'] != $toCol)
			return false;

		return true;
	}

	function doMove()
	{
		global $board, $isPromoting, $doUndo, $history, $numMoves;

		/* if moving en-passant */
		/* (ie: if pawn moves diagonally without replacing anything) */
		if ((($board[$_POST['fromRow']][$_POST['fromCol']] & COLOR_MASK) == PAWN) && ($_POST['toCol'] != $_POST['fromCol']) && ($board[$_POST['toRow']][$_POST['toCol']] == 0))
			/* delete eaten pawn */
			$board[$_POST['fromRow']][$_POST['toCol']] = 0;
		
		/* move piece to destination, replacing whatever's there */
		$board[$_POST['toRow']][$_POST['toCol']] = $board[$_POST['fromRow']][$_POST['fromCol']];

		/* delete piece from old position */
		$board[$_POST['fromRow']][$_POST['fromCol']] = 0;

		/* if not Undoing, but castling */
		if (($doUndo != "yes") && (($board[$_POST['toRow']][$_POST['toCol']] & COLOR_MASK) == KING) && (($_POST['toCol'] - $_POST['fromCol']) == 2))
		{
			/* castling to the right, move the right rook to the left side of the king */
			$board[$_POST['toRow']][5] = $board[$_POST['toRow']][7];

			/* delete rook from original position */
			$board[$_POST['toRow']][7] = 0;
		}
		elseif (($doUndo != "yes") && (($board[$_POST['toRow']][$_POST['toCol']] & COLOR_MASK) == KING) && (($_POST['fromCol'] - $_POST['toCol']) == 2))
		{
			/* castling to the left, move the left rook to the right side of the king */
			$board[$_POST['toRow']][3] = $board[$_POST['toRow']][0];

			/* delete rook from original position */
			$board[$_POST['toRow']][0] = 0;
		}

		return true;
	}
?>
