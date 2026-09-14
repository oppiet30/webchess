<?php

declare(strict_types=1);

namespace WebChess\Tests\Http;

use PHPUnit\Framework\TestCase;
use WebChess\Http\MainmenuController;

/**
 * DB-backed integration tests for MainmenuController, mirroring the POST
 * actions mainmenu.php used to perform inline. Skipped unless the
 * WEBCHESS_DB_* environment variables are set (CI has no database).
 */
final class MainmenuControllerTest extends TestCase
{
    protected function setUp(): void
    {
        TestDatabase::boot();

        if (!TestDatabase::isAvailable()) {
            self::markTestSkipped(TestDatabase::reason());
        }

        $tables = $GLOBALS['CFG_TABLE'];
        foreach (['history', 'pieces', 'communication', 'messages', 'games', 'preferences', 'players'] as $table) {
            \db_query('TRUNCATE TABLE ' . $tables[$table]);
        }

        $_SESSION = [];
        $GLOBALS['_SESSION'] = &$_SESSION;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
    }

    public function testNewUserCreatesPlayerAndPrefersAndLogsIn(): void
    {
        $_POST = [
            'ToDo' => 'NewUser',
            'txtNick' => 'alice',
            'txtFirstName' => 'Alice',
            'txtLastName' => 'Wonder',
            'pwdPassword' => 'secret',
            'rdoHistory' => 'pgn',
            'rdoHistorylayout' => 'columns',
            'rdoTheme' => 'gnuchess_simple',
            'replayAll' => 'false',
            'txtReload' => '10',
        ];

        $outcome = (new MainmenuController())->handle('NewUser');

        self::assertTrue($outcome['tmpNewUser']);
        self::assertEmpty($outcome['errMsg']);

        $row = $this->dbRow('SELECT playerID, password FROM players WHERE nick = ?', ['alice']);
        self::assertNotNull($row);
        self::assertSame((string) $_SESSION['playerID'], (string) $row['playerID']);
        self::assertStringStartsWith('$2y$', (string) $row['password']);
        self::assertTrue(\verify_password('secret', (string) $row['password']));

        /* the fall-through login populated the session with defaults */
        self::assertSame('alice', $_SESSION['nick']);
        self::assertSame('pgn', $_SESSION['pref_history']);
        self::assertSame('columns', $_SESSION['pref_historylayout']);
        self::assertSame('gnuchess_simple', $_SESSION['pref_theme']);
        self::assertSame('10', (string) $_SESSION['pref_autoreload']);

        $prefs = $this->dbAll('SELECT preference, value FROM preferences WHERE playerID = ?', [$_SESSION['playerID']]);
        $prefMap = array_column($prefs, 'value', 'preference');
        self::assertSame('pgn', $prefMap['history']);
        self::assertSame('gnuchess_simple', $prefMap['theme']);
    }

    public function testLoginPopulatesSessionAndAppliesMissingPreferenceDefaults(): void
    {
        $playerId = $this->seedPlayer('bob', 'bobpw', '(Expert)');

        $_POST = ['txtNick' => 'bob', 'pwdPassword' => 'bobpw'];

        $outcome = (new MainmenuController())->handle('Login');

        self::assertFalse($outcome['tmpNewUser']);
        self::assertEmpty($outcome['errMsg']);
        self::assertSame($playerId, (int) $_SESSION['playerID']);
        self::assertSame('bob', $_SESSION['nick']);
        self::assertSame('4', $_SESSION['userLevel']);
        self::assertSame('pgn', $_SESSION['pref_history']);

        $prefs = $this->dbAll('SELECT preference FROM preferences WHERE playerID = ?', [$_SESSION['playerID']]);
        $names = array_column($prefs, 'preference');
        foreach (['history', 'historylayout', 'theme', 'autoreload', 'replayall'] as $expected) {
            self::assertContains($expected, $names);
        }
    }

    public function testLoginDoesNotSetUserLevelWhenUnmapped(): void
    {
        $this->seedPlayer('carol', 'carolpw', '');

        $_POST = ['txtNick' => 'carol', 'pwdPassword' => 'carolpw'];

        (new MainmenuController())->handle('Login');

        self::assertArrayNotHasKey('userLevel', $_SESSION);
    }

    public function testInvitePlayerCreatesInvitedGame(): void
    {
        $me = $this->seedPlayer('dave', 'davepw', '(Novice)');
        $opponent = $this->seedPlayer('erin', 'erinpw', '(Novice)');
        $_SESSION['playerID'] = $me;
        $_SESSION['nick'] = 'dave';

        $_POST = ['opponent' => $opponent, 'color' => 'white'];

        (new MainmenuController())->handle('InvitePlayer');

        $game = $this->dbRow(
            "SELECT whitePlayer, blackPlayer, gameMessage, messageFrom FROM games WHERE gameMessage = 'playerInvited'"
        );
        self::assertNotNull($game);
        self::assertSame($me, (int) $game['whitePlayer']);
        self::assertSame($opponent, (int) $game['blackPlayer']);
        self::assertSame('white', $game['messageFrom']);
    }

    public function testInvitePlayerDoesNotDuplicatePendingRequest(): void
    {
        $me = $this->seedPlayer('frank', 'frankpw', '(Novice)');
        $opponent = $this->seedPlayer('grace', 'gracepw', '(Novice)');
        $_SESSION['playerID'] = $me;
        $_SESSION['nick'] = 'frank';

        $gameId = $this->insertInvitedGame($me, $opponent, 'white');

        $_POST = ['opponent' => $opponent, 'color' => 'black'];

        (new MainmenuController())->handle('InvitePlayer');

        self::assertNull(
            $this->dbRow("SELECT gameID FROM games WHERE gameID <> ? AND gameMessage = 'playerInvited'", [$gameId])
        );
    }

    public function testResponseToInviteDeclined(): void
    {
        $me = $this->seedPlayer('henry', 'henrypw', '(Novice)');
        $opponent = $this->seedPlayer('ida', 'idapw', '(Novice)');
        $_SESSION['playerID'] = $me;

        $gameId = $this->insertInvitedGame($opponent, $me, 'white');

        $_POST = ['gameID' => $gameId, 'response' => 'declined', 'messageFrom' => 'white'];

        (new MainmenuController())->handle('ResponseToInvite');

        $game = $this->dbRow('SELECT gameMessage, messageFrom FROM games WHERE gameID = ?', [$gameId]);
        self::assertSame('inviteDeclined', $game['gameMessage']);
        self::assertSame('white', $game['messageFrom']);
    }

    public function testWithdrawRequestDeletesGame(): void
    {
        $me = $this->seedPlayer('judy', 'judypw', '(Novice)');
        $opponent = $this->seedPlayer('kyle', 'kylepw', '(Novice)');
        $_SESSION['playerID'] = $me;

        $gameId = $this->insertInvitedGame($me, $opponent, 'white');

        $_POST = ['gameID' => $gameId];

        (new MainmenuController())->handle('WithdrawRequest');

        self::assertNull($this->dbRow('SELECT gameID FROM games WHERE gameID = ?', [$gameId]));
    }

    public function testUpdatePersonalInfoChangesCredentialsAndName(): void
    {
        $me = $this->seedPlayer('lena', 'oldpass', '(Novice)');
        $_SESSION['playerID'] = $me;

        $_POST = [
            'pwdOldPassword' => 'oldpass',
            'pwdPassword' => 'newpass',
            'txtFirstName' => 'Lena',
            'txtLastName' => 'Oten',
        ];

        $outcome = (new MainmenuController())->handle('UpdatePersonalInfo');

        self::assertEmpty($outcome['errMsg']);
        self::assertTrue(\verify_password('newpass', (string) $this->dbValue('SELECT password FROM players WHERE playerID = ?', [$me])));
        self::assertSame('Lena', $_SESSION['firstName']);
        self::assertSame('Lena Oten', $_SESSION['playerName']);
    }

    public function testUpdatePersonalInfoRejectsWrongOldPassword(): void
    {
        $me = $this->seedPlayer('mike', 'correct', '(Novice)');
        $_SESSION['playerID'] = $me;

        $_POST = [
            'pwdOldPassword' => 'wrong',
            'pwdPassword' => 'newpass',
            'txtFirstName' => 'Mike',
            'txtLastName' => 'Wrong',
        ];

        $outcome = (new MainmenuController())->handle('UpdatePersonalInfo');

        self::assertSame('Sorry, incorrect old password!', $outcome['errMsg']);
        self::assertTrue(\verify_password('correct', (string) $this->dbValue('SELECT password FROM players WHERE playerID = ?', [$me])));
    }

    public function testUpdatePrefsWritesPreferencesAndUserLevel(): void
    {
        $me = $this->seedPlayer('neo', 'neopw', '(Novice)');
        $this->seedDefaultPrefs($me);
        $_SESSION['playerID'] = $me;

        $_POST = [
            'rdoTheme' => 'master',
            'rdoHistory' => 'verbous',
            'rdoHistorylayout' => 'paragraph',
            'txtReload' => '10',
            'userLevel' => '3',
            'replayAll' => 'true',
        ];

        (new MainmenuController())->handle('UpdatePrefs');

        self::assertSame('master', $this->dbValue('SELECT value FROM preferences WHERE playerID = ? AND preference = \'theme\'', [$me]));
        self::assertSame('verbous', $this->dbValue('SELECT value FROM preferences WHERE playerID = ? AND preference = \'history\'', [$me]));
        self::assertSame('(Hobbyist)', $this->dbValue('SELECT userlevel FROM players WHERE playerID = ?', [$me]));
        self::assertSame('master', $_SESSION['pref_theme']);
        self::assertSame('3', $_SESSION['userLevel']);
    }

    public function testUpdatePrefsUserLevelZeroClearsLabel(): void
    {
        $me = $this->seedPlayer('owen', 'owenpw', '(Master)');
        $this->seedDefaultPrefs($me);
        $_SESSION['playerID'] = $me;

        $_POST = ['userLevel' => '0', 'rdoTheme' => 'gnuchess_simple', 'rdoHistory' => 'pgn', 'rdoHistorylayout' => 'columns', 'replayAll' => 'false'];

        (new MainmenuController())->handle('UpdatePrefs');

        self::assertSame('', $this->dbValue('SELECT userlevel FROM players WHERE playerID = ?', [$me]));
    }

    public function testHideMessageArchivesRequestOnlyWhenAddressedToUser(): void
    {
        $me = $this->seedPlayer('peggy', 'peggypw', '(Novice)');
        $other = $this->seedPlayer('quentin', 'quentinpw', '(Novice)');
        $_SESSION['playerID'] = $me;

        $mine = $this->insertMessage($me, 0);
        $theirs = $this->insertMessage($other, 0);

        $_POST = ['messageID' => $mine];

        (new MainmenuController())->handle('HideMessage');

        self::assertSame(1, (int) $this->dbValue('SELECT ack FROM communication WHERE commID = ?', [$mine]));
        self::assertSame(0, (int) $this->dbValue('SELECT ack FROM communication WHERE commID = ?', [$theirs]));
    }

    private function seedPlayer(string $nick, string $password, string $userlevel): int
    {
        \db_query(
            'INSERT INTO players (password, firstName, lastName, nick, userlevel) VALUES (?, ?, ?, ?, ?)',
            [\hash_password($password), ucfirst($nick), 'Smith', $nick, $userlevel]
        );

        return (int) \db_insert_id();
    }

    private function seedDefaultPrefs(int $playerId): void
    {
        $defaults = ['history' => 'pgn', 'historylayout' => 'columns', 'theme' => 'gnuchess_simple', 'autoreload' => '10', 'replayall' => 'false'];
        foreach ($defaults as $preference => $value) {
            \db_query(
                'INSERT INTO preferences (playerID, preference, value) VALUES (?, ?, ?)',
                [$playerId, $preference, $value]
            );
        }
    }

    private function insertInvitedGame(int $white, int $black, string $messageFrom): int
    {
        \db_query(
            "INSERT INTO games (whitePlayer, blackPlayer, gameMessage, messageFrom, dateCreated, lastMove) VALUES (?, ?, 'playerInvited', ?, NOW(), NOW())",
            [$white, $black, $messageFrom]
        );

        return (int) \db_insert_id();
    }

    private function insertMessage(int $toId, int $ack): int
    {
        $tables = $GLOBALS['CFG_TABLE'];
        \db_query(
            'INSERT INTO ' . $tables['communication'] . ' (fromID, toID, title, text, postDate, ack) VALUES (0, ?, ?, ?, NOW(), ?)',
            [$toId, 'Subject', 'Body', $ack]
        );

        return (int) \db_insert_id();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function dbRow(string $sql, array $params = []): ?array
    {
        return \db_row($sql, $params);
    }

    /**
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function dbAll(string $sql, array $params = []): array
    {
        return \db_all($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function dbValue(string $sql, array $params = [])
    {
        return \db_value($sql, $params);
    }
}