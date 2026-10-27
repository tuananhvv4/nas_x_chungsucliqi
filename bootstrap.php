<?php

declare(strict_types=1);

use App\Config;

$root = __DIR__;

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Config::load($root);
date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('auto_lq_session');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'path' => '/',
    ]);
    session_start();
}

