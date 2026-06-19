/*
    Drag-and-drop for the WebChess board (online and local).

    It does NOT reimplement move logic: a drop simply drives the existing
    click flow (squareClicked on the source square, then the target), so all
    validation, castling/en-passant handling, history and submit/local-apply
    behaviour are exactly the same as click-to-move. Clicking still works too.

    HTML5 drag-and-drop is a desktop affordance; on touch devices the existing
    tap-to-move continues to work. Requires domready.js and squareclicked.js;
    include AFTER board.js.
*/

(function () {
	var dragFrom = -1;

	function squareIndexOf(el)
	{
		while (el && !(el.id && el.id.indexOf('tsq') === 0))
			el = el.parentNode;
		return (el && el.id && el.id.indexOf('tsq') === 0) ? parseInt(el.id.slice(3), 10) : -1;
	}

	function interactive()
	{
		if (typeof localPlay !== 'undefined' && localPlay)
			return true;
		return (typeof isPlayersTurn !== 'undefined' && isPlayersTurn == '1');
	}

	function onDragStart(e)
	{
		var idx = squareIndexOf(e.target);
		if (idx < 0) { return; }
		dragFrom = idx;
		try { e.dataTransfer.setData('text/plain', String(idx)); e.dataTransfer.effectAllowed = 'move'; } catch (_) { }
	}

	function onDragOver(e)
	{
		e.preventDefault();
		if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
	}

	function onDrop(e)
	{
		e.preventDefault();
		var to = squareIndexOf(e.target);
		var from = dragFrom;
		dragFrom = -1;
		if (from < 0 || to < 0 || from === to)
			return;

		/* drive the normal click flow: pick up the source, then drop on target */
		is1stClick = true;
		squareClicked(getObject('tsq' + from));
		if (!is1stClick)                 /* source held a movable piece */
			squareClicked(getObject('tsq' + to));
	}

	window.wcBindDnd = function ()
	{
		if (!interactive())
			return;
		for (var i = 0; i < 64; i++)
		{
			var td = getObject('tsq' + i);
			if (!td) continue;
			td.ondragover = onDragOver;
			td.ondrop = onDrop;
			var img = td.getElementsByTagName('img')[0];
			if (img)
			{
				img.setAttribute('draggable', 'true');
				img.ondragstart = onDragStart;
			}
		}
	};

	domready(function () { wcBindDnd(); });
})();
