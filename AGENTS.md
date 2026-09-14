# AGENTS.md

WebChess is a plain-PHP chess web app (originally from 2013, modernized in 2026).
The runtime has no framework and no build step, but does rely on Composer for
PSR-4 autoloading of the namespaced classes in `lib/` (`WebChess\` → `lib/`,
regenerated with `composer dump-autoload -o` after adding classes). Deployments
need a `composer install --no-dev`. The app is verified with `php -l`,
`npm test` (JS chess engine), `vendor/bin/phpunit` and `vendor/bin/phpstan analyse`
— see "Verification" below. See `docs/MODERNIZATION.md` for the planned
modernization phases.

## Runtime / setup

- Requires PHP 8.1+ with `pdo_mysql` and Composer's `vendor/autoload.php`;
  MySQL/MariaDB. Verify with `php -l` only.
- DB credentials come from env vars `WEBCHESS_DB_HOST/USER/PASSWORD/NAME` or a git-ignored
  `config.local.php` (copy `config.local.sample.php`). `config.php` is committed and must
  stay secret-free.
- Schema lives in `docs/tables/*.txt` (install via `install.php`). Upgrading an existing DB
  requires running the two `ALTER TABLE players ...` migrations documented in `README.md`
  (password → `VARCHAR(255)`, userlevel → `VARCHAR(20)`).
- `install.php` / `makeConfig.php` are disabled unless `WEBCHESS_ENABLE_INSTALLER=1`.

## Data layer

- All SQL goes through the PDO helpers in `db.php`: `db_query($sql, $params)` for statements,
  `db_row`, `db_all`, `db_value`, `db_insert_id()`. Always pass values as `?` parameters —
  never concatenate input into SQL. `mysql_*` is gone.
- Reference table names via `$CFG_TABLE[...]` (from `config.php`), not string literals.
- This is the 2026 security rewrite (PDO/prepared statements, bcrypt, CSRF, session hardening,
  XSS escaping, IDOR fixes). Do not regress these invariants:
  - `secure_session_start()` (security.php) instead of `session_start()`.
  - `h()` for every echo of user/DB-derived data.
  - `csrf_field()` in every state-changing form and `csrf_check()` at the top of every
    POST handler.
  - `hash_password()` / `verify_password()` / `password_needs_upgrade()` for credentials;
    verify the caller is a participant before acting on a game/message.

## Architecture

- Entrypoints are standalone scripts in the web root (`index.php`, `chess.php`, `mainmenu.php`,
  `inviteplayer.php`, `sendmessage.php`, ...). Shared code is in `chessdb.php` (`saveGame()`,
  `savePromotion()`, undo/draw/resign processing), `move.php` / `undo.php` (server-side move,
  castling, en-passant and promotion validation / undo helpers), `gui.php` (renders the board +
  embedded JS), `chessutils.php`, `security.php`, `db.php`.
- `chess.php` delegates its entire POST state-change chain (undo / promotion / move with castling
  & en-passant validation / incomplete-promotion detection) to `WebChess\Game\GameService`
  (`lib/Game/GameService.php`), which runs the legacy functions against a parameterized
  `$_POST` / `$_SESSION` and returns the resulting state array. CSRF + participant checks stay
  in the entrypoint (`csrf_check()` / `requirePlayerInGame()`).
- Move legality is validated client-side in `javascript/validation.js`; the server re-validates
  castling, promotion and en-passant before saving. A forged move must never corrupt the board.
- Move notation is WebChess long-algebraic (`1. e2-e4 e7-e5 2. Ng1-f3`); PGN import/export
  (`javascript/localpgn.js` + `openpgn.php`) additionally uses standard SAN.
- `local.php` is a login-free, DB-free hot-seat game: everything stays in the browser, nothing
  is sent to the server. Keep it fully client-side (it shares `javascript/*` with the online game).

## Conventions / quirks

- gettext i18n is off by default (`I18N_GETTEXT_SUPPORT` in `config.php`); only `de_DE` exists
  in `locale/`. Most text is plain inline strings.
- Board appearance: CSS board colour schemes and move notation live in
  `boardcolors.css` / `chess.css` / `responsive.css`; piece sets are images under `images/`.
- En passant is asymmetric (legacy engine, pinned by tests): only a **black** pawn can e.p.
  a white pawn's double-advance — the app places the captured pawn at `(fromRow, toCol)`
  (same rank as the capturer) and requires the previous ply to be a 2-square pawn advance
  landing exactly there. White-facing FIDE-style captures (`1. e4 d5 2. exd6` e.p.) are
  rejected server-side. `GameServiceTest` pins both directions.
- The `history` primary key is `(timeOfMove, gameID)` and plies are stamped with `NOW()`:
  two legal plies within the same second collide and surface as "WebChess encountered a
  database error." This pre-existing data-model limitation is due for a Phase 2 fix; tests
  must stagger fixture timestamps (and pause ≥1s between live plies) to avoid it. A fast
  real-world game can legitimately hit it today.

## Verification

- `npm test` runs the plain-JS chess engine (`javascript/validation.js`, `chessutils.js`,
  `isCheckMate.js`) under Node's built-in test runner. The engine files are loaded in a
  `vm` sandbox (`test/engine/loadEngine.js`) because they are legacy ad-hoc scripts that
  expect browser globals (`board`, `numMoves`, `chessHistory`, piece constants, `alert`).
  Board coordinates in tests: `board[row][col]`, row = rank − 1, col = file (a=0).
- `npm run lint` (or `scripts/lint-php.sh`) runs `php -l` over every `*.php` and `*.inc`.
- `composer test` runs the PHPUnit suite (`tests/php/`): `security.php`
  password/escaping helpers, the pure helpers in `chessutils.php`, the legacy
  engine `chess.inc` (FEN round-trip, move legality, notation), and the new
  namespaced classes (`UserLevel`, `PlayerColor`). `phpunit.xml.dist`
  bootstraps via `tests/php/bootstrap.php`, which loads these files through
  `include_legacy_php()` — PHPUnit includes the bootstrap inside a method, so the
  legacy files' top-level variables would otherwise land in a local scope and be
  lost (chess.inc reads them via `global`).
- DB-backed integration tests (`tests/php/Http/`, `tests/php/Game/`) exercise `MainmenuController`
  and `WebChess\Game\GameService` (the full chess.php state-change chain: move / castling /
  en-passant / promotion / undo / draw / resign) against a real MariaDB database named by the
  `WEBCHESS_DB_*` env vars (or `config.local.php`); rebuild it with
  `scripts/rebuild-test-db.sh` from `docs/tables/*.txt`. They **skip automatically** when no DB
  is configured, so CI (which has none) stays green. `TestDatabase` boots the legacy files the
  service needs (`config.php`, `newgame.php`, `chessdb.php`, `move.php`, `undo.php`,
  `connectdb.php`).
- `composer analyse` runs PHPStan at level 5 over the web root; the existing
  findings are captured in `phpstan-baseline.neon`, so CI only fails on *new*
  findings. Note: PHPStan cannot see that legacy functions
  (e.g. `processMessages()`) mutate globals through a class-method scope, so
  `GameService::applyStateChange()` carries a targeted `@phpstan-ignore if.alwaysFalse`
  on `if ($isUndoing)` — the identical pattern in `chess.php` is unflagged only because
  PHPStan treats file-scope globals differently. Regenerate the baseline after moving
  code: `vendor/bin/phpstan analyse --generate-baseline`.
- GitHub Actions (`.github/workflows/ci.yml`) runs php -l, `npm test`,
  `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` on every push/PR.
  PHPUnit 13 requires PHP ≥ 8.3 (CI uses 8.4); the app runtime itself needs 8.1+.
- The engine has two known design quirks to leave alone:
  - `isValidMove*` for non-king pieces does **not** reject moving onto a friendly piece;
    the UI layer (`squareclicked.js`) prevents that. King moves do reject friendly squares.
  - `canBeCaptured` contains a typo `testCol. epCol` (dot instead of comma) that parses as
    a member access; it only slightly affects en-passant block detection.