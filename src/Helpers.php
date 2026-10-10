<?php
declare(strict_types=1);

namespace App;

function e(string|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function base_url(string $path = ''): string
{
    // App jalan di subfolder /container-server di bawah DocumentRoot.
    $base = rtrim(Config::get('APP_URL', ''), '/');
    // Ambil path relatif dari APP_URL agar portable antar host.
    $basePath = parse_url($base, PHP_URL_PATH) ?: '/container-server';
    return rtrim($basePath, '/') . '/' . ltrim($path, '/');
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $sent)) {
        http_response_code(419);
        exit('CSRF token mismatch');
    }
}

function valid_container_name(string $name): bool
{
    return (bool) preg_match('/^\/?[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/', $name);
}

function parse_env_text(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ($k !== '') {
            $out[] = "$k=$v";
        }
    }
    return $out;
}

/** "8080:80, 8443:443" -> [["8080","80"],["8443","443"]] */
function parse_ports_text(string $text): array
{
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $text) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (!preg_match('/^(\d{1,5}):(\d{1,5})(\/(tcp|udp))?$/', $part, $m)) {
            continue;
        }
        $out[] = [(int) $m[1], (int) $m[2], $m[4] ?? 'tcp'];
    }
    return $out;
}
