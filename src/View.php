<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $template, array $data = [], bool $layout = true): void
    {
        $root = dirname(__DIR__);
        extract($data, EXTR_SKIP);
        if (!$layout) {
            require $root . '/views/' . $template . '.php';
            return;
        }
        ob_start();
        require $root . '/views/' . $template . '.php';
        $content = (string) ob_get_clean();
        $flash = Support::consumeFlash();
        $authUser = Auth::user();
        require $root . '/views/layout.php';
    }
}

