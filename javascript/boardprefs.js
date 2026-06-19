/*
    Shared board-appearance preferences for WebChess.

    Board colour works on BOTH the online (chess.php) and local (local.php)
    boards. The piece set switcher is for local play only — online players pick
    their set in their account preferences (stored server-side). Everything here
    is client-side and persisted in localStorage; nothing is sent to the server.

    Requires domready.js. Include AFTER board.js (and local.js on the local page)
    so CURRENTTHEME / localRedraw() exist.
*/

(function () {
	var COLOR_KEY = 'wc_boardcolor';
	var SET_KEY   = 'wc_pieceset';
	var COLOR_CLASSES = ['bc-brown', 'bc-green'];

	function lsGet(k)    { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { } }

	function applyColor(scheme)
	{
		var b = document.body;
		for (var i = 0; i < COLOR_CLASSES.length; i++)
			b.className = b.className.replace(new RegExp('(^|\\s)' + COLOR_CLASSES[i] + '(\\s|$)', 'g'), ' ');
		if (scheme && scheme !== 'grey')
			b.className += ' bc-' + scheme;
		b.className = b.className.replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
	}

	function setPieceSet(theme)
	{
		if (typeof CURRENTTHEME === 'undefined')
			return;
		CURRENTTHEME = theme;
		var link = document.getElementById('themeCss');
		if (link)
			link.href = 'images/' + theme + '/wctheme.css';
		if (typeof localRedraw === 'function')
			localRedraw();          // local board: re-render with the new piece images
		lsSet(SET_KEY, theme);
	}

	window.wcBoardPrefs = { applyColor: applyColor, setPieceSet: setPieceSet };

	domready(function () {
		/* board colour — both boards */
		var color = lsGet(COLOR_KEY) || 'grey';
		applyColor(color);
		var cs = document.getElementById('boardColorSelect');
		if (cs)
		{
			cs.value = color;
			cs.onchange = function () { applyColor(this.value); lsSet(COLOR_KEY, this.value); };
		}

		/* piece set — local play only */
		if (typeof localPlay !== 'undefined' && localPlay)
		{
			var saved = lsGet(SET_KEY);
			if (saved)
				setPieceSet(saved);
			var ss = document.getElementById('pieceSetSelect');
			if (ss)
			{
				if (saved) ss.value = saved;
				ss.onchange = function () { setPieceSet(this.value); };
			}
		}
	});
})();
