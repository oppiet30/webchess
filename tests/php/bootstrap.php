<?php

declare(strict_types=1);

/*
 * Loads the app's self-contained PHP units so the baseline tests can exercise
 * them. Everything here is DB-free and side-effect-free on include.
 *
 * PHPUnit includes this bootstrap from inside a method (BootstrapLoader::load),
 * so a plain require would give the legacy files' top-level variables *local*
 * scope. include_legacy_php() copies every variable defined by the included
 * file into $GLOBALS so functions like set_FEN() (which uses `global` internally)
 * keep working exactly as they do when gui.php requires chess.inc at page scope.
 *
 * DB-backed code (db.php, chessdb.php, move.php, ...) is intentionally excluded
 * from this bootstrap; covering it needs a PDO seam, which is Phase 1 work in
 * docs/MODERNIZATION.md.
 */

if (is_file(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
}

function include_legacy_php(string $file): void
{
    require $file;

    foreach (get_defined_vars() as $name => $value) {
        if ($name !== 'file') {
            $GLOBALS[$name] = $value;
        }
    }
}

include_legacy_php(__DIR__ . '/../../chessconstants.php');
include_legacy_php(__DIR__ . '/../../security.php');
include_legacy_php(__DIR__ . '/../../chessutils.php');
include_legacy_php(__DIR__ . '/../../chess.inc');