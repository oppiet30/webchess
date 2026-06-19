<?php
/*
    This file is part of WebChess. https://github.com/thorium/webchess
    Copyright 2010 Jonathan Evraire, Rodrigo Flores

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

/*
 * Shared security helpers: hardened sessions, CSRF protection, HTML output
 * escaping and modern password hashing. Every page should call
 * secure_session_start() instead of session_start().
 */

declare(strict_types=1);

/**
 * Starts a session with hardened cookie parameters. Safe to call more than once.
 */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,   /* only send the cookie over HTTPS when available */
        'httponly' => true,     /* keep the session cookie out of JavaScript's reach */
        'samesite' => 'Lax',    /* mitigate CSRF on top-level cross-site requests */
    ]);

    session_start();
}

/**
 * Escapes a value for safe output inside HTML. Use everywhere user-controlled
 * data is echoed into a page.
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Returns the current session's CSRF token, generating one if necessary.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Returns a hidden form field carrying the CSRF token, to embed in every
 * state-changing <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '" />';
}

/**
 * Validates the CSRF token submitted with a request. Aborts the request when
 * the token is missing or does not match. Call at the top of every handler that
 * acts on POST data.
 */
function csrf_check(): void
{
    $submitted = $_POST['csrf_token'] ?? '';

    if (!is_string($submitted)
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(400);
        die('Invalid or missing security token. Please reload the page and try again.');
    }
}

/**
 * Hashes a plaintext password using the current best algorithm.
 */
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/**
 * Verifies a plaintext password against a stored hash. Transparently supports
 * legacy 32-character MD5 hashes so existing accounts keep working; callers
 * should re-hash with hash_password() on a successful legacy match.
 */
function verify_password(string $plain, string $storedHash): bool
{
    /* Legacy WebChess stored unsalted md5() hashes (always 32 hex chars). */
    if (preg_match('/^[a-f0-9]{32}$/i', $storedHash)) {
        return hash_equals(strtolower($storedHash), md5($plain));
    }

    return password_verify($plain, $storedHash);
}

/**
 * Returns true when a stored hash is in a legacy/outdated format and should be
 * upgraded to the current algorithm after a successful login.
 */
function password_needs_upgrade(string $storedHash): bool
{
    if (preg_match('/^[a-f0-9]{32}$/i', $storedHash)) {
        return true;
    }

    return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
}
