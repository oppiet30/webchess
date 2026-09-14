# WebChess modernization plan (Phase 0 proposal)

Status: **proposal** — nothing here has been implemented beyond the Phase 0 safety
net (`test/`, `scripts/lint-php.sh`, `.github/workflows/ci.yml`).

## Why this plan exists

The 2026 release modernized the **data and auth layers only**. The app is still:

- Plain standalone PHP entrypoints with no Composer/autoloader.
- A 1,423-line `mainmenu.php` god-file (login, registration, preferences,
  invites, game lists, logout).
- An XHTML 1.0 / table-era front end whose rendered HTML and embedded JS
  (`var gameId = ...`, `writeJSboard()`, `writeJSHistory()`) are a contract the
  JS depends on.
- Zeros tests, no CI.

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

### Phase 0 — Safety net (DONE for engine + lint + CI)
- Node test harness (`test/engine/`) loading the real `validation.js` /
  `isCheckMate.js` / `chessutils.js` under `node --test`, no build step.
- `scripts/lint-php.sh`: `php -l` over every `*.php` and `*.inc`.
- GitHub Actions running both (`npm test`, `scripts/lint-php.sh`).

### Phase 1 — Server cleanup (behavior-preserving)
- Composer + PSR-4/namespaces; a single front-controller router replacing the
  standalone entrypoints.
- Split `mainmenu.php` into controllers (Auth / Profile / GameList / Messages).
- A `GameService` wrapping `chessdb.php` save/load + `move.php` validation.
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