/*
    Move history + PGN export/import for local (hot-seat) play.

    Reuses the existing move-list renderer board.js::displayMoves() and the
    shared `moves` array; only the notation is generated here (the online game
    generates the same notation server-side in chessutils.php::moveToPGNString).

    Export is standard SAN, e.g. "e4", "Nf3", "exd5", "Nbd2", "R1e2", "e8=Q",
    "O-O" — the same notation the server-side .pgn export emits
    (gui.php::writePGN, served by openpgn.php). Disambiguation is computed by
    replaying the move log (wcMoveLogToSanList), since it depends on the position
    before each move.

    Import (wcResolveMove) is tolerant: it accepts that SAN, our own export, and
    PGNs from lichess / chess.com, and it also still reads the old WebChess long
    algebraic ("e2-e4", "Ng1-f3"). The from-square is resolved by asking the
    engine which piece can legally reach the destination, so SAN's implicit
    disambiguation needs no explicit origin.
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
function wcFile(col)        { return String.fromCharCode(97 + col); }

function wcNameToCode(name)
{
	switch (name)
	{
		case 'knight': return KNIGHT;
		case 'bishop': return BISHOP;
		case 'rook':   return ROOK;
		case 'queen':  return QUEEN;
		case 'king':   return KING;
		default:       return PAWN;
	}
}

/* ---- Standard SAN export ------------------------------------------------ *
   SAN disambiguation (e.g. Nbd2 vs Nfd2) depends on the position as it was
   BEFORE each move, so we replay wcMoveLog from the start on a private board
   rather than reading the live one. The same SAN powers both the move-list
   panel and the exported PGN, and round-trips back through wcResolveMove. */

function wcFreshBoard()
{
	var b = [];
	for (var i = 0; i < 8; i++) { b[i] = []; for (var j = 0; j < 8; j++) b[i][j] = 0; }
	var back = [ROOK, KNIGHT, BISHOP, QUEEN, KING, BISHOP, KNIGHT, ROOK];
	for (var c = 0; c < 8; c++)
	{
		b[0][c] = WHITE | back[c]; b[1][c] = WHITE | PAWN;
		b[6][c] = BLACK | PAWN;    b[7][c] = BLACK | back[c];
	}
	return b;
}

/* every square strictly between (fr,fc) and (tr,tc) is empty */
function wcPathClear(b, fr, fc, tr, tc)
{
	var sr = (tr > fr) ? 1 : (tr < fr ? -1 : 0);
	var sc = (tc > fc) ? 1 : (tc < fc ? -1 : 0);
	var r = fr + sr, c = fc + sc;
	while (r != tr || c != tc)
	{
		if (b[r][c] != 0) return false;
		r += sr; c += sc;
	}
	return true;
}

/* Pseudo-legal reachability of the piece on (fr,fc) to (tr,tc); ignores pins,
   which is enough for SAN disambiguation (a redundant hint is still valid SAN). */
function wcCanReach(b, fr, fc, tr, tc)
{
	var dr = tr - fr, dc = tc - fc;
	var adr = Math.abs(dr), adc = Math.abs(dc);
	switch (b[fr][fc] & COLOR_MASK)
	{
		case KNIGHT: return (adr == 1 && adc == 2) || (adr == 2 && adc == 1);
		case KING:   return adr <= 1 && adc <= 1;
		case BISHOP: return adr == adc && wcPathClear(b, fr, fc, tr, tc);
		case ROOK:   return (dr == 0 || dc == 0) && wcPathClear(b, fr, fc, tr, tc);
		case QUEEN:  return (adr == adc || dr == 0 || dc == 0) && wcPathClear(b, fr, fc, tr, tc);
	}
	return false;
}

/* SAN disambiguation suffix for a piece (non-pawn) move on board b */
function wcDisambig(b, m)
{
	var myCode  = b[m.fromRow][m.fromCol] & COLOR_MASK;
	var myColor = b[m.fromRow][m.fromCol] & BLACK;
	var sameFile = false, sameRank = false, any = false;
	for (var r = 0; r < 8; r++)
		for (var c = 0; c < 8; c++)
		{
			if (r == m.fromRow && c == m.fromCol) continue;
			var pc = b[r][c];
			if (pc == 0 || (pc & COLOR_MASK) != myCode || (pc & BLACK) != myColor) continue;
			if (!wcCanReach(b, r, c, m.toRow, m.toCol)) continue;
			any = true;
			if (c == m.fromCol) sameFile = true;
			if (r == m.fromRow) sameRank = true;
		}
	if (!any) return '';
	if (!sameFile) return wcFile(m.fromCol);
	if (!sameRank) return String(m.fromRow + 1);
	return wcFile(m.fromCol) + (m.fromRow + 1);
}

/* one move record -> SAN, using board b as it stands BEFORE the move */
function wcMoveToSan(b, m)
{
	if (m.piece == 'king' && Math.abs(m.toCol - m.fromCol) == 2)
		return ((m.toCol - m.fromCol == 2) ? 'O-O' : 'O-O-O') + (m.check ? '+' : '');

	var capture = m.capture || (m.piece == 'pawn' && m.fromCol != m.toCol);
	var s = '';
	if (m.piece == 'pawn')
	{
		if (capture) s += wcFile(m.fromCol) + 'x';
		s += wcSquare(m.toCol, m.toRow);
		if (m.promotedTo) s += '=' + wcPgnCode(m.promotedTo);
	}
	else
	{
		s += wcPgnCode(m.piece) + wcDisambig(b, m) + (capture ? 'x' : '') + wcSquare(m.toCol, m.toRow);
	}
	if (m.check) s += '+';
	return s;
}

/* advance board b by one move record (handles capture, en passant, castling, promotion) */
function wcApplyOnBoard(b, m)
{
	var pc = b[m.fromRow][m.fromCol];
	var colorBit = pc & BLACK;
	if (m.piece == 'pawn' && m.fromCol != m.toCol && b[m.toRow][m.toCol] == 0)
		b[m.fromRow][m.toCol] = 0;            /* en passant: remove the passed pawn */
	b[m.fromRow][m.fromCol] = 0;
	b[m.toRow][m.toCol] = m.promotedTo ? (colorBit | wcNameToCode(m.promotedTo)) : pc;
	if (m.piece == 'king' && Math.abs(m.toCol - m.fromCol) == 2)
	{
		if (m.toCol - m.fromCol == 2) { b[m.fromRow][5] = b[m.fromRow][7]; b[m.fromRow][7] = 0; }
		else                          { b[m.fromRow][3] = b[m.fromRow][0]; b[m.fromRow][0] = 0; }
	}
}

/* replay the whole log, returning one SAN string per half-move */
function wcMoveLogToSanList()
{
	var b = wcFreshBoard();
	var list = [];
	for (var i = 0; i < wcMoveLog.length; i++)
	{
		list.push(wcMoveToSan(b, wcMoveLog[i]));
		wcApplyOnBoard(b, wcMoveLog[i]);
	}
	return list;
}

/* Rebuild the shared `moves` array (pairs of [white, black]) and re-render. */
function wcRebuildMoves()
{
	var sans = wcMoveLogToSanList();
	moves = [];
	for (var i = 0; i < sans.length; i++)
	{
		if (i % 2 === 0)
			moves.push([sans[i], '']);
		else
			moves[moves.length - 1][1] = sans[i];
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
	var sans = wcMoveLogToSanList();
	var line = '';
	for (var i = 0; i < sans.length; i++)
	{
		if (i % 2 === 0)
			line += (i / 2 + 1) + '. ';
		line += sans[i] + ' ';
	}
	return out + line.replace(/\s+$/, '') + (sans.length ? ' *' : '*') + '\n';
}

/* Resolve one move token to {fromRow,fromCol,toRow,toCol,promo} against the
   current board, or {err:"..."} when it can't be understood/played.

   Accepts both notations:
     - WebChess long algebraic (our own export):  e2-e4, Ng1-f3, Bf1xc4, e7-e8=Q
     - Standard SAN (server .pgn export, lichess, chess.com): e4, Nf3, exd5,
       Nbd2, R1e2, Qh4xe1, e8=Q, O-O ...
   In both cases the from-square is found by asking the engine which piece of the
   right type/colour can legally reach the destination (isValidMove handles pins
   and en passant), so SAN's implicit/partial disambiguation just works. */
function wcResolveMove(rawTok, whiteToMove)
{
	var color = whiteToMove ? 'white' : 'black';
	var tok = rawTok.replace(/[+#!?]+$/, '');   /* drop check/mate/annotation glyphs */

	/* castling (letter O or digit 0) */
	if (tok === 'O-O-O' || tok === '0-0-0')
	{
		var rq = whiteToMove ? 0 : 7;
		return { fromRow: rq, fromCol: 4, toRow: rq, toCol: 2, promo: null };
	}
	if (tok === 'O-O' || tok === '0-0')
	{
		var rk = whiteToMove ? 0 : 7;
		return { fromRow: rk, fromCol: 4, toRow: rk, toCol: 6, promo: null };
	}

	/* trailing promotion piece, with or without '=' (a normal move ends in a
	   digit, so a trailing letter here is unambiguously the promotion piece) */
	var promo = null;
	var pm = tok.match(/=?([QRBNqrbn])$/);
	if (pm)
	{
		promo = pm[1].toUpperCase();
		tok = tok.slice(0, tok.length - pm[0].length);
	}

	/* destination is always the last two characters once suffixes are stripped */
	var dest = tok.slice(-2);
	if (!/^[a-h][1-8]$/.test(dest))
		return { err: 'Could not parse move' };
	var toCol = dest.charCodeAt(0) - 97;
	var toRow = parseInt(dest.charAt(1), 10) - 1;

	var middle = tok.slice(0, tok.length - 2);

	/* optional leading piece letter (uppercase KQRBN); absent => pawn */
	var pieceType = 'pawn';
	if (/^[KQRBN]/.test(middle))
	{
		pieceType = wcCodeToName(middle.charAt(0));
		middle = middle.slice(1);
	}

	/* whatever remains is optional disambiguation (a file and/or rank) plus the
	   capture marker x/-, which we ignore for resolution */
	var discFile = -1, discRank = -1;
	var fm = middle.match(/[a-h]/);
	if (fm) discFile = fm[0].charCodeAt(0) - 97;
	var rm = middle.match(/[1-8]/);
	if (rm) discRank = parseInt(rm[0], 10) - 1;

	/* find every piece of the right type/colour that can legally reach dest */
	var candidates = [];
	for (var r = 0; r < 8; r++)
		for (var c = 0; c < 8; c++)
		{
			var pc = board[r][c];
			if (pc == 0 || getPieceColor(pc) != color || getPieceName(pc) != pieceType)
				continue;
			if (discFile != -1 && c != discFile) continue;
			if (discRank != -1 && r != discRank) continue;
			if (isValidMove(r, c, toRow, toCol))
				candidates.push([r, c]);
		}

	if (candidates.length === 0)
		return { err: 'Illegal or unresolved move' };
	if (candidates.length > 1)
		return { err: 'Ambiguous move' };

	return {
		fromRow: candidates[0][0], fromCol: candidates[0][1],
		toRow: toRow, toCol: toCol, promo: promo
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
		var mv = wcResolveMove(tokens[i], whiteToMove);
		if (mv.err) { wcSuppressAlert = false; alert(mv.err + ' ' + (i + 1) + ': "' + tokens[i] + '". Import stopped.'); return; }

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
