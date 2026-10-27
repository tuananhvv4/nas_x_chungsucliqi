# Nas Bridge

Web quản lý độc lập nhận đơn từ Nas Tele, tạo đơn tại Chungsucliqi và đồng bộ trạng thái về Nas Tele.

## Yêu cầu

- PHP 8.1+ với `pdo_mysql`, `curl`, `mbstring`
- MySQL 8 hoặc MariaDB 10.6+
- Apache hoặc Nginx; document root trỏ vào thư mục `public/`

## Cài đặt

```bash
cp .env.example .env
php bin/install.php
php -S 127.0.0.1:8080 -t public public/router.php
```

Mở `http://127.0.0.1:8080/register`. Tài khoản đầu tiên tự nhận vai trò `super_admin`.

## Cron đề xuất

```cron
* * * * * php /path/auto_lq/cron/pull_orders.php
* * * * * php /path/auto_lq/cron/dispatch_orders.php
*/2 * * * * php /path/auto_lq/cron/sync_target_statuses.php
* * * * * php /path/auto_lq/cron/push_nas_statuses.php
*/15 * * * * php /path/auto_lq/cron/sync_products.php
```

Mỗi task dùng MySQL advisory lock để tránh hai tiến trình chạy chồng.

### Gọi cron bằng HTTP

Đặt một token khó đoán trong `.env`:

```env
CRON_TOKEN=thay-bang-token-rieng-dai-va-kho-doan
```

Web cron gọi toàn bộ luồng đơn mỗi phút:

```text
https://ten-mien-cua-ban.com/cron?task=all&token=CRON_TOKEN
```

Cập nhật danh sách sản phẩm mỗi 30 phút:

```text
https://ten-mien-cua-ban.com/cron?task=products&token=CRON_TOKEN
```

Có thể gửi token bằng header `X-Cron-Token` thay cho query string nếu dịch vụ web cron hỗ trợ.

Kiểm tra endpoint mà không chạy đồng bộ:

```text
https://ten-mien-cua-ban.com/cron?task=health&token=CRON_TOKEN
```

## Luồng đơn

1. Lấy các đơn Nas Tele có trạng thái `processing`.
2. Chống trùng theo `user_id + nas_order_id`.
3. Đọc `data.fields` và lấy mã theo mapping của user.
4. Tạo đơn Chungsucliqi với `client_ref=nas-u{user}-o{order}`.
5. Tra trạng thái theo batch tối đa 50 mã.
6. Cập nhật Nas Tele sang `completed` hoặc `cancelled`.
# nas_x_chungsucliqi
