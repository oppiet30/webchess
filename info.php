<?php

/* connect to database */
require 'connectdb.php';
$viewGame = 0;
if (isset($_SESSION['ViewGame'])) {
	$viewGame = $_SESSION['ViewGame'];
}
$f=db_query("select gameID, p1.nick as white, p2.nick as black, lastMove from (games inner join players as p1 on whitePlayer=p1.playerID) inner join players p2 on blackPlayer=p2.playerID where gameID <=? and dateCreated != lastMove order by gameID DESC limit 0,1", [$viewGame]);


$c=0;
$d=0;

while($row=$f->fetch()){
	$_SESSION['ViewGame']=$row['gameID'];
	echo "Game ".h($row['gameID']).": White ".h($row['white'])." versus black ".h($row['black'])." ".h($row['lastMove']);
}
?>
