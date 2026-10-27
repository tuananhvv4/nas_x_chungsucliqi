<?php

declare(strict_types=1);

namespace App;

final class Config
{
    private static array $values = [];

    public static function load(string $root): void
    {
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key !== '' && getenv($key) === false) {
                    self::$values[$key] = $value;
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return $value === false ? (self::$values[$key] ?? $default) : $value;
    }
}

