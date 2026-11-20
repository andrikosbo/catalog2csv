<?php

declare(strict_types=1);

namespace Catalog2Csv;

require dirname(__DIR__) . '/vendor/autoload.php';

function configuration(string $root): array
{
    $path = $root . '/config/config.local.php';
    if (!is_file($path)) {
        throw new \RuntimeException('Local configuration is missing.');
    }

    $config = require $path;
    $db = $config['database'] ?? [];
    $user = $config['access']['username'] ?? '';
    $hash = $config['access']['password_hash'] ?? '';

    if (!is_string($user) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $user)
        || !is_string($hash) || password_get_info($hash)['algo'] === null
        || !is_string($db['dsn'] ?? null) || !str_starts_with($db['dsn'], 'mysql:')
        || !is_string($db['user'] ?? null) || $db['user'] === ''
        || !is_string($db['password'] ?? null)) {
        throw new \RuntimeException('Local configuration is invalid.');
    }

    return $config;
}

function openSession(array $config): void
{
    $secure = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
    $local = PHP_SAPI === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (!$secure && !$local) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Use HTTPS to open Catalog2CSV.');
    }

    $username = $_SERVER['PHP_AUTH_USER'] ?? '';
    $password = $_SERVER['PHP_AUTH_PW'] ?? '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($username === '' && is_string($authorization) && strlen($authorization) <= 768 && strncasecmp($authorization, 'Basic ', 6) === 0) {
        $decoded = base64_decode(substr($authorization, 6), true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$username, $password] = explode(':', $decoded, 2);
        }
    }
    if (!is_string($username) || !is_string($password) || strlen($password) > 256
        || !hash_equals($config['access']['username'], $username) || !password_verify($password, $config['access']['password_hash'])) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Catalog2CSV", charset="UTF-8"');
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Sign in with the access credentials configured by your operator.');
    }

    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\') . '/';
    session_name('catalog2csv');
    session_cache_limiter('');
    if (!session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => 1,
        'cookie_secure' => $secure,
        'cookie_samesite' => 'Strict',
        'cookie_path' => $path,
        'gc_maxlifetime' => 86400,
    ])) {
        throw new \RuntimeException('Cannot initialize the session.');
    }
    $identity = hash('sha256', $config['access']['username'] . "\0" . $config['access']['password_hash']);
    if (($_SESSION['identity'] ?? null) !== $identity) {
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('Cannot regenerate the session.');
        }
        $_SESSION = ['identity' => $identity, 'csrf' => bin2hex(random_bytes(32))];
    }
}

function e(string|int|float|null $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
