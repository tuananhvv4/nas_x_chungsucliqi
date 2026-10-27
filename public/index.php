<?php

declare(strict_types=1);

use App\ApiException;
use App\Auth;
use App\ChungSucClient;
use App\Config;
use App\Database;
use App\NasTeleClient;
use App\Support;
use App\SyncService;
use App\View;

require dirname(__DIR__) . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($path === '/cron') {
        ignore_user_abort(true);
        set_time_limit(0);
        header('Content-Type: application/json; charset=utf-8');

        $configuredToken = (string) Config::get('CRON_TOKEN', '');
        $requestToken = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
        if ($configuredToken === '' || $configuredToken === 'change-this-token') {
            http_response_code(503);
            echo Support::json(['success' => false, 'message' => 'Cron chưa được cấu hình token.']);
            exit;
        }
        if ($requestToken === '' || !hash_equals($configuredToken, $requestToken)) {
            http_response_code(401);
            echo Support::json(['success' => false, 'message' => 'Token cron không hợp lệ.']);
            exit;
        }

        $task = (string) ($_GET['task'] ?? 'all');
        $allowedTasks = ['all', 'products', 'pull', 'dispatch', 'target-status', 'nas-status', 'health'];
        if (!in_array($task, $allowedTasks, true)) {
            http_response_code(422);
            echo Support::json(['success' => false, 'message' => 'Tác vụ cron không hợp lệ.']);
            exit;
        }

        $lockName = 'auto_lq_' . preg_replace('/[^a-z_]/', '', $task);
        $lock = Database::fetch('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            http_response_code(409);
            echo Support::json(['success' => false, 'message' => 'Tác vụ này đang chạy, không cần gọi lại.']);
            exit;
        }

        try {
            $service = new SyncService();
            $result = match ($task) {
                'health' => ['database' => 'ok'],
                'products' => $service->syncProducts(),
                'pull' => $service->pullOrders(),
                'dispatch' => $service->dispatchOrders(),
                'target-status' => $service->syncStatuses(),
                'nas-status' => $service->pushStatuses(),
                default => $service->runAll(),
            };
            echo Support::json([
                'success' => true,
                'task' => $task,
                'message' => 'Tác vụ đã chạy xong.',
                'result' => $result,
                'finishedAt' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo Support::json([
                'success' => false,
                'task' => $task,
                'message' => Support::friendlyError($e),
                'finishedAt' => date('Y-m-d H:i:s'),
            ]);
        } finally {
            Database::fetch('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
        exit;
    }

    if ($path === '/login') {
        if ($method === 'POST') {
            Support::verifyCsrf();
            if (Auth::login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                Support::redirect('/');
            }
            View::render('auth/login', ['title' => 'Đăng nhập', 'error' => 'Email hoặc mật khẩu không đúng.'], false);
            exit;
        }
        View::render('auth/login', ['title' => 'Đăng nhập'], false);
        exit;
    }

    if ($path === '/register') {
        if ($method === 'POST') {
            Support::verifyCsrf();
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $error = null;
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 6) {
                $error = 'Vui lòng nhập đủ thông tin; mật khẩu tối thiểu 6 ký tự.';
            } elseif (Database::fetch('SELECT id FROM users WHERE email = ?', [$email])) {
                $error = 'Email này đã được sử dụng.';
            }
            if ($error === null) {
                $count = (int) (Database::fetch('SELECT COUNT(*) AS total FROM users')['total'] ?? 0);
                Database::execute('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT), $count === 0 ? 'super_admin' : 'user']);
                Auth::login($email, $password);
                Support::redirect('/settings');
            }
            View::render('auth/register', ['title' => 'Đăng ký', 'error' => $error], false);
            exit;
        }
        View::render('auth/register', ['title' => 'Đăng ký'], false);
        exit;
    }

    if ($path === '/logout' && $method === 'POST') {
        Support::verifyCsrf();
        Auth::logout();
        Support::redirect('/login');
    }

    $user = Auth::requireUser();
    $userId = (int) $user['id'];

    if ($path === '/' || $path === '/dashboard') {
        $counts = Database::fetch(
            "SELECT COUNT(*) total,
             SUM(processing_status IN ('received','ready','submitting')) waiting,
             SUM(processing_status = 'running') running,
             SUM(processing_status = 'completed') completed,
             SUM(processing_status IN ('mapping_required','manual_review','failed')) attention
             FROM orders WHERE user_id = ?",
            [$userId]
        ) ?: [];
        $integration = Database::fetch('SELECT * FROM integrations WHERE user_id = ?', [$userId]);
        $recent = Database::fetchAll('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 8', [$userId]);
        $logs = Database::fetchAll('SELECT * FROM sync_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6', [$userId]);
        View::render('dashboard', compact('counts', 'integration', 'recent', 'logs') + ['title' => 'Tổng quan']);
        exit;
    }

    if ($path === '/settings') {
        $integration = Database::fetch('SELECT * FROM integrations WHERE user_id = ?', [$userId]);
        if ($method === 'POST') {
            Support::verifyCsrf();
            $nasBase = rtrim(trim((string) ($_POST['nas_base_url'] ?? '')), '/');
            $nasKey = trim((string) ($_POST['nas_api_key'] ?? ''));
            $targetBase = trim((string) ($_POST['target_base_url'] ?? ''));
            $targetKey = trim((string) ($_POST['target_api_key'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;
            Database::execute(
                'INSERT INTO integrations (user_id, nas_base_url, nas_api_key, target_base_url, target_api_key, is_active) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nas_base_url=VALUES(nas_base_url), nas_api_key=VALUES(nas_api_key), target_base_url=VALUES(target_base_url), target_api_key=VALUES(target_api_key), is_active=VALUES(is_active)',
                [$userId, $nasBase ?: 'https://nastele.online', $nasKey, $targetBase ?: 'https://chungsucliqi.com/api/v1.php', $targetKey, $active]
            );
            Support::flash('success', 'Đã lưu cấu hình kết nối.');
            Support::redirect('/settings');
        }
        View::render('settings', ['title' => 'Kết nối hệ thống', 'integration' => $integration]);
        exit;
    }

    if ($path === '/settings/test' && $method === 'POST') {
        Support::verifyCsrf();
        $integration = Database::fetch('SELECT * FROM integrations WHERE user_id = ?', [$userId]);
        if (!$integration) {
            Support::flash('error', 'Hãy lưu cấu hình trước khi kiểm tra.');
            Support::redirect('/settings');
        }
        try {
            $nas = (new NasTeleClient((string) $integration['nas_base_url'], (string) $integration['nas_api_key']))->orders(['status' => 'processing', 'page' => 1, 'per_page' => 1]);
            $target = (new ChungSucClient((string) $integration['target_base_url'], (string) $integration['target_api_key']))->me();
            Database::execute('UPDATE integrations SET nas_last_ok_at=NOW(), target_last_ok_at=NOW(), last_error=NULL WHERE user_id=?', [$userId]);
            $balance = $target['data']['balance'] ?? 0;
            Support::flash('success', 'Kết nối thành công. Số dư Chungsucliqi: ' . number_format((float) $balance) . ' Xu.');
        } catch (Throwable $e) {
            Database::execute('UPDATE integrations SET last_error=? WHERE user_id=?', [mb_substr($e->getMessage(), 0, 2000), $userId]);
            Support::flash('error', 'Chưa thể kết nối: ' . Support::friendlyError($e));
        }
        Support::redirect('/settings');
    }

    if ($path === '/sync' && $method === 'POST') {
        Support::verifyCsrf();
        $scope = (string) ($_POST['scope'] ?? 'all');
        $service = new SyncService();
        $result = match ($scope) {
            'products' => $service->syncProducts($userId),
            'pull' => $service->pullOrders($userId),
            'dispatch' => $service->dispatchOrders($userId),
            'statuses' => ['target' => $service->syncStatuses($userId), 'nas' => $service->pushStatuses($userId)],
            default => $service->runAll($userId),
        };
        $message = match ($scope) {
            'products' => 'Đã cập nhật danh sách sản phẩm từ Chungsucliqi.',
            'pull' => 'Đã kiểm tra đơn mới từ Nas Tele.',
            'dispatch' => 'Đã xử lý các đơn đang chờ.',
            'statuses' => 'Đã cập nhật tình trạng các đơn đang chạy.',
            default => 'Đồng bộ hoàn tất. Dữ liệu mới nhất đã được cập nhật.',
        };
        Support::flash('success', $message);
        Support::redirect((string) ($_SERVER['HTTP_REFERER'] ?? '/'));
    }

    if ($path === '/mappings') {
        if ($method === 'POST') {
            Support::verifyCsrf();
            $id = (int) ($_POST['id'] ?? 0);
            $values = [
                (int) ($_POST['nas_product_id'] ?? 0), trim((string) ($_POST['nas_product_name'] ?? '')),
                trim((string) ($_POST['event_code_field_key'] ?? '')), trim((string) ($_POST['phone_field_key'] ?? '')) ?: null,
                trim((string) ($_POST['note_field_key'] ?? '')) ?: null, ($_POST['target_type'] ?? 'order') === 'retail_order' ? 'retail_order' : 'order',
                ($_POST['target_product_id'] ?? '') !== '' ? (int) $_POST['target_product_id'] : null,
                trim((string) ($_POST['target_product_name'] ?? '')) ?: null, trim((string) ($_POST['target_pack'] ?? '')) ?: null,
                max(1, (int) ($_POST['target_qty'] ?? 1)), isset($_POST['is_active']) ? 1 : 0,
            ];
            $targetIsMissing = ($values[5] === 'order' && empty($values[6]))
                || ($values[5] === 'retail_order' && empty($values[8]));
            if ($values[0] < 1 || $values[1] === '' || $values[2] === '') {
                Support::flash('error', 'Vui lòng nhập mã sản phẩm, tên sản phẩm và tên trường chứa mã chung sức.');
            } elseif ($targetIsMissing || empty($values[7])) {
                Support::flash('error', 'Vui lòng chọn một gói xử lý từ Chungsucliqi.');
            } elseif ($id > 0) {
                Database::execute('UPDATE product_mappings SET nas_product_id=?, nas_product_name=?, event_code_field_key=?, phone_field_key=?, note_field_key=?, target_type=?, target_product_id=?, target_product_name=?, target_pack=?, target_qty=?, is_active=? WHERE id=? AND user_id=?', array_merge($values, [$id, $userId]));
                Support::flash('success', 'Đã cập nhật liên kết sản phẩm.');
            } else {
                Database::execute('INSERT INTO product_mappings (user_id, nas_product_id, nas_product_name, event_code_field_key, phone_field_key, note_field_key, target_type, target_product_id, target_product_name, target_pack, target_qty, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge([$userId], $values));
                Support::flash('success', 'Đã tạo liên kết sản phẩm.');
            }
            Support::redirect('/mappings');
        }
        $mappings = Database::fetchAll('SELECT * FROM product_mappings WHERE user_id = ? ORDER BY updated_at DESC, created_at DESC', [$userId]);
        $targetProducts = Database::fetchAll('SELECT * FROM target_products WHERE user_id = ? ORDER BY target_type, name', [$userId]);
        $editing = isset($_GET['edit']) ? Database::fetch('SELECT * FROM product_mappings WHERE id = ? AND user_id = ?', [(int) $_GET['edit'], $userId]) : null;
        View::render('mappings', compact('mappings', 'targetProducts', 'editing') + ['title' => 'Liên kết sản phẩm']);
        exit;
    }

    if ($path === '/mappings/delete' && $method === 'POST') {
        Support::verifyCsrf();
        Database::execute('DELETE FROM product_mappings WHERE id = ? AND user_id = ?', [(int) ($_POST['id'] ?? 0), $userId]);
        Support::flash('success', 'Đã xoá liên kết sản phẩm.');
        Support::redirect('/mappings');
    }

    if ($path === '/orders') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = trim((string) ($_GET['q'] ?? ''));
        $where = 'user_id = ?';
        $params = [$userId];
        if ($status !== '') {
            $where .= ' AND processing_status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= ' AND (nas_order_id = ? OR customer_username LIKE ? OR nas_product_name LIKE ?)';
            $params[] = (int) $search;
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $total = (int) (Database::fetch("SELECT COUNT(*) total FROM orders WHERE {$where}", $params)['total'] ?? 0);
        $perPage = 30;
        $offset = ($page - 1) * $perPage;
        $orders = Database::fetchAll("SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);
        View::render('orders', compact('orders', 'total', 'page', 'perPage', 'status', 'search') + ['title' => 'Đơn hàng']);
        exit;
    }

    if ($path === '/order') {
        $order = Database::fetch('SELECT * FROM orders WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), $userId]);
        if (!$order) {
            http_response_code(404);
            exit('Không tìm thấy đơn.');
        }
        $targets = Database::fetchAll('SELECT * FROM target_orders WHERE order_id = ? ORDER BY id', [$order['id']]);
        $logs = Database::fetchAll('SELECT * FROM sync_logs WHERE order_id = ? ORDER BY created_at DESC', [$order['id']]);
        View::render('order-detail', compact('order', 'targets', 'logs') + ['title' => 'Đơn #' . $order['nas_order_id']]);
        exit;
    }

    if ($path === '/order/retry' && $method === 'POST') {
        Support::verifyCsrf();
        $retryOrder = Database::fetch('SELECT * FROM orders WHERE id = ? AND user_id = ?', [(int) ($_POST['id'] ?? 0), $userId]);
        if ($retryOrder) {
            if (in_array($retryOrder['processing_status'], ['completed', 'cancelled'], true)) {
                Database::execute("UPDATE orders SET source_sync_status = 'pending', retry_count = 0, next_retry_at = NULL, last_error = NULL WHERE id = ?", [$retryOrder['id']]);
            } else {
                $nextStatus = !empty($retryOrder['mapping_snapshot_json']) && !empty($retryOrder['event_codes_json']) ? 'ready' : 'received';
                Database::execute('UPDATE orders SET processing_status = ?, retry_count = 0, next_retry_at = NULL, last_error = NULL WHERE id = ?', [$nextStatus, $retryOrder['id']]);
            }
        }
        Support::flash('success', 'Đơn sẽ được hệ thống xử lý lại.');
        Support::redirect('/order?id=' . (int) ($_POST['id'] ?? 0));
    }

    if ($path === '/logs') {
        $logs = Database::fetchAll('SELECT l.*, o.nas_order_id FROM sync_logs l LEFT JOIN orders o ON o.id=l.order_id WHERE l.user_id=? ORDER BY l.created_at DESC LIMIT 300', [$userId]);
        View::render('logs', compact('logs') + ['title' => 'Nhật ký đồng bộ']);
        exit;
    }

    if ($path === '/users' && ($user['role'] ?? '') === 'super_admin') {
        if ($method === 'POST') {
            Support::verifyCsrf();
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            if ($targetUserId !== $userId) {
                Database::execute('UPDATE users SET is_active = ? WHERE id = ?', [isset($_POST['is_active']) ? 1 : 0, $targetUserId]);
                Support::flash('success', 'Đã cập nhật trạng thái người dùng.');
            }
            Support::redirect('/users');
        }
        $users = Database::fetchAll(
            "SELECT u.id, u.name, u.email, u.role, u.is_active, u.last_login_at, u.created_at,
             COUNT(o.id) AS order_count, SUM(o.processing_status = 'running') AS running_count
             FROM users u LEFT JOIN orders o ON o.user_id = u.id GROUP BY u.id ORDER BY u.created_at DESC"
        );
        View::render('users', compact('users') + ['title' => 'Người dùng']);
        exit;
    }

    http_response_code(404);
    View::render('not-found', ['title' => 'Không tìm thấy']);
} catch (PDOException $e) {
    http_response_code(500);
    echo '<h1>Chưa kết nối được database</h1><p>Hãy tạo file <code>.env</code> và chạy <code>database/schema.sql</code>.</p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Đã xảy ra lỗi</h1><p>' . Support::e($e->getMessage()) . '</p>';
}
