<?php

declare(strict_types=1);

namespace WebChess\Tests\Http;

/**
 * Bootstraps the DB-backed parts of the app (config globals + PDO helpers)
 * for integration tests. The web-root files are include_legacy_php()'d so the
 * $CFG_* variables land in $GLOBALS, exactly like include_legacy_php() does
 * for the engine files in tests/php/bootstrap.php.
 *
 * Only runs when WEBCHESS_DB_* environment variables are present, so the
 * default `composer test` run (and CI) stays DB-free and skips these tests.
 */
final class TestDatabase
{
    private static bool $booted = false;
    private static bool $available = false;
    private static string $reason = '';

    public static function isAvailable(): bool
    {
        return self::$available;
    }

    public static function reason(): string
    {
        return self::$reason;
    }

    /**
     * Must be called after the superglobals are set up; idempotent.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (getenv('WEBCHESS_DB_NAME') === false) {
            self::$reason = 'WEBCHESS_DB_* environment variables are not set; DB integration tests skipped.';
            return;
        }

        include_legacy_php(__DIR__ . '/../../../config.php');
        /* chessutils.php, chessconstants.php and security.php are already
           loaded by tests/php/bootstrap.php; only the DB/game files remain. */
        include_legacy_php(__DIR__ . '/../../../newgame.php');
        include_legacy_php(__DIR__ . '/../../../chessdb.php');
        include_legacy_php(__DIR__ . '/../../../connectdb.php');

        self::$available = true;
    }
}