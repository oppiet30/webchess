<?php

// exit('halt'); // script not needed anymore after install and configuration are complete

// $Id: makeConfig.php,v 1.11 2013/12/07 20:00:00 gitjake Exp $

/*
    This file is part of WebChess. https://github.com/thorium/webchess
	Copyright 2010 Jonathan Evraire, Rodrigo Flores, rigao

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

/* Safety guard: like install.php this can create a database user and emits a
 * config file, so it must not be reachable by default on a deployed site.
 * Enable explicitly only during installation, then remove the installer
 * scripts from the web root. */
if (getenv('WEBCHESS_ENABLE_INSTALLER') !== '1') {
	http_response_code(403);
	exit('The WebChess installer is disabled. Set WEBCHESS_ENABLE_INSTALLER=1 to enable it during installation, then remove install.php and makeConfig.php from your web root.');
}

//This file is called by install.php (case 3 of the switch, before it ends the installation
//procedure. It has two functions: first, to create a new user if told so. Second,
//to make the config.php file and put it for download.

//This function creates the user that will interact with the database.
//Normally it would be expected for this file to be located in install.php
//but coding the installer has yeld to the conclusion that it is easier to put it
//right here.
/* Identifiers (database/user names) cannot be passed as bound parameters, so
 * they are validated against a strict whitelist before being interpolated. */
function makeConfigValidIdentifier($name) {
   return is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
}

function createUser($new_user,$new_password,$user,$password,$server,$DBname){
   /* $DBname and $new_user are SQL identifiers and cannot be bound as
      parameters, so they are strictly whitelisted before interpolation. The
      password in IDENTIFIED BY likewise cannot be a placeholder, so it is
      escaped with PDO::quote(). */
   if (!makeConfigValidIdentifier($DBname) || !makeConfigValidIdentifier($new_user)) {
      return false;
   }
   try {
      $dsn = 'mysql:host=' . $server . ';dbname=' . $DBname . ';charset=utf8mb4';
      $pdo = new PDO($dsn, $user, $password, [
         PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      $quotedPassword = $pdo->quote($new_password);
      $query = "GRANT SELECT, INSERT, UPDATE, DELETE ON " . $DBname . ".* TO " . $new_user . " IDENTIFIED BY " . $quotedPassword;
      $pdo->exec($query);
   } catch (PDOException $e) {
      return false;
   }
   return true;
}

/* debug flag */
define ("DEBUG", 0);

header('Cache-Control: no-store, no-cache, must-revalidate'); // HTTP 1.1
header('Cache-Control: pre-check=0, post-check=0, max-age=0'); // HTTP 1.1
header('Pragma: no-cache'); // HTTP 1.0
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Transfer-Encoding: none');
header('Content-Type: text/php; name="config.php"');
header('Content-Disposition: attachment; filename="config.php');
// header("Content-length: $content_len");
//We see if the administrator has provided a new user to be used. If it is so
//(FALSE case) we will create the new user, if it is not (TRUE case) we won't
//create any user.
if (isset($_POST['reuse']) && $_POST['reuse']=='true')
{
   //In case we reuse, there is no need to create the new username.
   $user=$_POST['user_last'];
   $pass=$_POST['pass_last'];
   $new_user=$_POST['user_last'];
   $new_pass=$_POST['pass_last'];
} else {
   //We would need to create a new user.
   $new_user=$_POST['user'];
   $new_pass=$_POST['pass'];
   $user=$_POST['user_last'];
   $pass=$_POST['pass_last'];

   $result=createUser($new_user,$new_pass,$user,$pass,$_POST['server'],$_POST['DBname']);
   /*
    * This code is called to make config.php. There is no way to write a warning code
    * as it will be in the config.php. What we will do is post it as commented php
    * code insider config.php if there is an error. It is not perfect, but it is
    * the only solution I find.
    */
   if ($result==true)
   {
      //No need to mess with config.php if everything has gone allright.
      //echo "New user created correctly<br>";
   } else {
      //We write this sentence in config.php. There must be a FAQ entry to explain it.
      echo "/* There was a <b><u>problem</u></b> and the new user was not created */\n";
   }
}

//Here we start the second part. We start to generate config.php.
echo "<?php";
echo "\$_CONFIG=true;\n\n";

echo "/* database settings */\n";
echo "\$CFG_SERVER = '".$_POST['server']."';\n";
echo "\$CFG_USER = '".$new_user."';\n";
echo "\$CFG_PASSWORD = '".$new_pass."';\n";
echo "\$CFG_DATABASE = '".$_POST['DBname']."';\n";
echo "\n/* server settings */\n";

echo "\$CFG_SESSIONTIMEOUT = ".$_POST['timeout'].";\n";
echo "\$CFG_EXPIREGAME = ".$_POST['expire'].";\n";
echo "\$CFG_MINAUTORELOAD = ".$_POST['autoreload'].";\n";


echo "\$CFG_USEEMAILNOTIFICATION = ";
if (isset($_POST['mail_not']) &&  $_POST['mail_not']=='1')
   echo "TRUE;\n";
else echo "FALSE;\n";

echo "\$CFG_MAILADDRESS = '".$_POST['mail_adr']."';\n";

echo "\$CFG_MAINPAGE = '".$_POST['url']."';\n";
echo "\$CFG_MAXUSERS = ".$_POST['maxUsers'].";\n";
echo "\$CFG_MAXACTIVEGAMES = ".$_POST['maxGames'].";\n";
echo "\$CFG_NICKCHANGEALLOWED = ";
if (isset($_POST['changeNick']) &&  $_POST['changeNick']=='1')
   echo "TRUE;\n";
else echo "FALSE;\n";

echo "\$CFG_NEW_USERS_ALLOWED = ";
if (isset($_POST['newUsers']) &&  $_POST['newUsers']=='1')
   echo "TRUE;\n";
else echo "FALSE;\n";

echo "\$CFG_BOARDSQUARESIZE = ".$_POST['size'].";\n";

?>

/* Application constants */
define('APP_NAME', 'WebChess'); // The name of the app that is shown in the title
define('APP_VERSION', '1.0.4'); // The version of the app

/* I18N constants */
define('I18N_GETTEXT_SUPPORT', false); // enable gettext for fetching translations
define('I18N_LOCALE', 'de_DE'); // locale to use (requires the webchess.mo file for the locale)

/* mysql table names */
define('communication', 'communication');
define('history', 'history');
define('games', 'games');
define('messages', 'messages');
define('pieces', 'pieces');
define('preferences', 'preferences');
define('players', 'players');

/* mysql table names */
$CFG_TABLE[communication] = "communication";
$CFG_TABLE[games] = "games";
$CFG_TABLE[history] = "history";
$CFG_TABLE[messages] = "messages";
$CFG_TABLE[pieces] = "pieces";
$CFG_TABLE[players] = "players";
$CFG_TABLE[preferences] = "preferences";

<?php
echo "\$CFG_IMAGE_EXT = '".$_POST['imageExtension']."';\n";
echo "\n/* shared security helpers (escaping, CSRF, hardened sessions, password hashing) */\n";
echo "require_once __DIR__ . '/security.php';\n";
echo "?>";
?>
