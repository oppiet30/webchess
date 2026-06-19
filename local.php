<?php
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

/*
 * Free, login-free, database-free two-player local (hot-seat) chess.
 *
 * This page reuses the existing client-side engine (validation.js, isCheckMate.js,
 * board.js, squareclicked.js, chessutils.js) but never touches the database or a
 * session: the whole game lives in the browser, driven by javascript/local.js.
 */

	/* load settings (constants, theme, helpers) — no session, no DB */
	if (!isset($_CONFIG)) {
		require 'config.php';
		include_once 'lang.php';
	}
	require 'chessconstants.php';
	if (!isset($_CHESSUTILS))
		require 'chessutils.php';
	require 'newgame.php';   /* initBoard() */
	require 'gui.php';       /* writeJSboard()/writeJSHistory()/drawboard()/... */

	/* the rendering helpers were written for the logged-in game; give them the
	   minimal, neutral state a fresh local game needs (no real session is used) */
	define('DEBUG', 0);
	$_SESSION['pref_theme']       = 'gnuchess_simple';
	$_SESSION['pref_history']      = 'pgn';
	$_SESSION['pref_historylayout'] = 'columns';
	$_SESSION['pref_autoreload']  = 0;
	$_SESSION['isSharedPC']       = true;   /* hot-seat: board is always interactive */
	$_SESSION['gameID']           = 0;
	$_SESSION['playerID']         = 0;

	$numMoves        = -1;
	$history         = array();
	$board           = array();
	$playersColor    = 'white';
	$isInCheck       = false;
	$isCheckMate     = false;
	$isPromoting     = false;
	$isGameOver      = false;
	$isUndoRequested = false;
	$isDrawRequested = false;
	$statusMessage   = '';

	initBoard();   /* set up the starting position in $board */
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="stylesheet" href="chess.css" type="text/css" />
<link rel="stylesheet" href="responsive.css" type="text/css" />
<link rel="stylesheet" href="boardcolors.css" type="text/css" />
<link id="themeCss" rel="stylesheet" href="images/<?php echo h($_SESSION['pref_theme']); ?>/wctheme.css" type="text/css" />
<title><?php echo APP_NAME . " :: " . gettext("Local 2-player"); ?></title>
<script type="text/javascript">
<?php
	echo("var cfgImageExt = '$CFG_IMAGE_EXT';\n");
	writeJSboard();
	echo("var autoreload = 0;\n");
	writeJSHistory();
	drawboard();
	echo "var localPlay = true;\n";
	echo "var gameId = 0;\n";
	echo 'var players = "' . gettext('White') . ' - ' . gettext('Black') . "\";\n";
	echo "var playersColor = \"white\";\n";
	echo "var isPromoting = \"\";\n";
	echo "var isKingInCheck = \"\";\n";
	echo "var isGameOver = \"\";\n";
	echo "var historyLayout = \"columns\";\n";
	echo "var captPieces = [[],[]];\n";   /* no captures yet; local.js maintains this */
?>
	var i18nMessages = {
		"Start of game": "<?php echo gettext('Start of game'); ?>"
		,"Start": "<?php echo gettext('Start'); ?>"
		,"Go back five halfmoves": "<?php echo gettext('Go back five halfmoves'); ?>"
		,"Go back one halfmove": "<?php echo gettext('Go back one halfmove'); ?>"
		,"Go forward one halfmove": "<?php echo gettext('Go forward one halfmove'); ?>"
		,"Go forward five halfmoves": "<?php echo gettext('Go forward five halfmoves'); ?>"
		,"End of game": "<?php echo gettext('End of game'); ?>"
		,"End": "<?php echo gettext('End'); ?>"
	};
<?php
	writeStatus();
	writeHistory();
?>
</script>
<script type="text/javascript" src="javascript/domready.js"></script>
<script type="text/javascript" src="javascript/chessutils.js"></script>
<script type="text/javascript" src="javascript/validation.js"></script>
<script type="text/javascript" src="javascript/isCheckMate.js"></script>
<script type="text/javascript" src="javascript/squareclicked.js"></script>
<script type="text/javascript" src="javascript/board.js"></script>
<script type="text/javascript" src="javascript/local.js"></script>
<script type="text/javascript" src="javascript/localpgn.js"></script>
<script type="text/javascript" src="javascript/dnd.js"></script>
<script type="text/javascript" src="javascript/boardprefs.js"></script>
</head>
<body>
<div id="wrapper">
	<div id="header">
	  <div id="heading"><?php echo APP_NAME; ?> :: <?php echo gettext("Local 2-player"); ?></div>
	</div>
	<div id="boardsection" align="center">
		<form name="gamedata" method="post" action="#" onsubmit="return false;">
		<div id="chessboard"></div>
		<div id="moveinfo">
			<div id="curmove">&nbsp;</div>
			<div id="whosmove">&nbsp;</div>
		</div>
		<div id="gamebuttons">
		<input type="button" id="btnUndo" class="button" value="Undo" disabled="disabled" />
		<input type="button" id="btnDraw" class="button" value="Request Draw" disabled="disabled" />
		<input type="button" id="btnResign" class="button" value="Resign" disabled="disabled" />
		</div>
		<input type="hidden" name="requestUndo" value="no" />
		<input type="hidden" name="requestDraw" value="no" />
		<input type="hidden" name="resign" value="no" />
		<input type="hidden" name="fromRow" value="" />
		<input type="hidden" name="fromCol" value="" />
		<input type="hidden" name="toRow" value="" />
		<input type="hidden" name="toCol" value="" />
		<input type="hidden" name="isInCheck" value="false" />
		<input type="hidden" name="isCheckMate" value="false" />
		</form>
		<div id="gamenav"></div>
		<div><?php echo gettext('When castling, just move the king (the rook will move automatically).'); ?></div>
		<div id="captheading"><?php echo gettext('Captured pieces'); ?></div>
		<div id="captures"></div>
	</div>

	<div id="content">
		<div id="appearance" style="text-align:center; padding:4px 0;">
			<div><label><?php echo gettext("Pieces"); ?>:
				<select id="pieceSetSelect">
					<option value="gnuchess_simple">GNU Chess Simple</option>
					<option value="gnuchess_fancy">GNU Chess Fancy</option>
					<option value="master">Master</option>
					<option value="beholder">Beholder</option>
				</select>
			</label></div>
			<div><label><?php echo gettext("Board"); ?>:
				<select id="boardColorSelect">
					<option value="grey"><?php echo gettext("Grey"); ?></option>
					<option value="brown"><?php echo gettext("Brown"); ?></option>
					<option value="green"><?php echo gettext("Green"); ?></option>
				</select>
			</label></div>
		</div>
		<div id="players" class="move_header"></div>
		<div id="gameid" class="move_header"></div>
		<div id="gamebody" class="move_header"></div>
		<div id="checkmsg"></div>
		<div id="statusmsg"></div>
		<form name="gamemenu" method="post" action="#" onsubmit="return false;" style="text-align:center;">
		<input type="button" id="btnReload" class="button" value="New Game" disabled="disabled" />
		<input type="button" id="btnMainMenu" class="button" value="Menu" disabled="disabled" />
		<input type="button" id="btnPGN" class="button" value="PGN" disabled="disabled" />
		<input type="button" id="btnLogout" class="button" value="Logout" disabled="disabled" />
		</form>
		<div id="pgnpanel" style="text-align:center; padding-top:8px;">
			<input type="button" id="btnPgnExport" class="button" value="<?php echo gettext("Export PGN"); ?>" />
			<input type="button" id="btnPgnImport" class="button" value="<?php echo gettext("Import PGN"); ?>" />
			<div><textarea id="pgnText" rows="6" style="width:95%; box-sizing:border-box;" placeholder="[Event ...] 1. e2-e4 ..."></textarea></div>
		</div>
		<div style="text-align:center; padding-top:8px;"><a href="index.php"><?php echo gettext("Back to login"); ?></a></div>
	</div>
	<?php include_once('footer.php'); ?>
</div>
</body>
</html>
