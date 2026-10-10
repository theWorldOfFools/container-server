<?php
declare(strict_types=1);

namespace App;

class Config
{
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(string $baseDir): void
    {
        if (self::$loaded) {
            return;
        }
        $file = $baseDir . '/.env';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
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
                if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
                    $v = substr($v, 1, -1);
                }
                self::$env[$k] = $v;
                if (getenv($k) === false) {
                    putenv("$k=$v");
                }
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$env)) {
            return self::$env[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : (string) $v;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, (string) $default);
    }

    /** @return string[] */
    public static function allowedImages(): array
    {
        $raw = self::get('ALLOWED_IMAGES', 'nginx:latest');
        $out = [];
        foreach (explode(',', $raw) as $img) {
            $img = trim($img);
            if ($img !== '') {
                $out[] = $img;
            }
        }
        return $out;
    }
}
