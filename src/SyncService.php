<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

final class SyncService
{
    public function syncProducts(?int $onlyUserId = null): array
    {
        $stats = ['users' => 0, 'products' => 0, 'errors' => 0];
        foreach ($this->integrations($onlyUserId) as $integration) {
            $userId = (int) $integration['user_id'];
            try {
                $client = $this->targetClient($integration);
                $products = $client->products()['data']['products'] ?? [];
                $retail = $client->retail()['data'] ?? [];
                Database::execute('DELETE FROM target_products WHERE user_id = ?', [$userId]);
                foreach ($products as $product) {
                    $this->saveTargetProduct($userId, 'order', (string) ($product['id'] ?? ''), (string) ($product['name'] ?? 'Sản phẩm'), (float) ($product['price'] ?? 0), $product);
                    $stats['products']++;
                }
                foreach (($retail['packs'] ?? []) as $pack) {
                    $this->saveTargetProduct($userId, 'retail', (string) ($pack['key'] ?? ''), (string) ($pack['name'] ?? 'Gói lẻ'), (float) ($pack['price'] ?? 0), $pack);
                    $stats['products']++;
                }
                Database::execute('UPDATE integrations SET target_last_ok_at = NOW(), last_error = NULL WHERE user_id = ?', [$userId]);
                $stats['users']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->integrationError($userId, 'Không thể cập nhật danh sách sản phẩm: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    public function pullOrders(?int $onlyUserId = null): array
    {
        $stats = ['users' => 0, 'seen' => 0, 'created' => 0, 'ready' => 0, 'errors' => 0];
        foreach ($this->integrations($onlyUserId) as $integration) {
            $userId = (int) $integration['user_id'];
            try {
                $client = $this->nasClient($integration);
                $page = 1;
                $lastPage = 1;
                do {
                    $payload = $client->orders(['status' => 'processing', 'page' => $page, 'per_page' => 100]);
                    $items = $payload['data']['items'] ?? [];
                    $pagination = $payload['data']['pagination'] ?? [];
                    $lastPage = max(1, (int) ($pagination['lastPage'] ?? 1));
                    foreach ($items as $item) {
                        $stats['seen']++;
                        $local = $this->upsertNasOrder($userId, $item);
                        if ($local['created']) {
                            $stats['created']++;
                        }
                        $retryDue = empty($local['row']['next_retry_at']) || strtotime((string) $local['row']['next_retry_at']) <= time();
                        if ($retryDue && in_array($local['row']['processing_status'], ['received', 'mapping_required'], true)) {
                            if ($this->prepareOrder($integration, $local['row'], $client)) {
                                $stats['ready']++;
                            }
                        }
                    }
                    $page++;
                } while ($page <= $lastPage);
                Database::execute('UPDATE integrations SET nas_last_ok_at = NOW(), last_error = NULL WHERE user_id = ?', [$userId]);
                $stats['users']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->integrationError($userId, 'Không thể kiểm tra đơn mới từ Nas Tele: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    public function dispatchOrders(?int $onlyUserId = null, int $limit = 30): array
    {
        $stats = ['processed' => 0, 'submitted' => 0, 'retry' => 0, 'review' => 0];
        $params = [];
        $userFilter = '';
        if ($onlyUserId !== null) {
            $userFilter = ' AND o.user_id = ?';
            $params[] = $onlyUserId;
        }
        $limit = max(1, min(200, $limit));
        $orders = Database::fetchAll(
            "SELECT o.*, i.target_base_url, i.target_api_key FROM orders o JOIN integrations i ON i.user_id = o.user_id
             WHERE i.is_active = 1 AND o.processing_status IN ('ready','retry_waiting')
             AND (o.next_retry_at IS NULL OR o.next_retry_at <= NOW()) {$userFilter}
             ORDER BY o.created_at ASC LIMIT {$limit}",
            $params
        );

        foreach ($orders as $order) {
            $stats['processed']++;
            $claimed = Database::execute(
                "UPDATE orders SET processing_status = 'submitting', last_error = NULL WHERE id = ? AND processing_status IN ('ready','retry_waiting')",
                [$order['id']]
            );
            if ($claimed !== 1) {
                continue;
            }
            try {
                $snapshot = Support::decode($order['mapping_snapshot_json']);
                $codes = Support::decode($order['event_codes_json']);
                if (!$snapshot || !$codes) {
                    throw new ApiException('Đơn chưa có đủ thông tin sản phẩm hoặc mã chung sức.', 422);
                }
                $clientRef = 'nas-u' . $order['user_id'] . '-o' . $order['nas_order_id'];
                $client = new ChungSucClient((string) $order['target_base_url'], (string) $order['target_api_key']);
                $phone = $this->fieldValue(Support::decode($order['form_fields_json']), (string) ($snapshot['phone_field_key'] ?? ''));
                $note = $this->fieldValue(Support::decode($order['form_fields_json']), (string) ($snapshot['note_field_key'] ?? ''));
                $request = [];
                if (($snapshot['target_type'] ?? 'order') === 'retail_order') {
                    if (count($codes) !== 1) {
                        throw new ApiException('Đơn đặt lẻ chỉ hỗ trợ đúng một mã.', 422);
                    }
                    $request = ['code' => $codes[0], 'qty' => (int) ($snapshot['target_qty'] ?? 1), 'pack' => $snapshot['target_pack'] ?? null, 'client_ref' => $clientRef];
                    $result = $client->createRetail($codes[0], (int) ($snapshot['target_qty'] ?? 1), $snapshot['target_pack'] ?? null, $clientRef, $phone, $note);
                } else {
                    $request = ['product_id' => (int) $snapshot['target_product_id'], 'codes' => $codes, 'client_ref' => $clientRef];
                    $result = $client->createOrder((int) $snapshot['target_product_id'], $codes, $clientRef, $phone, $note);
                }
                $data = $result['data'] ?? [];
                Database::execute(
                    "INSERT INTO target_orders (order_id, client_ref, target_type, target_order_code, target_product_id, target_pack, target_qty, event_codes_json, price, status, message, request_payload, response_payload)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE target_order_code = VALUES(target_order_code), price = VALUES(price), status = VALUES(status), message = VALUES(message), response_payload = VALUES(response_payload)",
                    [
                        $order['id'], $clientRef, $snapshot['target_type'] ?? 'order', $data['code'] ?? null,
                        $snapshot['target_product_id'] ?: null, $snapshot['target_pack'] ?: null, (int) ($snapshot['target_qty'] ?? 1),
                        Support::json($codes), $data['price'] ?? null, $data['status'] ?? 'running', $data['message'] ?? null,
                        Support::json($request), Support::json($result),
                    ]
                );
                Database::execute("UPDATE orders SET processing_status = 'running', next_retry_at = NULL, last_error = NULL WHERE id = ?", [$order['id']]);
                $this->log((int) $order['user_id'], (int) $order['id'], 'target', 'info', 'Đã tạo đơn Chungsucliqi ' . ($data['code'] ?? ''), ['status' => $data['status'] ?? 'running']);
                $stats['submitted']++;
            } catch (Throwable $e) {
                $isReview = $e instanceof ApiException && in_array($e->httpStatus, [402, 409, 422], true);
                $status = $isReview ? 'manual_review' : 'retry_waiting';
                $delay = min(3600, 60 * (2 ** min((int) $order['retry_count'], 5)));
                if ($e instanceof ApiException && $e->retryAfter) {
                    $delay = max($delay, $e->retryAfter);
                }
                Database::execute(
                    "UPDATE orders SET processing_status = ?, retry_count = retry_count + 1, next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND), last_error = ? WHERE id = ?",
                    [$status, $delay, mb_substr($e->getMessage(), 0, 2000), $order['id']]
                );
                $this->log((int) $order['user_id'], (int) $order['id'], 'target', $isReview ? 'warning' : 'error', 'Không thể gửi đơn sang Chungsucliqi: ' . $e->getMessage());
                $stats[$isReview ? 'review' : 'retry']++;
            }
        }
        return $stats;
    }

    public function syncStatuses(?int $onlyUserId = null): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'errors' => 0];
        foreach ($this->integrations($onlyUserId) as $integration) {
            $userId = (int) $integration['user_id'];
            $rows = Database::fetchAll(
                "SELECT t.*, o.user_id FROM target_orders t JOIN orders o ON o.id = t.order_id
                 WHERE o.user_id = ? AND t.status NOT IN ('done','cancelled') AND t.target_order_code IS NOT NULL ORDER BY t.updated_at ASC LIMIT 500",
                [$userId]
            );
            foreach (array_chunk($rows, 50) as $chunk) {
                try {
                    $byCode = [];
                    foreach ($chunk as $row) {
                        $byCode[$row['target_order_code']] = $row;
                    }
                    $result = $this->targetClient($integration)->statuses(array_keys($byCode));
                    foreach (($result['data']['orders'] ?? []) as $remote) {
                        $code = (string) ($remote['code'] ?? '');
                        if (!isset($byCode[$code])) {
                            continue;
                        }
                        $local = $byCode[$code];
                        $status = (string) ($remote['status'] ?? $local['status']);
                        $progress = $remote['progress'] ?? [];
                        Database::execute(
                            'UPDATE target_orders SET status = ?, price = COALESCE(?, price), progress_percent = ?, tickets_done = ?, tickets_total = ?, message = ?, response_payload = ? WHERE id = ?',
                            [$status, $remote['price'] ?? null, $progress['percent'] ?? null, $progress['tickets_done'] ?? null, $progress['tickets_total'] ?? null, $remote['message'] ?? null, Support::json($remote), $local['id']]
                        );
                        if ($status === 'done') {
                            Database::execute("UPDATE orders SET processing_status = 'completed', source_sync_status = 'pending', last_error = NULL WHERE id = ?", [$local['order_id']]);
                        } elseif ($status === 'cancelled') {
                            Database::execute("UPDATE orders SET processing_status = 'cancelled', source_sync_status = 'pending', last_error = ? WHERE id = ?", [$remote['message'] ?? 'Đơn đích đã huỷ.', $local['order_id']]);
                        } else {
                            Database::execute("UPDATE orders SET processing_status = 'running' WHERE id = ?", [$local['order_id']]);
                        }
                        $stats['updated']++;
                    }
                    $stats['checked'] += count($chunk);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->integrationError($userId, 'Không thể cập nhật tình trạng đơn từ Chungsucliqi: ' . $e->getMessage());
                }
            }
        }
        return $stats;
    }

    public function pushStatuses(?int $onlyUserId = null, int $limit = 50): array
    {
        $stats = ['processed' => 0, 'synced' => 0, 'errors' => 0];
        $params = [];
        $filter = '';
        if ($onlyUserId !== null) {
            $filter = ' AND o.user_id = ?';
            $params[] = $onlyUserId;
        }
        $limit = max(1, min(200, $limit));
        $rows = Database::fetchAll(
            "SELECT o.*, i.nas_base_url, i.nas_api_key, t.message AS target_message
             FROM orders o JOIN integrations i ON i.user_id = o.user_id
             LEFT JOIN target_orders t ON t.order_id = o.id
             WHERE o.processing_status IN ('completed','cancelled') AND o.source_sync_status IN ('pending','failed') {$filter}
             ORDER BY o.updated_at ASC LIMIT {$limit}",
            $params
        );
        foreach ($rows as $row) {
            $stats['processed']++;
            try {
                $status = $row['processing_status'] === 'completed' ? 'completed' : 'cancelled';
                $reason = $status === 'cancelled' ? (string) ($row['target_message'] ?: $row['last_error'] ?: 'Đơn đích đã huỷ.') : null;
                $result = (new NasTeleClient((string) $row['nas_base_url'], (string) $row['nas_api_key']))->updateStatus((int) $row['nas_order_id'], $status, $reason);
                $notificationSent = $result['notificationSent'] ?? $result['data']['notificationSent'] ?? null;
                Database::execute(
                    'UPDATE orders SET source_status = ?, source_sync_status = ?, notification_sent = ?, last_error = ? WHERE id = ?',
                    [$status, 'synced', $notificationSent === null ? null : (int) (bool) $notificationSent, $notificationSent === false ? 'Đã cập nhật kết quả nhưng chưa gửi được thông báo cho khách.' : null, $row['id']]
                );
                $this->log((int) $row['user_id'], (int) $row['id'], 'nas', $notificationSent === false ? 'warning' : 'info', $status === 'completed' ? 'Đã báo hoàn thành đơn cho Nas Tele.' : 'Đã báo huỷ đơn cho Nas Tele.', ['notificationSent' => $notificationSent]);
                $stats['synced']++;
            } catch (Throwable $e) {
                Database::execute("UPDATE orders SET source_sync_status = 'failed', last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 2000), $row['id']]);
                $this->log((int) $row['user_id'], (int) $row['id'], 'nas', 'error', 'Không thể gửi kết quả về Nas Tele: ' . $e->getMessage());
                $stats['errors']++;
            }
        }
        return $stats;
    }

    public function runAll(?int $onlyUserId = null): array
    {
        return [
            'pull' => $this->pullOrders($onlyUserId),
            'dispatch' => $this->dispatchOrders($onlyUserId),
            'target_status' => $this->syncStatuses($onlyUserId),
            'nas_status' => $this->pushStatuses($onlyUserId),
        ];
    }

    private function integrations(?int $userId): array
    {
        $sql = "SELECT * FROM integrations WHERE is_active = 1 AND nas_api_key IS NOT NULL AND nas_api_key != '' AND target_api_key IS NOT NULL AND target_api_key != ''";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        return Database::fetchAll($sql . ' ORDER BY user_id', $params);
    }

    private function nasClient(array $integration): NasTeleClient
    {
        return new NasTeleClient((string) $integration['nas_base_url'], (string) $integration['nas_api_key']);
    }

    private function targetClient(array $integration): ChungSucClient
    {
        return new ChungSucClient((string) $integration['target_base_url'], (string) $integration['target_api_key']);
    }

    private function upsertNasOrder(int $userId, array $item): array
    {
        $existing = Database::fetch('SELECT * FROM orders WHERE user_id = ? AND nas_order_id = ?', [$userId, $item['orderId']]);
        $product = $item['product'] ?? [];
        $customer = $item['customer'] ?? [];
        $params = [
            $item['botId'] ?? null, $product['id'] ?? 0, $product['name'] ?? 'Không rõ', $item['assignedAdmin'] ?? null,
            $customer['telegramId'] ?? null, $customer['username'] ?? null, $customer['firstName'] ?? null, $customer['lastName'] ?? null,
            $item['totalAmount'] ?? 0, $item['status'] ?? 'processing', Support::json($item), $item['readyAt'] ?? null,
            $item['createdAt'] ?? null, $item['updatedAt'] ?? null,
        ];
        if ($existing) {
            Database::execute(
                'UPDATE orders SET nas_bot_id=?, nas_product_id=?, nas_product_name=?, assigned_admin=?, customer_telegram_id=?, customer_username=?, customer_first_name=?, customer_last_name=?, total_amount=?, source_status=?, source_payload=?, ready_at=?, source_created_at=?, source_updated_at=? WHERE id=?',
                array_merge($params, [$existing['id']])
            );
            return ['created' => false, 'row' => Database::fetch('SELECT * FROM orders WHERE id = ?', [$existing['id']])];
        }
        Database::execute(
            'INSERT INTO orders (user_id, nas_order_id, nas_bot_id, nas_product_id, nas_product_name, assigned_admin, customer_telegram_id, customer_username, customer_first_name, customer_last_name, total_amount, source_status, source_payload, ready_at, source_created_at, source_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge([$userId, $item['orderId']], $params)
        );
        $id = (int) Database::connection()->lastInsertId();
        return ['created' => true, 'row' => Database::fetch('SELECT * FROM orders WHERE id = ?', [$id])];
    }

    private function prepareOrder(array $integration, array $order, NasTeleClient $client): bool
    {
        $mapping = Database::fetch('SELECT * FROM product_mappings WHERE user_id = ? AND nas_product_id = ? AND is_active = 1', [$order['user_id'], $order['nas_product_id']]);
        if (!$mapping) {
            Database::execute("UPDATE orders SET processing_status = 'mapping_required', last_error = 'Sản phẩm này chưa được liên kết với Chungsucliqi.' WHERE id = ?", [$order['id']]);
            return false;
        }
        try {
            $account = $client->account((int) $order['nas_order_id']);
            $data = $account['data'] ?? [];
            $fields = $data['fields'] ?? [];
            $rawCodes = $this->fieldValue($fields, (string) $mapping['event_code_field_key']);
            if ($rawCodes === null || trim($rawCodes) === '') {
                Database::execute("UPDATE orders SET mapping_id = ?, processing_status = 'manual_review', form_fields_json = ?, last_error = ? WHERE id = ?", [$mapping['id'], Support::json($fields), 'Không tìm thấy trường chứa mã chung sức: ' . $mapping['event_code_field_key'], $order['id']]);
                return false;
            }
            $codes = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $rawCodes) ?: []))));
            if (!$codes) {
                throw new ApiException('Trường chứa mã chung sức đang để trống hoặc không đúng định dạng.', 422);
            }
            Database::execute(
                "UPDATE orders SET mapping_id = ?, processing_status = 'ready', form_fields_json = ?, event_codes_json = ?, mapping_snapshot_json = ?, last_error = NULL WHERE id = ?",
                [$mapping['id'], Support::json($fields), Support::json($codes), Support::json($mapping), $order['id']]
            );
            return true;
        } catch (Throwable $e) {
            Database::execute("UPDATE orders SET processing_status = 'received', retry_count = retry_count + 1, next_retry_at = DATE_ADD(NOW(), INTERVAL 2 MINUTE), last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 2000), $order['id']]);
            $this->log((int) $order['user_id'], (int) $order['id'], 'nas', 'error', 'Không thể đọc dữ liệu đơn: ' . $e->getMessage());
            return false;
        }
    }

    private function fieldValue(array $fields, string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        foreach ($fields as $field) {
            if ((string) ($field['key'] ?? '') === $key) {
                $value = $field['value'] ?? null;
                return is_scalar($value) ? (string) $value : Support::json($value);
            }
        }
        return null;
    }

    private function saveTargetProduct(int $userId, string $type, string $externalId, string $name, float $price, array $metadata): void
    {
        if ($externalId === '') {
            return;
        }
        Database::execute(
            'INSERT INTO target_products (user_id, target_type, external_id, name, price, metadata_json, synced_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$userId, $type, $externalId, $name, $price, Support::json($metadata)]
        );
    }

    private function integrationError(int $userId, string $message): void
    {
        Database::execute('UPDATE integrations SET last_error = ? WHERE user_id = ?', [mb_substr($message, 0, 2000), $userId]);
        $this->log($userId, null, 'system', 'error', $message);
    }

    private function log(?int $userId, ?int $orderId, string $channel, string $level, string $message, array $context = []): void
    {
        Database::execute(
            'INSERT INTO sync_logs (user_id, order_id, channel, level, message, context_json) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $orderId, $channel, $level, mb_substr($message, 0, 500), $context ? Support::json($context) : null]
        );
    }
}
