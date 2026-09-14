<?php

declare(strict_types=1);

namespace WebChess\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Baseline tests for security.php: HTML escaping and password handling.
 *
 * CSRF/session helpers are skipped here: they read the real $_SESSION, which
 * gets populated by secure_session_start() during normal request handling.
 */
final class SecurityTest extends TestCase
{
    public function testH(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&amp;&amp;1',
            h('<script>alert("x")&&1'),
        );

        self::assertSame('a&#039;b', h("a'b"));

        self::assertSame('', h(null));
        self::assertSame('', h(''));
    }

    public function testHashPasswordRoundTrip(): void
    {
        $hash = hash_password('correct horse battery staple');

        self::assertStringStartsWith('$2y$', $hash, 'expected a modern bcrypt-style hash');
        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue(verify_password('correct horse battery staple', $hash));
        self::assertFalse(verify_password('wrong password', $hash));
    }

    public function testVerifyPasswordRejectsGarbage(): void
    {
        self::assertFalse(verify_password('anything', 'not a hash at all'));
    }

    public function testLegacyMd5HashIsVerified(): void
    {
        $md5 = md5('secret');

        self::assertTrue(verify_password('secret', $md5), 'legacy unsalted md5 must verify');
        self::assertFalse(verify_password('nope', $md5));
        self::assertTrue(verify_password('secret', strtoupper($md5)), 'uppercase legacy hashes are normalized');
    }

    public function testPasswordNeedsUpgrade(): void
    {
        self::assertTrue(password_needs_upgrade(md5('secret')), 'md5 always needs upgrading');

        $currentHash = hash_password('secret');
        self::assertFalse(password_needs_upgrade($currentHash));
    }
}