<?php

declare(strict_types=1);

namespace WebChess\Http;

use WebChess\Chess\PlayerColor;
use WebChess\Player\UserLevel;

/**
 * Handles the POST actions that mainmenu.php historically performed inline.
 *
 * This is a behavior-preserving extraction: the code below is the original
 * `switch ($_POST['ToDo'])` moved verbatim. It reads the $_SESSION / $_POST
 * superglobals, the $CFG_* config globals, and the global db_* / security /
 * game helpers exactly as before, so it must only be invoked after those are
 * loaded (see mainmenu.php for the canonical include order).
 *
 * Deliberately NOT covered here: the CSRF check and session/auth gating that
 * mainmenu.php performs before dispatching, and the page rendering.
 */
final class MainmenuController
{
    /**
     * Runs the requested action.
     *
     * @return array{tmpNewUser: bool, errMsg: string}
     */
    public function handle(string $todo): array
    {
        $tmpNewUser = false;
        $errMsg = '';

        switch ($todo) {
            case 'NewUser':
                /* create new player */
                $tmpNewUser = true;

                /* sanity check: empty nick */
                if (($_POST['txtNick'] ?? '') == '') {
                    \die('ERROR: must supply a valid nick!');
                }

                /* check for existing user with same nick */
                $existingUser = $this->dbRow(
                    'SELECT playerID FROM ' . $this->playersTable() . ' WHERE nick = ?',
                    [$_POST['txtNick']]
                );
                if ($existingUser !== null) {
                    require \dirname(__DIR__, 3) . '/newuser.php';
                    \die();
                }

                \db_query(
                    'INSERT INTO ' . $this->playersTable() . ' (password, firstName, lastName, nick) VALUES (?, ?, ?, ?)',
                    [\hash_password($_POST['pwdPassword'] ?? ''), $_POST['txtFirstName'] ?? '', $_POST['txtLastName'] ?? '', $_POST['txtNick'] ?? '']
                );

                /* get ID of new player; start a fresh session id to prevent fixation */
                \session_regenerate_id(true);
                $_SESSION['playerID'] = \db_insert_id();

                $prefInsert = 'INSERT INTO ' . $this->preferencesTable() . ' (playerID, preference, value) VALUES (?, ?, ?)';

                /* set History format preference */
                \db_query($prefInsert, [$_SESSION['playerID'], 'history', $_POST['rdoHistory'] ?? '']);

                /* set History layout preference */
                \db_query($prefInsert, [$_SESSION['playerID'], 'historylayout', $_POST['rdoHistorylayout'] ?? '']);

                /* set Theme preference */
                \db_query($prefInsert, [$_SESSION['playerID'], 'theme', $_POST['rdoTheme'] ?? '']);

                /* set Replay-all preference */
                \db_query($prefInsert, [$_SESSION['playerID'], 'replayall', $_POST['replayAll'] ?? '']);

                /* set auto-reload preference */
                if (\is_numeric($_POST['txtReload'] ?? '')) {
                    if (\intval($_POST['txtReload']) >= $this->config('CFG_MINAUTORELOAD')) {
                        \db_query($prefInsert, [$_SESSION['playerID'], 'autoreload', \intval($_POST['txtReload'])]);
                    } else {
                        \db_query($prefInsert, [$_SESSION['playerID'], 'autoreload', $this->config('CFG_MINAUTORELOAD')]);
                    }
                }

                /* set email notification preference */
                if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                    \db_query($prefInsert, [$_SESSION['playerID'], 'emailnotification', $_POST['txtEmailNotification'] ?? '']);
                }

                /* no break, login user */

            case 'Login':
                /* look up the player by nick, then verify the password in PHP */
                $tmpPlayer = $this->dbRow(
                    'SELECT * FROM ' . $this->playersTable() . ' WHERE nick = ?',
                    [$_POST['txtNick'] ?? '']
                );

                /* if the password checks out (legacy md5 hashes are supported), log him in... otherwise die */
                if ($tmpPlayer && \verify_password($_POST['pwdPassword'] ?? '', $tmpPlayer['password'])) {
                    /* transparently re-hash legacy/outdated password hashes on successful login */
                    if (\password_needs_upgrade($tmpPlayer['password'])) {
                        \db_query('UPDATE ' . $this->playersTable() . ' SET password = ? WHERE playerID = ?', [\hash_password($_POST['pwdPassword']), $tmpPlayer['playerID']]);
                    }

                    /* prevent session fixation: issue a fresh session id on login */
                    \session_regenerate_id(true);

                    $_SESSION['playerID'] = $tmpPlayer['playerID'];
                    $_SESSION['lastInputTime'] = \time();
                    $_SESSION['playerName'] = $tmpPlayer['firstName'] . ' ' . $tmpPlayer['lastName'];
                    $_SESSION['firstName'] = $tmpPlayer['firstName'];
                    $_SESSION['lastName'] = $tmpPlayer['lastName'];
                    $_SESSION['nick'] = $tmpPlayer['nick'];

                    $levelNumber = UserLevel::toLevelNumber((string) ($tmpPlayer['userlevel'] ?? ''));
                    if ($levelNumber !== '0') {
                        $_SESSION['userLevel'] = $levelNumber;
                    }
                } else {
                    echo "<script>alert('Invalid Nick or Password. Please try again'); window.location.replace('index.php');</script>\n";
                    \exit();
                }

                /* load user preferences */
                $tmpPreferences = $this->dbQuery('SELECT * FROM ' . $this->preferencesTable() . ' WHERE playerID = ?', [$_SESSION['playerID']]);

                $isPreferenceFound = [];
                foreach (['history', 'historylayout', 'theme', 'autoreload', 'emailnotification', 'replayall'] as $preferenceName) {
                    $isPreferenceFound[$preferenceName] = false;
                }

                while ($tmpPreference = $tmpPreferences->fetch()) {
                    switch ($tmpPreference['preference']) {
                        case 'history':
                        case 'historylayout':
                        case 'theme':
                        case 'replayall':
                            /* setup SESSION var of name pref_PREF, like pref_history */
                            $_SESSION['pref_' . $tmpPreference['preference']] = $tmpPreference['value'];
                            break;

                        case 'emailnotification':
                            if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                                $_SESSION['pref_emailnotification'] = $tmpPreference['value'];
                            }
                            break;

                        case 'autoreload':
                            if (\is_numeric($tmpPreference['value'])) {
                                $_SESSION['pref_autoreload'] = \intval($tmpPreference['value']) >= $this->config('CFG_MINAUTORELOAD')
                                    ? \intval($tmpPreference['value'])
                                    : $this->config('CFG_MINAUTORELOAD');
                            } else {
                                $_SESSION['pref_autoreload'] = $this->config('CFG_MINAUTORELOAD');
                            }
                            break;
                    }

                    $isPreferenceFound[$tmpPreference['preference']] = true;
                }

                /* look for missing preference and fix */
                foreach (\array_keys($isPreferenceFound, false) as $missingPref) {
                    $defaultValue = '';
                    switch ($missingPref) {
                        case 'history':
                            $defaultValue = 'pgn';
                            break;
                        case 'historylayout':
                            $defaultValue = 'columns';
                            break;
                        case 'theme':
                            $defaultValue = 'gnuchess_simple';
                            break;
                        case 'replayall':
                            $defaultValue = 'false';
                            break;
                        case 'autoreload':
                            $defaultValue = $this->config('CFG_MINAUTORELOAD');
                            break;
                        case 'emailnotification':
                            $defaultValue = '';
                            break;
                    }
                    \db_query('INSERT INTO ' . $this->preferencesTable() . ' (playerID, preference, value) VALUES (?, ?, ?)', [$_SESSION['playerID'], $missingPref, $defaultValue]);

                    /* setup SESSION var of name pref_PREF, like pref_history */
                    if ($this->config('CFG_USEEMAILNOTIFICATION') || ($missingPref !== 'emailnotification')) {
                        $_SESSION['pref_' . $missingPref] = $defaultValue;
                    }
                }

                break;

            case 'Logout':
                /* fully tear down the session on logout */
                $_SESSION = [];
                if (\ini_get('session.use_cookies')) {
                    $params = \session_get_cookie_params();
                    \setcookie(\session_name(), '', \time() - 42000,
                        $params['path'], $params['domain'],
                        $params['secure'], $params['httponly']);
                }
                \session_destroy();
                \header('Location: index.php');
                \exit();

            case 'InvitePlayer':
                /* prevent multiple pending requests between two players with the same originator */
                $tmpExistingRequest = $this->dbRow(
                    'SELECT gameID FROM ' . $this->gamesTable() . " WHERE gameMessage = 'playerInvited'"
                    . ' AND ((messageFrom = \'white\' AND whitePlayer = ? AND blackPlayer = ?)'
                    . ' OR (messageFrom = \'black\' AND whitePlayer = ? AND blackPlayer = ?))',
                    [$_SESSION['playerID'], $_POST['opponent'] ?? '', $_POST['opponent'] ?? '', $_SESSION['playerID']]
                );

                if ($tmpExistingRequest === null) {
                    $tmpColor = PlayerColor::resolve($_POST['color'] ?? 'random');

                    if ($tmpColor === 'white') {
                        $whitePlayer = $_SESSION['playerID'];
                        $blackPlayer = $_POST['opponent'] ?? '';
                    } else {
                        $whitePlayer = $_POST['opponent'] ?? '';
                        $blackPlayer = $_SESSION['playerID'];
                    }

                    \db_query(
                        "INSERT INTO " . $this->gamesTable() . " (whitePlayer, blackPlayer, gameMessage, messageFrom, dateCreated, lastMove) VALUES (?, ?, 'playerInvited', ?, NOW(), NOW())",
                        [$whitePlayer, $blackPlayer, $tmpColor]
                    );

                    /* if email notification is activated... */
                    if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                        /* if opponent is using email notification... */
                        $opponentEmail = $this->dbValue('SELECT value FROM ' . $this->preferencesTable() . " WHERE playerID = ? AND preference = 'emailNotification'", [$_POST['opponent'] ?? '']);
                        if ($opponentEmail !== null && $opponentEmail !== '') {
                            /* notify opponent of invitation via email */
                            \webchessMail('invitation', $opponentEmail, '', $_SESSION['nick'], '');
                        }
                    }
                }
                break;

            case 'ResponseToInvite':
                /* only a participant of the invited game may respond to it */
                \requirePlayerInGame($_POST['gameID'] ?? '');
                if (($_POST['response'] ?? '') === 'accepted') {
                    /* update game data */
                    \db_query("UPDATE " . $this->gamesTable() . " SET gameMessage = '', messageFrom = '' WHERE gameID = ?", [$_POST['gameID'] ?? '']);

                    /* setup new board */
                    $_SESSION['gameID'] = $_POST['gameID'] ?? '';
                    \createNewGame($_POST['gameID'] ?? '');
                    \saveGame();
                } else {
                    \db_query("UPDATE " . $this->gamesTable() . " SET gameMessage = 'inviteDeclined', messageFrom = ? WHERE gameID = ?", [$_POST['messageFrom'] ?? '', $_POST['gameID'] ?? '']);
                }

                break;

            case 'WithdrawRequest':
                /* only a participant of the game may withdraw/delete it */
                \requirePlayerInGame($_POST['gameID'] ?? '');

                /* get opponent's player ID */
                $opponentID = $this->dbValue('SELECT whitePlayer FROM ' . $this->gamesTable() . ' WHERE gameID = ?', [$_POST['gameID'] ?? '']);
                if ($opponentID !== null) {
                    if ($opponentID == $_SESSION['playerID']) {
                        $opponentID = $this->dbValue('SELECT blackPlayer FROM ' . $this->gamesTable() . ' WHERE gameID = ?', [$_POST['gameID'] ?? '']);
                    }

                    \db_query('DELETE FROM ' . $this->gamesTable() . ' WHERE gameID = ?', [$_POST['gameID'] ?? '']);

                    /* if email notification is activated... */
                    if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                        /* if opponent is using email notification... */
                        $opponentEmail = $this->dbValue('SELECT value FROM ' . $this->preferencesTable() . " WHERE playerID = ? AND preference = 'emailNotification'", [$opponentID]);
                        if ($opponentEmail !== null && $opponentEmail !== '') {
                            /* notify opponent of invitation via email */
                            \webchessMail('withdrawal', $opponentEmail, '', $_SESSION['nick'], $_POST['gameID'] ?? '');
                        }
                    }
                }
                break;

            case 'UpdatePersonalInfo':
                $dbPassword = $this->dbValue('SELECT password FROM ' . $this->playersTable() . ' WHERE playerID = ?', [$_SESSION['playerID']]);

                if ($dbPassword === null || !\verify_password($_POST['pwdOldPassword'] ?? '', $dbPassword)) {
                    $errMsg = 'Sorry, incorrect old password!';
                } else {
                    $tmpDoUpdate = true;

                    if ($this->config('CFG_NICKCHANGEALLOWED')) {
                        $existingUser = $this->dbRow(
                            'SELECT playerID FROM ' . $this->playersTable() . ' WHERE nick = ? AND playerID <> ?',
                            [$_POST['txtNick'] ?? '', $_SESSION['playerID']]
                        );

                        if ($existingUser !== null) {
                            $errMsg = 'Sorry, that nick is already in use.';
                            $tmpDoUpdate = false;
                        }
                    }

                    if ($tmpDoUpdate) {
                        /* update DB */
                        $updateSql = 'UPDATE ' . $this->playersTable() . ' SET firstName = ?, lastName = ?, password = ?';
                        $updateParams = [$_POST['txtFirstName'] ?? '', $_POST['txtLastName'] ?? '', \hash_password($_POST['pwdPassword'] ?? '')];

                        if ($this->config('CFG_NICKCHANGEALLOWED') && ($_POST['txtNick'] ?? '') !== '') {
                            $updateSql .= ', nick = ?';
                            $updateParams[] = $_POST['txtNick'];
                        }

                        $updateSql .= ' WHERE playerID = ?';
                        $updateParams[] = $_SESSION['playerID'];
                        \db_query($updateSql, $updateParams);

                        /* update current session */
                        $_SESSION['playerName'] = ($_POST['txtFirstName'] ?? '') . ' ' . ($_POST['txtLastName'] ?? '');
                        $_SESSION['firstName'] = $_POST['txtFirstName'] ?? '';
                        $_SESSION['lastName'] = $_POST['txtLastName'] ?? '';

                        if ($this->config('CFG_NICKCHANGEALLOWED') && ($_POST['txtNick'] ?? '') !== '') {
                            $_SESSION['nick'] = $_POST['txtNick'];
                        }
                    }
                }

                break;

            case 'UpdatePrefs':
                $prefUpdate = 'UPDATE ' . $this->preferencesTable() . ' SET value = ? WHERE playerID = ? AND preference = ?';

                /* Theme */
                \db_query($prefUpdate, [$_POST['rdoTheme'] ?? '', $_SESSION['playerID'], 'theme']);

                /* History format */
                \db_query($prefUpdate, [$_POST['rdoHistory'] ?? '', $_SESSION['playerID'], 'history']);

                /* History layout */
                \db_query($prefUpdate, [$_POST['rdoHistorylayout'] ?? '', $_SESSION['playerID'], 'historylayout']);

                /* Auto-Reload */
                if (\is_numeric($_POST['txtReload'] ?? '')) {
                    \db_query($prefUpdate, [\intval($_POST['txtReload']) >= $this->config('CFG_MINAUTORELOAD') ? \intval($_POST['txtReload']) : $this->config('CFG_MINAUTORELOAD'), $_SESSION['playerID'], 'autoreload']);
                }

                /* Email Notification */
                if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                    \db_query($prefUpdate, [$_POST['txtEmailNotification'] ?? '', $_SESSION['playerID'], 'emailnotification']);
                }

                /* User level */
                if (\is_numeric($_POST['userLevel'] ?? '')) {
                    $userleveltxt = UserLevel::toLabel((string) $_POST['userLevel']);

                    \db_query('UPDATE ' . $this->playersTable() . ' SET userlevel = ? WHERE playerID = ?', [$userleveltxt, $_SESSION['playerID']]);
                }

                /* Replay all */
                \db_query($prefUpdate, [$_POST['replayAll'] ?? '', $_SESSION['playerID'], 'replayall']);

                /* update current session */
                $_SESSION['pref_history'] = $_POST['rdoHistory'] ?? '';
                $_SESSION['pref_historylayout'] = $_POST['rdoHistorylayout'] ?? '';
                $_SESSION['pref_theme'] = $_POST['rdoTheme'] ?? '';
                $_SESSION['pref_replayall'] = $_POST['replayAll'] ?? '';
                $_SESSION['userLevel'] = $_POST['userLevel'] ?? '';

                if (\is_numeric($_POST['txtReload'] ?? '')) {
                    $_SESSION['pref_autoreload'] = \intval($_POST['txtReload']) >= $this->config('CFG_MINAUTORELOAD')
                        ? \intval($_POST['txtReload'])
                        : $this->config('CFG_MINAUTORELOAD');
                } else {
                    $_SESSION['pref_autoreload'] = $this->config('CFG_MINAUTORELOAD');
                }

                if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                    $_SESSION['pref_emailnotification'] = $_POST['txtEmailNotification'] ?? '';
                }
                break;

            case 'TestEmail':
                if ($this->config('CFG_USEEMAILNOTIFICATION')) {
                    \webchessMail('test', $_SESSION['pref_emailnotification'], '', '', '');
                }
                break;

            case 'HideMessage':
                /* a user may only archive messages addressed to them (or broadcasts) */
                \db_query("UPDATE " . $this->communicationTable() . " SET ack = 1 WHERE commID = ? AND (toID = ? OR toID IS NULL)", [$_POST['messageID'] ?? '', $_SESSION['playerID']]);
                break;
        }

        return ['tmpNewUser' => $tmpNewUser, 'errMsg' => $errMsg];
    }

    /**
     * @param list<mixed> $params
     */
    private function dbQuery(string $sql, array $params = []): \PDOStatement
    {
        return \db_query($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function dbRow(string $sql, array $params = []): ?array
    {
        return \db_row($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function dbValue(string $sql, array $params = [])
    {
        return \db_value($sql, $params);
    }

    private function playersTable(): string
    {
        return $this->cfgTable()['players'];
    }

    private function gamesTable(): string
    {
        return $this->cfgTable()['games'];
    }

    private function preferencesTable(): string
    {
        return $this->cfgTable()['preferences'];
    }

    private function communicationTable(): string
    {
        return $this->cfgTable()['communication'];
    }

    private function cfgTable(): array
    {
        /** @var array<string, string> $CFG_TABLE */
        global $CFG_TABLE;

        return $CFG_TABLE;
    }

    private function config(string $name): mixed
    {
        return $GLOBALS[$name] ?? null;
    }
}