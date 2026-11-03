<?php

declare(strict_types=1);

namespace App;

use Throwable;

final class Support
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    public static function decode(?string $value, mixed $default = []): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function consumeFlash(): array
    {
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $items;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $token = (string) ($_POST['_csrf'] ?? '');
        if ($token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
            http_response_code(419);
            exit('Phiên làm việc đã hết hạn. Vui lòng tải lại trang.');
        }
    }

    public static function statusLabel(string $status): string
    {
        return [
            'received' => 'Đơn mới', 'mapping_required' => 'Chưa ghép sản phẩm', 'ready' => 'Đang chờ xử lý',
            'submitting' => 'Đang chuyển đơn', 'submitted' => 'Đã chuyển đơn', 'running' => 'Đang xử lý',
            'processing' => 'Đang xử lý', 'cancelling' => 'Đang huỷ', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã huỷ', 'retry_waiting' => 'Chờ thử lại',
            'manual_review' => 'Cần kiểm tra', 'failed' => 'Lỗi', 'pending' => 'Chờ', 'done' => 'Hoàn thành',
        ][$status] ?? $status;
    }

    public static function syncStatusLabel(?string $status): string
    {
        return [
            'pending' => 'Chưa gửi kết quả',
            'synced' => 'Đã gửi kết quả',
            'failed' => 'Gửi kết quả thất bại',
            'local_only' => 'Không gửi về Nas Tele',
        ][$status ?? ''] ?? 'Chưa gửi kết quả';
    }

    public static function targetTypeLabel(?string $type): string
    {
        return [
            'order' => 'Đơn theo sản phẩm',
            'retail_order' => 'Đơn vé lẻ',
            'retail' => 'Gói vé lẻ',
        ][$type ?? ''] ?? 'Đơn theo sản phẩm';
    }

    public static function channelLabel(?string $channel): string
    {
        return [
            'nas' => 'Nas Tele',
            'target' => 'Chungsucliqi',
            'system' => 'Hệ thống',
        ][$channel ?? ''] ?? 'Hệ thống';
    }

    public static function roleLabel(?string $role): string
    {
        return $role === 'super_admin' ? 'Quản trị hệ thống' : 'Người dùng';
    }

    public static function friendlyError(Throwable $error): string
    {
        if ($error instanceof ApiException) {
            return match ($error->httpStatus) {
                401, 403 => 'Mã truy cập không đúng, đã hết hạn hoặc tài khoản đã bị tạm khoá.',
                402 => 'Tài khoản Chungsucliqi không đủ số dư để xử lý đơn.',
                404 => 'Không tìm thấy dữ liệu cần xử lý trên hệ thống bên kia.',
                409 => 'Đơn hoặc mã này đã tồn tại. Vui lòng kiểm tra lại trước khi xử lý tiếp.',
                422 => 'Thông tin đơn chưa đầy đủ hoặc chưa đúng. Vui lòng kiểm tra sản phẩm và mã khách nhập.',
                429 => 'Hệ thống đang nhận quá nhiều yêu cầu. Đơn sẽ được kiểm tra lại sau.',
                503 => 'Dịch vụ bên kia đang tạm ngừng. Hệ thống sẽ thử lại sau.',
                default => self::friendlyMessage($error->getMessage()),
            };
        }
        return self::friendlyMessage($error->getMessage());
    }

    public static function friendlyMessage(?string $message): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }
        return str_replace([
            'Chưa có mapping cho sản phẩm nguồn.',
            'Không tìm thấy field mã:',
            'Đồng bộ sản phẩm thất bại:',
            'Đồng bộ trạng thái đích thất bại:',
            'Không thể tạo đơn đích:',
            'Cập nhật Nas Tele thất bại:',
            'Nas Tele đã đổi trạng thái nhưng gửi Telegram thất bại.',
            'Đơn thiếu snapshot mapping hoặc mã sự kiện.',
        ], [
            'Sản phẩm này chưa được liên kết với Chungsucliqi.',
            'Không tìm thấy trường chứa mã chung sức:',
            'Không thể cập nhật danh sách sản phẩm:',
            'Không thể cập nhật tình trạng đơn:',
            'Không thể gửi đơn sang Chungsucliqi:',
            'Không thể gửi kết quả về Nas Tele:',
            'Đã cập nhật kết quả nhưng chưa gửi được thông báo cho khách.',
            'Đơn chưa có đủ thông tin sản phẩm hoặc mã chung sức.',
        ], $message);
    }

    public static function badgeClass(string $status): string
    {
        return match ($status) {
            'completed', 'done' => 'success',
            'cancelled', 'failed' => 'danger',
            'running', 'submitted', 'submitting' => 'accent',
            'mapping_required', 'manual_review', 'retry_waiting' => 'warning',
            default => 'neutral',
        };
    }
}
