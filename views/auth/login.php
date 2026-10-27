<?php

use App\Support; ?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Đăng nhập · Nas Bridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@600;700;800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/app.css">
</head>

<body class="auth-body">
    <section class="auth-copy">
        <div><span class="eyebrow light">NAS TELE × CHUNGSUCLIQI</span>
            <h1>Mọi đơn hàng.<br>Một luồng xử lý.</h1>
            <p>Nhận đơn từ Telegram, chuyển sang hệ thống đích và đồng bộ kết quả về đúng khách hàng.</p>
            <div class="auth-flow"><span>Nas Tele</span><i>→</i><b>Nas Bridge</b><i>→</i><span>Chung Sức</span></div>
        </div>
    </section>
    <section class="auth-panel">
        <form class="auth-card" method="post"><span class="brand-mark large">NB</span>
            <h2>Chào mừng trở lại</h2>
            <p>Đăng nhập để theo dõi luồng đơn của bạn.</p><?php if (!empty($error)): ?><div class="flash error"><?= Support::e($error) ?></div><?php endif; ?><input type="hidden" name="_csrf" value="<?= Support::csrfToken() ?>"><label>Email<input type="email" name="email" required autofocus></label><label>Mật khẩu<input type="password" name="password" required></label><button class="btn primary wide">Đăng nhập</button><small>Chưa có tài khoản? <a href="/register">Đăng ký</a></small>
        </form>
    </section>
</body>

</html>