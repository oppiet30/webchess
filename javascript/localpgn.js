/*
    Move history + PGN export/import for local (hot-seat) play.

    Reuses the existing move-list renderer board.js::displayMoves() and the
    shared `moves` array; only the notation is generated here (the online game
    generates the same notation server-side in chessutils.php::moveToPGNString).

    Notation is WebChess long algebraic, e.g. "e2-e4", "Ng1-f3", "Bf1xc4",
    "e7-e8=Q", "O-O". Because every move carries its from-square explicitly, the
    importer can replay our own export reliably.
*/

var wcMoveLog = [];   /* one record per half-move: {piece,fromRow,fromCol,toRow,toCol,capture,promotedTo,check} */

function wcPgnCode(piece)
{
	switch (piece)
	{
		case 'knight': return 'N';
		case 'bishop': return 'B';
		case 'rook':   return 'R';
		case 'queen':  return 'Q';
		case 'king':   return 'K';
		default:       return '';
	}
}

function wcCodeToName(letter)
{
	switch (letter)
	{
		case 'N': return 'knight';
		case 'B': return 'bishop';
		case 'R': return 'rook';
		case 'Q': return 'queen';
		case 'K': return 'king';
		default:  return 'pawn';
	}
}

function wcSquare(col, row) { return String.fromCharCode(97 + col) + (row + 1); }

function wcMoveToString(m)
{
	var s = '';
	if (m.piece == 'king' && Math.abs(m.toCol - m.fromCol) == 2)
	{
		s = (m.toCol - m.fromCol == 2) ? 'O-O' : 'O-O-O';
	}
	else
	{
		s += wcPgnCode(m.piece);
		s += wcSquare(m.fromCol, m.fromRow);
		s += (m.capture || (m.piece == 'pawn' && m.fromCol != m.toCol)) ? 'x' : '-';
		s += wcSquare(m.toCol, m.toRow);
		if (m.promotedTo)
			s += '=' + wcPgnCode(m.promotedTo);
	}
	if (m.check)
		s += '+';
	return s;
}

/* Rebuild the shared `moves` array (pairs of [white, black]) and re-render. */
function wcRebuildMoves()
{
	moves = [];
	for (var i = 0; i < wcMoveLog.length; i++)
	{
		var s = wcMoveToString(wcMoveLog[i]);
		if (i % 2 === 0)
			moves.push([s, '']);
		else
			moves[moves.length - 1][1] = s;
	}
	if (typeof displayMoves === 'function')
		displayMoves();
}

function wcLocalRecordMove(rec) { wcMoveLog.push(rec); wcRebuildMoves(); }
function wcLocalUndoMove()      { wcMoveLog.pop(); wcRebuildMoves(); }
function wcLocalResetMoves()    { wcMoveLog = []; wcRebuildMoves(); }

function wcBuildPgn()
{
	var out = '[Event "WebChess local game"]\n[Site "WebChess"]\n[White "White"]\n[Black "Black"]\n[Result "*"]\n\n';
	var line = '';
	for (var i = 0; i < wcMoveLog.length; i++)
	{
		if (i % 2 === 0)
			line += (i / 2 + 1) + '. ';
		line += wcMoveToString(wcMoveLog[i]) + ' ';
	}
	return out + line.replace(/\s+$/, '') + (wcMoveLog.length ? ' *' : '*') + '\n';
}

/* Parse one WebChess long-algebraic token into {fromRow,fromCol,toRow,toCol,promo}. */
function wcParseToken(tok, whiteToMove)
{
	tok = tok.replace(/[+#]$/, '');
	if (tok === 'O-O' || tok === '0-0')
	{
		var r = whiteToMove ? 0 : 7;
		return { fromRow: r, fromCol: 4, toRow: r, toCol: 6, promo: null };
	}
	if (tok === 'O-O-O' || tok === '0-0-0')
	{
		var r2 = whiteToMove ? 0 : 7;
		return { fromRow: r2, fromCol: 4, toRow: r2, toCol: 2, promo: null };
	}
	/* optional piece letter, from-square, separator, to-square, optional =P */
	var m = tok.match(/^([NBRQK]?)([a-h])([1-8])[x-]([a-h])([1-8])(?:=([NBRQ]))?$/);
	if (!m)
		return null;
	return {
		fromCol: m[2].charCodeAt(0) - 97,
		fromRow: parseInt(m[3], 10) - 1,
		toCol:   m[4].charCodeAt(0) - 97,
		toRow:   parseInt(m[5], 10) - 1,
		promo:   m[6] || null
	};
}

/* Replay a PGN string into a fresh local game (reuses click validation/apply). */
function wcImportPgn(text)
{
	if (typeof localNewBoard !== 'function')
		return;

	/* strip headers, comments, result, move numbers -> bare move tokens */
	var body = text.replace(/\[[^\]]*\]/g, ' ')
	               .replace(/\{[^}]*\}/g, ' ')
	               .replace(/\d+\.(\.\.)?/g, ' ')
	               .replace(/(1-0|0-1|1\/2-1\/2|\*)/g, ' ');
	var tokens = body.split(/\s+/).filter(function (t) { return t.length > 0; });

	localNewBoard();   /* reset to the starting position */

	wcSuppressAlert = true;
	var applied = 0;
	for (var i = 0; i < tokens.length; i++)
	{
		var whiteToMove = ((numMoves == -1) || (numMoves % 2 == 1));
		var mv = wcParseToken(tokens[i], whiteToMove);
		if (!mv) { wcSuppressAlert = false; alert('Could not parse move ' + (i + 1) + ': "' + tokens[i] + '". Import stopped.'); return; }

		wcImportPromotion = mv.promo ? wcCodeToName(mv.promo) : null;
		var before = numMoves;
		is1stClick = true;
		squareClicked(getObject('tsq' + (mv.fromRow * 8 + mv.fromCol)));
		squareClicked(getObject('tsq' + (mv.toRow * 8 + mv.toCol)));
		wcImportPromotion = null;

		if (numMoves === before)   /* the move was rejected as illegal */
		{
			wcSuppressAlert = false;
			alert('Illegal move ' + (i + 1) + ': "' + tokens[i] + '". Import stopped.');
			return;
		}
		applied++;
	}
	wcSuppressAlert = false;
	alert(applied + ' move(s) imported.');
}

domready(function () {
	if (typeof localPlay === 'undefined' || !localPlay)
		return;

	var ex = document.getElementById('btnPgnExport');
	if (ex) ex.onclick = function () {
		var pgn = wcBuildPgn();
		var ta = document.getElementById('pgnText');
		if (ta) { ta.value = pgn; ta.focus(); ta.select(); }
	};

	var im = document.getElementById('btnPgnImport');
	if (im) im.onclick = function () {
		var ta = document.getElementById('pgnText');
		if (ta && ta.value.replace(/\s/g, '').length)
			wcImportPgn(ta.value);
		else
			alert('Paste a PGN into the box first.');
	};
});
