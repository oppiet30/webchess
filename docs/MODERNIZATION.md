# WebChess modernization plan

Status: Phase 0 (JS engine harness + PHP lint + CI) and Phase 1 groundwork
(PHPUnit 13 baseline + PHPStan level-5 baseline) are **done**. Phase 1's
autoloading + `mainmenu.php` split is **done** (Composer PSR-4 `WebChess\` →
`lib/`, `UserLevel`/`PlayerColor` value objects, `MainmenuController` extracting
the whole POST switch, tested against a MariaDB test DB). The `GameService`
wrapper + `chess.php` delegation are **done** (see below). Remaining Phase 1
work (front controller, splitting more entrypoints) is in progress.

## Why this plan exists

The 2026 release modernized the **data and auth layers only**. The app is still:

- Plain standalone PHP entrypoints with no runtime Composer/autoloader (dev
  tooling now uses Composer for PHPUnit + PHPStan).
- A 1,423-line `mainmenu.php` god-file (login, registration, preferences,
  invites, game lists, logout).
- An XHTML 1.0 / table-era front end whose rendered HTML and embedded JS
  (`var gameId = ...`, `writeJSboard()`, `writeJSHistory()`) are a contract the
  JS depends on.
- Zero runtime tests, no CI for the app itself (CI runs engine tests, lint,
  PHPUnit baseline, and PHPStan on new findings).

## Verified architecture facts

- **Three independent "engines"** — no single source of truth:
  1. `javascript/validation.js` — full move legality, client-side only.
  2. `move.php` — server re-validates *only* castling / en-passant / promotion;
     everything else is trusted from the client.
  3. `chess.inc` — a PHP3-era engine kept alive only by `gui.php` replay/PGN
     rendering (`set_FEN()` / `add_Move()`).
- **The client declares checkmate**: `chessdb.php` records `gameMessage='checkMate'`
  from a plain `$_POST['isCheckMate']=='true'` flag.
- **Board state** is the denormalized `pieces` table; the moves live in
  `history`, whose primary key is `(timeOfMove DATETIME, gameID)` and rows are
  written with `NOW()` — two plies within the same second collide.
- Coordinates everywhere use row = rank - 1 (board[0] = White's back rank).
  History index constants `CURPIECE..PROMOTEDTO` are emitted by `gui.php`, not
  present in any `.js` file.

## Suggested phases (each leaves a runnable app)

### Phase 0 — Safety net (DONE)
- Node test harness (`test/engine/`) loading the real `validation.js` /
  `isCheckMate.js` / `chessutils.js` under `node --test`, no build step.
- `scripts/lint-php.sh`: `php -l` over every `*.php` and `*.inc`.
- GitHub Actions running both (`npm test`, `scripts/lint-php.sh`).
- Found and fixed `genAllMoves()` color-comparison bug (2013-era), which made
  `countMoves()` always return 0 and broke stalemate detection at runtime.

### Phase 1 — Server cleanup (done: groundwork, autoloader, mainmenu split)
**Done (groundwork):**
- `composer.json` with PHPUnit 13 + PHPStan 2.2 (dev-only, no runtime
  autoloader) — later extended with a runtime PSR-4 autoloader.
- PHPUnit 13 baseline covering `security.php` (password hashing, MD5 migration,
  escaping), `chessutils.php` (pure helpers), and the legacy PHP engine
  `chess.inc` (FEN round-trip, long-algebraic move replay, castling/promotion
  notation, illegal-move rejection, check detection).
- PHPStan level 5 over the whole web root; findings captured in
  `phpstan-baseline.neon` — CI fails only on *new* findings.
- Bootstrap workaround: `include_legacy_php()` in `tests/php/bootstrap.php`
  hoists `chess.inc`'s top-level variables into `$GLOBALS` (PHPUnit includes
  the bootstrap inside a method, so plain `require` leaks to local scope).

**Done (this slice):**
- Runtime Composer autoloader: `"autoload": {"psr-4": {"WebChess\\": "lib/"}}`,
  `require vendor/autoload.php` in `mainmenu.php`; deployments now need
  `composer install --no-dev`. `config.php` defines are guarded so `lib/`
  classes can re-include legacy files in test bootstrap contexts.
- Value objects: `WebChess\Player\UserLevel` (label↔number mapping) and
  `WebChess\Chess\PlayerColor` (`random` → coin flip) in `lib/`.
- `mainmenu.php` slimmed 1,423 → 1,033 lines: the entire POST switch
  (`NewUser`, `Login`, `Logout`, `InvitePlayer`, `ResponseToInvite`,
  `WithdrawRequest`, `UpdatePersonalInfo`, `UpdatePrefs`, `TestEmail`,
  `HideMessage`) moved to `WebChess\Http\MainmenuController::handle()`, which
  returns `{tmpNewUser, errMsg}` and keeps csrf/session gating in `mainmenu.php`.
- DB integration tests: `tests/php/Http/TestDatabase.php` boots legacy files
  against a MariaDB database configured via `WEBCHESS_DB_*` env or
  `config.local.php`; `tests/php/Http/MainmenuControllerTest.php` covers the
  controller's persistent actions; `scripts/rebuild-test-db.sh` rebuilds
  `webchess_test` from `docs/tables/*.txt`. These tests **skip automatically**
  when no DB is configured (CI has none).

**This slice (GameService):**
- `WebChess\Game\GameService::applyStateChange(int $gameId, int $playerId,
  bool $isSharedPC, array $post): array` wraps the legacy chessdb/move/undo
  functions (now also booted by `TestDatabase`) and reproduces the old
  `chess.php` state-change chain verbatim: load history/game, process
  messages, then undo / promotion (server-validated) / move (color, castling,
  en-passant re-validation) / incomplete-promotion detection. It parameterizes
  `$_POST`/`$_SESSION` (saved/restored in a `try/finally`) and returns
  `{board, history, numMoves, playersColor, isInCheck, isPromoting, isUndoing,
  isCheckMate, isUndoRequested, isDrawRequested, isGameOver, statusMessage}`.
  CSRF + participant checks stay in the entrypoint.
- `chess.php` now requires `vendor/autoload.php` and replaces its whole
  state-change chain with a single `GameService` call that feeds the returned
  state back into the legacy template variables. This *removed* 25 legacy
  PHPStan findings from the baseline (123 → 98); the one remaining
  `@phpstan-ignore if.alwaysFalse` on `if ($isUndoing)` is documented in
  `AGENTS.md`.
- `tests/php/Game/GameServiceTest.php`: 16 DB integration tests covering the
  full chain — legal & out-of-turn moves, castling validation, both en-passant
  directions (the engine only accepts **black**-pawn captures; the FIDE
  white-side `exd6` e.p. is rejected and pinned), promotion + forged-promotion
  rejection, undo/draw/resign on shared and separate PCs, and checkmate. Tests
  must stagger timestamps/`sleep(1)` because of the `(timeOfMove, gameID)` PK
  collision.
- Manually smoke-tested over Apache (webchess.local): login, board load,
  legal white/black plies, and an illegal out-of-turn move being ignored.

**Remaining Phase 1 work (not yet started):**
- A single front-controller router replacing standalone entrypoints.
- Must keep: `h()`, `csrf_check()`, prepared statements, participant checks.

### Phase 2 — Correctness / trust
- Authoritative move legality and checkmate detection server-side (stop trusting
  `$_POST['isCheckMate']`).
- Migrate `history` to an integer `(gameID, moveNo)` primary key (+ per-move FEN
  snapshot); write a migration for existing databases.

### Phase 3 — Frontend
- Vite + React (or modern vanilla) board driven by a JSON API replacing the
  server-rendered board and its `var ...` JS contract.
- SSE/WebSocket updates instead of the autoreload/meta-refresh polling.
- Retire `chess.inc`'s replay rendering.

### Phase 4 — Consolidation
- `local.php` rides the same board component as the online game.

## Risks
- Anything structural before Phase 0 is risky (no regression net).
- The `history` datetime PK collides on fast play — affects the Phase 2
  migration.
- The embedded-JS contract couples backend and frontend: a JSON API must be
  stable before the frontend rewrite.
- Do not lose the security invariants from `AGENTS.md`.