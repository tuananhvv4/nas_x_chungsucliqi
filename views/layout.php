<?php use App\Support; ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Support::e($title ?? 'Nas Bridge') ?> · Nas Bridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@500;600;700;800&family=Instrument+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="/"><span class="brand-mark">NB</span><span>Nas Bridge<small>Quản lý đơn hàng</small></span></a>
        <nav class="nav">
            <a class="<?= in_array(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), ['/', '/dashboard'], true) ? 'active' : '' ?>" href="/"><span>⌂</span>Tổng quan</a>
            <a class="<?= str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/order') ? 'active' : '' ?>" href="/orders"><span>↗</span>Đơn hàng</a>
            <a class="<?= parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/mappings' ? 'active' : '' ?>" href="/mappings"><span>⇄</span>Liên kết sản phẩm</a>
            <a class="<?= parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/logs' ? 'active' : '' ?>" href="/logs"><span>≡</span>Nhật ký</a>
            <a class="<?= str_starts_with(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/settings') ? 'active' : '' ?>" href="/settings"><span>⚙</span>Kết nối</a>
            <?php if (($authUser['role'] ?? '') === 'super_admin'): ?><a class="<?= parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/users' ? 'active' : '' ?>" href="/users"><span>◎</span>Người dùng</a><?php endif; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip"><span><?= Support::e(mb_strtoupper(mb_substr($authUser['name'] ?? 'U', 0, 1))) ?></span><div><b><?= Support::e($authUser['name'] ?? '') ?></b><small><?= Support::e($authUser['email'] ?? '') ?></small></div></div>
            <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= Support::csrfToken() ?>"><button class="text-button">Đăng xuất</button></form>
        </div>
    </aside>
    <main class="main">
        <header class="mobile-head"><a class="brand" href="/"><span class="brand-mark">NB</span><span>Nas Bridge</span></a><button type="button" data-menu aria-label="Mở menu">☰</button></header>
        <?php foreach ($flash as $item): ?><div class="flash <?= Support::e($item['type']) ?>"><?= Support::e($item['message']) ?></div><?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
