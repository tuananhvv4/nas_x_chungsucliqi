<?php

declare(strict_types=1);

namespace App;

final class ChungSucClient
{
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey)
    {
    }

    private function call(string $method, string $action, array $query = [], ?array $body = null): array
    {
        $query = array_merge(['action' => $action], $query);
        $url = $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . http_build_query($query);
        $response = HttpClient::request($method, $url, ['X-API-Key: ' . $this->apiKey], $body);
        $payload = $response['data'];
        if (($payload['ok'] ?? false) !== true) {
            throw new ApiException((string) ($payload['error']['message'] ?? 'Chungsucliqi trả kết quả không thành công.'), $response['status'], $payload);
        }
        return $payload;
    }

    public function me(): array
    {
        return $this->call('GET', 'me');
    }

    public function serviceStatus(): array
    {
        return $this->call('GET', 'status');
    }

    public function products(): array
    {
        return $this->call('GET', 'products');
    }

    public function retail(): array
    {
        return $this->call('GET', 'retail');
    }

    public function createOrder(int $productId, array $codes, string $clientRef, ?string $phone = null, ?string $note = null): array
    {
        return $this->call('POST', 'order', [], array_filter([
            'product_id' => $productId,
            'codes' => array_values($codes),
            'client_ref' => $clientRef,
            'phone' => $phone,
            'note' => $note,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    public function createRetail(string $code, int $qty, ?string $pack, string $clientRef, ?string $phone = null, ?string $note = null): array
    {
        return $this->call('POST', 'retail_order', [], array_filter([
            'code' => $code,
            'qty' => $qty,
            'pack' => $pack,
            'client_ref' => $clientRef,
            'phone' => $phone,
            'note' => $note,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    public function statuses(array $codes): array
    {
        return $this->call('GET', 'orders_status', ['codes' => implode(',', $codes)]);
    }
}

