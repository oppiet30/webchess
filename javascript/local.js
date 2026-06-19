/*
    Local hot-seat controller for WebChess.

    Drives a two-player game entirely in the browser — no login, no server, no
    database. It REUSES the existing engine: validation.js (move legality),
    isCheckMate.js (mate/stalemate), chessutils.js, squareclicked.js (click
    handling) and board.js (rendering). squareclicked.js calls localApplyMove()
    instead of submitting to the server when the global `localPlay` is true.
*/

var localGameOver = false;
var localCapt = [[], []];     // [0] = captured white pieces, [1] = captured black pieces
var localStack = [];          // snapshots for undo: {board, capt, over}

function localCloneBoard(b)
{
	var copy = [];
	for (var i = 0; i < 8; i++)
		copy[i] = b[i].slice();
	return copy;
}

function localSnapshot()
{
	localStack.push({
		board: localCloneBoard(board),
		capt: [localCapt[0].slice(), localCapt[1].slice()],
		over: localGameOver
	});
}

function localBindSquares()
{
	for (var i = 0; i < 64; i++)
	{
		var o = getObject('tsq' + i);
		if (o) o.onclick = function () { squareClicked(this); };
	}
}

function localUnbindSquares()
{
	for (var i = 0; i < 64; i++)
	{
		var o = getObject('tsq' + i);
		if (o) o.onclick = null;
	}
}

function localRedraw()
{
	getObject('chessboard').innerHTML = htmlBoard();
	if (localGameOver)
	{
		localUnbindSquares();
	}
	else
	{
		localBindSquares();
		if (typeof wcBindDnd === 'function')
			wcBindDnd();
	}
}

function localRenderCaptures()
{
	captPieces = localCapt;     // global consumed by displayCaptPieces()
	displayCaptPieces();
}

function localShowTurn()
{
	var curColor = ((numMoves == -1) || (numMoves % 2 == 1)) ? 'white' : 'black';
	getObject('whosmove').innerHTML = curColor.charAt(0).toUpperCase() + curColor.slice(1) + "'s turn";
}

function localApplyMove(fromRow, fromCol, toRow, toCol, thePiece, ennemyColor, epCol, capturedName, capturedColor)
{
	if (localGameOver) return;

	/* commit the move (chessHistory[numMoves+1] was filled by squareclicked.js) */
	numMoves++;
	is1stClick = true;

	/* pawn promotion: ask the player who just moved what to promote to
	   (during PGN import the choice comes from wcImportPromotion, no prompt) */
	var promotedTo = null;
	if (thePiece == 'pawn' && (toRow == 0 || toRow == 7))
	{
		var choice;
		if (typeof wcImportPromotion !== 'undefined' && wcImportPromotion)
			choice = wcImportPromotion.charAt(0).toUpperCase();
		else
			choice = (window.prompt('Promote to: Q, R, B or N', 'Q') || 'Q').toUpperCase().charAt(0);
		var code = QUEEN;
		if (choice == 'R') code = ROOK;
		else if (choice == 'B') code = BISHOP;
		else if (choice == 'N') code = KNIGHT;
		board[toRow][toCol] = code | (board[toRow][toCol] & BLACK);
		promotedTo = getPieceName(code);
	}

	/* track the captured piece for the captured-pieces panel */
	if (capturedName)
	{
		if (capturedColor == 'white') localCapt[0].push(capturedName);
		else localCapt[1].push(capturedName);
	}

	/* the side to move is now ennemyColor — report check / checkmate */
	var checkText = '';
	var isChecking = isInCheck(ennemyColor);
	if (isChecking)
	{
		if (isCheckMate(ennemyColor, epCol))
		{
			localGameOver = true;
			var winner = (ennemyColor == 'white') ? 'Black' : 'White';
			checkText = 'Checkmate! ' + winner + ' wins.';
		}
		else
		{
			checkText = ennemyColor.charAt(0).toUpperCase() + ennemyColor.slice(1) + ' is in check!';
		}
	}

	/* record the move for the history panel / PGN export (shared renderer) */
	if (typeof wcLocalRecordMove === 'function')
		wcLocalRecordMove({
			piece: thePiece, fromRow: fromRow, fromCol: fromCol,
			toRow: toRow, toCol: toCol, capture: !!capturedName,
			promotedTo: promotedTo, check: isChecking
		});

	localSnapshot();
	localRedraw();
	localRenderCaptures();

	getObject('checkmsg').innerHTML = checkText;
	if (localGameOver)
		getObject('whosmove').innerHTML = 'Game over';
	else
		localShowTurn();
}

function localUndo()
{
	if (localStack.length <= 1)
		return;                       // nothing before the initial position

	localStack.pop();                 // discard current state
	var prev = localStack[localStack.length - 1];

	board = localCloneBoard(prev.board);
	localCapt = [prev.capt[0].slice(), prev.capt[1].slice()];
	localGameOver = prev.over;
	numMoves--;
	chessHistory.length = numMoves + 1;
	is1stClick = true;

	if (typeof wcLocalUndoMove === 'function')
		wcLocalUndoMove();

	localRedraw();
	localRenderCaptures();
	getObject('checkmsg').innerHTML = '';
	localShowTurn();
}

function localNewGame()
{
	window.location.reload();
}

/* Reset to the standard starting position without reloading (used by PGN import). */
function localNewBoard()
{
	board = [];
	for (var i = 0; i < 8; i++)
	{
		board[i] = [];
		for (var j = 0; j < 8; j++)
			board[i][j] = 0;
	}
	var back = [ROOK, KNIGHT, BISHOP, QUEEN, KING, BISHOP, KNIGHT, ROOK];
	for (var c = 0; c < 8; c++)
	{
		board[0][c] = WHITE | back[c];
		board[1][c] = WHITE | PAWN;
		board[6][c] = BLACK | PAWN;
		board[7][c] = BLACK | back[c];
	}
	numMoves = -1;
	chessHistory = [];
	localCapt = [[], []];
	localGameOver = false;
	localStack = [];
	is1stClick = true;

	if (typeof wcLocalResetMoves === 'function')
		wcLocalResetMoves();

	localSnapshot();
	localRedraw();
	localRenderCaptures();
	getObject('checkmsg').innerHTML = '';
	localShowTurn();
}

/* Wire up the local controls after board.js has drawn the board. */
domready(function ()
{
	if (typeof localPlay === 'undefined' || !localPlay)
		return;

	var hideIds = ['btnDraw', 'btnResign', 'btnMainMenu', 'btnPGN', 'btnLogout'];
	for (var i = 0; i < hideIds.length; i++)
	{
		var o = getObject(hideIds[i]);
		if (o) o.style.display = 'none';
	}

	var r = getObject('btnReload');
	if (r) { r.value = 'New Game'; r.disabled = false; r.onclick = function () { localNewGame(); }; }

	var u = getObject('btnUndo');
	if (u) { u.value = 'Undo'; u.disabled = false; u.onclick = function () { localUndo(); }; }

	localSnapshot();          // initial position, so the first move can be undone
	localRenderCaptures();
	localShowTurn();
});
