<?php

declare(strict_types=1);

namespace App;

final class NasTeleClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey)
    {
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->apiKey];
    }

    public function orders(array $filters = []): array
    {
        $query = http_build_query(array_filter($filters, static fn ($value) => $value !== null && $value !== ''));
        $response = HttpClient::request('GET', rtrim($this->baseUrl, '/') . '/api/admin/order-form-orders' . ($query ? '?' . $query : ''), $this->headers());
        return $response['data'];
    }

    public function account(int $orderId): array
    {
        $response = HttpClient::request('GET', rtrim($this->baseUrl, '/') . '/api/admin/order-form-orders/' . $orderId . '/account', $this->headers());
        return $response['data'];
    }

    public function updateStatus(int $orderId, string $status, ?string $cancelReason = null): array
    {
        $body = ['status' => $status];
        if ($status === 'cancelled') {
            $body['cancel_reason'] = $cancelReason ?: 'Đơn đã bị huỷ bởi hệ thống xử lý.';
        }
        $response = HttpClient::request('PUT', rtrim($this->baseUrl, '/') . '/api/admin/order-form-orders/' . $orderId . '/status', $this->headers(), $body);
        return $response['data'];
    }
}

