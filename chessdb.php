<?php
// $Id: chessdb.php,v 1.11 2013/12/07 20:00:00 gitjake Exp $

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

	/* these functions are used to interact with the DB.
	   All queries are parameterized through the PDO helpers in db.php. */
	function updateTimestamp()
	{
		global $CFG_TABLE;

		db_query("UPDATE " . $CFG_TABLE[games] . " SET lastMove = NOW() WHERE gameID = ?", [$_SESSION['gameID']]);
	}

	function loadHistory()
	{
		global $CFG_TABLE;
		global $history, $numMoves;

		$allMoves = db_query("SELECT * FROM " . $CFG_TABLE[history] . " WHERE gameID = ? ORDER BY timeOfMove", [$_SESSION['gameID']]);

		$numMoves = -1;
		while ($thisMove = $allMoves->fetch())
		{
			$numMoves++;
			$history[$numMoves] = $thisMove;
		}
	}

	function savePromotion()
	{
		global $CFG_TABLE;
		global $CFG_USEEMAILNOTIFICATION;
		global $history, $numMoves, $isInCheck;

		if ($isInCheck)
		{
			$tmpIsInCheck = 1;
			$history[$numMoves]['isInCheck'] = 1;
		}
		else
			$tmpIsInCheck = 0;

		$history[$numMoves]['promotedTo'] = getPieceName($_POST['promotion']);

		db_query(
			"UPDATE " . $CFG_TABLE[history] . " SET promotedTo = ?, isInCheck = ? WHERE gameID = ? AND timeOfMove = ?",
			[getPieceName($_POST['promotion']), $tmpIsInCheck, $_SESSION['gameID'], $history[$numMoves]['timeOfMove']]
		);

		updateTimestamp();

		/* if email notification is activated and move does not result in a pawn's promotion... */
		if ($CFG_USEEMAILNOTIFICATION && ! $_SESSION['isSharedPC'])
		{
			if ($history[$numMoves]['replaced'] == null)
				$tmpReplaced = '';
			else
				$tmpReplaced = $history[$numMoves]['replaced'];

			/* get opponent's color */
			if (($numMoves == -1) || ($numMoves % 2 == 1))
				$oppColor = "black";
			else
				$oppColor = "white";

			/* get opponent's player ID */
			if ($oppColor == 'white')
				$opponentID = db_value("SELECT whitePlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);
			else
				$opponentID = db_value("SELECT blackPlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);

			/* if opponent is using email notification... */
			$opponentEmail = db_value("SELECT value FROM " . $CFG_TABLE[preferences] . " WHERE playerID = ? AND preference = 'emailNotification'", [$opponentID]);
			if ($opponentEmail !== null)
			{
				if ($opponentEmail != '')
				{
					/* get opponent's nick */
					$opponentNick = db_value("SELECT nick FROM " . $CFG_TABLE[players] . " WHERE playerID = ?", [$_SESSION['playerID']]);

					/* get opponent's prefered history type */
					$opponentHistory = db_value("SELECT value FROM " . $CFG_TABLE[preferences] . " WHERE playerID = ? AND preference = 'history'", [$opponentID]);

					/* default to PGN */
					if ($opponentHistory === null)
						$opponentHistory = 'pgn';

					/* notify opponent of move via email */
					if ($opponentHistory == 'pgn')
						webchessMail('move', $opponentEmail, moveToPGNString($history[$numMoves]['curColor'], $history[$numMoves]['curPiece'], $history[$numMoves]['fromRow'], $history[$numMoves]['fromCol'], $history[$numMoves]['toRow'], $history[$numMoves]['toCol'], $tmpReplaced, $history[$numMoves]['promotedTo'], $isInCheck), $opponentNick, $_SESSION['gameID']);
					else
						webchessMail('move', $opponentEmail, moveToVerbousString($history[$numMoves]['curColor'], $history[$numMoves]['curPiece'], $history[$numMoves]['fromRow'], $history[$numMoves]['fromCol'], $history[$numMoves]['toRow'], $history[$numMoves]['toCol'], $tmpReplaced, $history[$numMoves]['promotedTo'], $isInCheck), $opponentNick, $_SESSION['gameID']);
				}
			}
		}
	}

	function saveHistory()
	{
		global $CFG_TABLE;
		global $board, $isPromoting, $history, $numMoves, $isInCheck, $CFG_USEEMAILNOTIFICATION;

		/* set destination row for pawn promotion */
		if ($board[$_POST['fromRow']][$_POST['fromCol']] & BLACK)
			$targetRow = 0;
		else
			$targetRow = 7;

		/* determine if move results in pawn promotion */
		if ((($board[$_POST['fromRow']][$_POST['fromCol']] & COLOR_MASK) == PAWN) && ($_POST['toRow'] == $targetRow))
			$isPromoting = true;
		else
			$isPromoting = false;

		/* determine who's playing based on number of moves so far */
		if (($numMoves == -1) || ($numMoves % 2 == 1))
		{
			$curColor = "white";
			$oppColor = "black";
		}
		else
		{
			$curColor = "black";
			$oppColor = "white";
		}

		/* add move to history */
		$numMoves++;
		$history[$numMoves]['gamedID'] = $_SESSION['gameID'];
		$history[$numMoves]['curPiece'] = getPieceName($board[$_POST['fromRow']][$_POST['fromCol']]);
		$history[$numMoves]['curColor'] = $curColor;
		$history[$numMoves]['fromRow'] = $_POST['fromRow'];
		$history[$numMoves]['fromCol'] = $_POST['fromCol'];
		$history[$numMoves]['toRow'] = $_POST['toRow'];
		$history[$numMoves]['toCol'] = $_POST['toCol'];
		$history[$numMoves]['promotedTo'] = null;

		if ($isInCheck)
			$history[$numMoves]['isInCheck'] = 1;
		else
			$history[$numMoves]['isInCheck'] = 0;

		if (DEBUG)
		{
			if ($history[$numMoves]['curPiece'] == '')
				echo ("WARNING!!!  missing piece at ".$_POST['fromRow'].", ".$_POST['fromCol'].": ".$board[$_POST['fromRow']][$_POST['fromCol']]."<p>\n");
		}

		if ($board[$_POST['toRow']][$_POST['toCol']] == 0)
		{
			$replaced = null;
			$history[$numMoves]['replaced'] = null;
			$tmpReplaced = "";
		}
		else
		{
			$replaced = getPieceName($board[$_POST['toRow']][$_POST['toCol']]);
			$history[$numMoves]['replaced'] = $replaced;
			$tmpReplaced = $replaced;
		}

		db_query(
			"INSERT INTO " . $CFG_TABLE[history] . " (timeOfMove, gameID, curPiece, curColor, fromRow, fromCol, toRow, toCol, replaced, promotedTo, isInCheck) VALUES (Now(), ?, ?, ?, ?, ?, ?, ?, ?, null, ?)",
			[
				$_SESSION['gameID'],
				getPieceName($board[$_POST['fromRow']][$_POST['fromCol']]),
				$curColor,
				$_POST['fromRow'],
				$_POST['fromCol'],
				$_POST['toRow'],
				$_POST['toCol'],
				$replaced,
				$history[$numMoves]['isInCheck'],
			]
		);

		/* if email notification is activated and move does not result in a pawn's promotion... */
		/* NOTE: moves resulting in pawn promotion are handled by savePromotion() above */
		if ($CFG_USEEMAILNOTIFICATION && !$isPromoting && ! $_SESSION['isSharedPC'])
		{
			/* get opponent's player ID */
			if ($oppColor == 'white')
				$opponentID = db_value("SELECT whitePlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);
			else
				$opponentID = db_value("SELECT blackPlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);

			/* if opponent is using email notification... */
			$opponentEmail = db_value("SELECT value FROM " . $CFG_TABLE[preferences] . " WHERE playerID = ? AND preference = 'emailNotification'", [$opponentID]);
			if ($opponentEmail !== null)
			{
				if ($opponentEmail != '')
				{
					/* get opponent's nick */
					$opponentNick = db_value("SELECT nick FROM " . $CFG_TABLE[players] . " WHERE playerID = ?", [$_SESSION['playerID']]);

					/* get opponent's prefered history type */
					$opponentHistory = db_value("SELECT value FROM " . $CFG_TABLE[preferences] . " WHERE playerID = ? AND preference = 'history'", [$opponentID]);

					/* default to PGN */
					if ($opponentHistory === null)
						$opponentHistory = 'pgn';

					/* notify opponent of move via email */
					if ($opponentHistory == 'pgn')
						webchessMail('move', $opponentEmail, moveToPGNString($history[$numMoves]['curColor'], $history[$numMoves]['curPiece'], $history[$numMoves]['fromRow'], $history[$numMoves]['fromCol'], $history[$numMoves]['toRow'], $history[$numMoves]['toCol'], $tmpReplaced, '', $isInCheck), $opponentNick, $_SESSION['gameID']);
					else
						webchessMail('move', $opponentEmail, moveToVerbousString($history[$numMoves]['curColor'], $history[$numMoves]['curPiece'], $history[$numMoves]['fromRow'], $history[$numMoves]['fromCol'], $history[$numMoves]['toRow'], $history[$numMoves]['toCol'], $tmpReplaced, '', $isInCheck), $opponentNick, $_SESSION['gameID']);
				}
			}
		}
	}

	function loadGame()
	{
		global $CFG_TABLE;
		global $board, $playersColor;

		/* clear board data */
		for ($i = 0; $i < 8; $i++)
			for ($j = 0; $j < 8; $j++)
				$board[$i][$j] = 0;

		/* get data from database */
		$pieces = db_query("SELECT * FROM " . $CFG_TABLE[pieces] . " WHERE gameID = ?", [$_SESSION['gameID']]);

		/* setup board */
		while ($thisPiece = $pieces->fetch())
		{
			$board[$thisPiece["row"]][$thisPiece["col"]] = getPieceCode($thisPiece["color"], $thisPiece["piece"]);
		}

		/* get current player's color */
		$tmpTurn = db_row("SELECT whitePlayer, blackPlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);

		if ($tmpTurn['whitePlayer'] == $_SESSION['playerID'])
			$playersColor = "white";
		else
			$playersColor = "black";
	}

	function saveGame()
	{
		global $CFG_TABLE;
		global $board, $playersColor;

		/* save new game data */
		$placeholders = array(); // parameter placeholders, one group per piece
		$params = array();       // bound values
		/* for each row... */
		for ($i = 0; $i < 8; $i++)
		{
			/* for each col... */
			for ($j = 0; $j < 8; $j++)
			{
				/* if there's a piece at that pos on the board */
				if ($board[$i][$j] != 0)
				{
					/* updated the database */
					if ($board[$i][$j] & BLACK)
						$tmpColor = "black";
					else
						$tmpColor = "white";

					$tmpPiece = getPieceName($board[$i][$j]);
					$placeholders[] = '(?, ?, ?, ?, ?)';
					array_push($params, $_SESSION['gameID'], $tmpColor, $tmpPiece, $i, $j);
				}
			}
		}
		/* clear old data */
		db_query("DELETE FROM " . $CFG_TABLE[pieces] . " WHERE gameID = ?", [$_SESSION['gameID']]);
		if (!empty($placeholders))
			db_query("INSERT INTO " . $CFG_TABLE[pieces] . ' (gameID, color, piece, `row`, `col`) VALUES ' . implode(',', $placeholders), $params);

		/* update lastMove timestamp */
		updateTimestamp();
	}

	function processMessages()
	{
		global $CFG_TABLE;
		global $isUndoRequested, $isDrawRequested, $isUndoing, $isGameOver, $isCheckMate, $playersColor, $numMoves, $statusMessage, $CFG_USEEMAILNOTIFICATION;

		if (DEBUG)
			echo("Entering processMessages()<br>\n");

		$isUndoRequested = false;
		$isGameOver = false;

		/* find out which player (black or white) we are serving */
		/* NOTE: When playing in the same computer $playersColor is always the player who logged in first */
		if (DEBUG)
			echo("SharedPC..." . $_SESSION['isSharedPC'] . "<br>\n");
		if ($_SESSION['isSharedPC'])	// Only the player to move is active in this case
			if( ( (($numMoves == -1) || (($numMoves % 2) == 1)) && ($playersColor == "white")) ||
				((($numMoves % 2) == 0) && ($playersColor == "black")) )
				$currentPlayer = $playersColor;
			else						// The player who logged in later is to move
				if($playersColor == "white")
					$currentPlayer = "black";
				else
					$currentPlayer = "white";
		else 							// The players are on different computers
			$currentPlayer = $playersColor;

		if ($currentPlayer == "white")
			$opponentColor = "black";
		else
			$opponentColor = "white";

		/* *********************************************** */
		/* queue user generated (ie: using forms) messages */
		/* *********************************************** */
		if (DEBUG)
			echo("Processing user generated (ie: form) messages...<br>\n");

		/* queue a request for an undo */
		if (isset($_POST['requestUndo']) && ($_POST['requestUndo'] == "yes"))
		{
			/* if the two players are on the same system, execute undo immediately */
			/* NOTE: assumes the two players discussed it live before undoing */
			if ($_SESSION['isSharedPC'])
				$isUndoing = true;
			else
			{
				db_query("INSERT INTO " . $CFG_TABLE[messages] . " (gameID, msgType, msgStatus, destination) VALUES (?, 'undo', 'request', ?)", [$_SESSION['gameID'], $opponentColor]);
                                // ToDo: Mail an undo request notice to other player??
			}

			updateTimestamp();
		}

		/* queue a request for a draw */
		if (isset($_POST['requestDraw']) && ($_POST['requestDraw'] == "yes"))
		{
			/* if the two players are on the same system, execute Draw immediately */
			/* NOTE: assumes the two players discussed it live before declaring the game a draw */
			if ($_SESSION['isSharedPC'])
			{
				db_query("UPDATE " . $CFG_TABLE[games] . " SET gameMessage = 'draw', messageFrom = ? WHERE gameID = ?", [$currentPlayer, $_SESSION['gameID']]);
			}
			else
			{
				db_query("INSERT INTO " . $CFG_TABLE[messages] . " (gameID, msgType, msgStatus, destination) VALUES (?, 'draw', 'request', ?)", [$_SESSION['gameID'], $opponentColor]);
			}

			updateTimestamp();
		}

		/* response to a request for an undo */
		if (isset($_POST['undoResponse']))
		{
			if ($_POST['isUndoResponseDone'] == 'yes')
			{
				if ($_POST['undoResponse'] == "yes")
				{
					$tmpStatus = "approved";
					$isUndoing = true;
				}
				else
					$tmpStatus = "denied";

				db_query("UPDATE " . $CFG_TABLE[messages] . " SET msgStatus = ?, destination = ? WHERE gameID = ? AND msgType = 'undo' AND msgStatus = 'request' AND destination = ?", [$tmpStatus, $opponentColor, $_SESSION['gameID'], $currentPlayer]);

				updateTimestamp();
			}
		}

		/* response to a request for a draw */
		if (isset($_POST['drawResponse']))
		{
			if ($_POST['isDrawResponseDone'] == 'yes')
			{
				if ($_POST['drawResponse'] == "yes")
				{
					$tmpStatus = "approved";
					db_query("UPDATE " . $CFG_TABLE[games] . " SET gameMessage = 'draw', messageFrom = ? WHERE gameID = ?", [$currentPlayer, $_SESSION['gameID']]);
				}
				else
					$tmpStatus = "denied";

				db_query("UPDATE " . $CFG_TABLE[messages] . " SET msgStatus = ?, destination = ? WHERE gameID = ? AND msgType = 'draw' AND msgStatus = 'request' AND destination = ?", [$tmpStatus, $opponentColor, $_SESSION['gameID'], $currentPlayer]);

				updateTimestamp();
			}
		}

		/* resign the game */
		if (isset($_POST['resign']) && ($_POST['resign'] == "yes"))
		{
			db_query("UPDATE " . $CFG_TABLE[games] . " SET gameMessage = 'playerResigned', messageFrom = ? WHERE gameID = ?", [$currentPlayer, $_SESSION['gameID']]);

			updateTimestamp();

			/* if email notification is activated... */
			if ($CFG_USEEMAILNOTIFICATION && ! $_SESSION['isSharedPC'])
			{
				/* get opponent's player ID */
				if ($currentPlayer == 'white')
					$opponentID = db_value("SELECT blackPlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);
				else
					$opponentID = db_value("SELECT whitePlayer FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);

				$opponentEmail = db_value("SELECT value FROM " . $CFG_TABLE[preferences] . " WHERE playerID = ? AND preference = 'emailNotification'", [$opponentID]);

				/* if opponent is using email notification... */
				if ($opponentEmail !== null)
				{
					if ($opponentEmail != '')
					{
						/* notify opponent of resignation via email */
						webchessMail('resignation', $opponentEmail, '', $_SESSION['nick'], $_SESSION['gameID']);
					}
				}
			}
		}


		/* ******************************************* */
		/* process queued messages (ie: from database) */
		/* ******************************************* */
		$tmpMessages = db_query("SELECT * FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND destination = ?", [$_SESSION['gameID'], $currentPlayer]);

		while($tmpMessage = $tmpMessages->fetch())
		{
			switch($tmpMessage['msgType'])
			{
				case 'undo':
					switch($tmpMessage['msgStatus'])
					{
						case 'request':
							$isUndoRequested = true;
							break;
						case 'approved':
							db_query("DELETE FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND msgType = 'undo' AND msgStatus = 'approved' AND destination = ?", [$_SESSION['gameID'], $currentPlayer]);
							$statusMessage .= "Undo approved";
							break;
						case 'denied':
							$isUndoing = false;
							db_query("DELETE FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND msgType = 'undo' AND msgStatus = 'denied' AND destination = ?", [$_SESSION['gameID'], $currentPlayer]);
							$statusMessage .= "Undo denied";
							break;
					}
					break;

				case 'draw':
					switch($tmpMessage['msgStatus'])
					{
						case 'request':
							$isDrawRequested = true;
							break;
						case 'approved':
							db_query("DELETE FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND msgType = 'draw' AND msgStatus = 'approved' AND destination = ?", [$_SESSION['gameID'], $currentPlayer]);
							$statusMessage .= "Draw approved";
							break;
						case 'denied':
							db_query("DELETE FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND msgType = 'draw' AND msgStatus = 'denied' AND destination = ?", [$_SESSION['gameID'], $currentPlayer]);
							$statusMessage .= "Draw denied";
							break;
					}
					break;
			}
		}

		/* requests pending */
		$tmpMessages = db_query("SELECT * FROM " . $CFG_TABLE[messages] . " WHERE gameID = ? AND msgStatus = 'request' AND destination = ?", [$_SESSION['gameID'], $opponentColor]);

		while($tmpMessage = $tmpMessages->fetch())
		{
			switch($tmpMessage['msgType'])
			{
				case 'undo':
					$statusMessage .= "Your undo request is pending";
					break;
				case 'draw':
					$statusMessage .= "Your request for a draw is pending";
					break;
			}
		}

		/* game level status: draws, resignations and checkmate */
		/* if checkmate, update games table */
		if (isset($_POST['isCheckMate']) && ($_POST['isCheckMate'] == 'true'))
			db_query("UPDATE " . $CFG_TABLE[games] . " SET gameMessage = 'checkMate', messageFrom = ? WHERE gameID = ?", [$currentPlayer, $_SESSION['gameID']]);
                        // ToDo: Mail checkmate notification to opponent

		$tmpMessage = db_row("SELECT gameMessage, messageFrom FROM " . $CFG_TABLE[games] . " WHERE gameID = ?", [$_SESSION['gameID']]);

		if ($tmpMessage['gameMessage'] == "draw")
		{
			$statusMessage .= "Game ended in a draw";
			$isGameOver = true;
		}

		if ($tmpMessage['gameMessage'] == "playerResigned")
		{
			$statusMessage .= $tmpMessage['messageFrom']." has resigned the game";
			$isGameOver = true;
		}

		if ($tmpMessage['gameMessage'] == "checkMate")
		{
			$statusMessage .= "Checkmate! ".$tmpMessage['messageFrom']." has won the game";
			$isGameOver = true;
			$isCheckMate = true;
		}
	}
?>
