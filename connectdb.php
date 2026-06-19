<?php
	// $Id: connectdb.php,v 1.4 2010/08/14 16:57:54 sandking Exp $

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
	 * Backwards-compatible shim. The mysql_* extension was removed in PHP 7;
	 * database access now goes through the PDO-based helpers in db.php. Including
	 * this file keeps the historical `require 'connectdb.php'` call sites working
	 * while routing everything through the modern layer.
	 */
	require_once __DIR__ . '/db.php';
?>
