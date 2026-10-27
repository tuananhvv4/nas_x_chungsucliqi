<?php

declare(strict_types=1);

namespace App;

final class HttpClient
{
    public static function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $allHeaders = array_merge(['Accept: application/json'], $headers);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = Support::json($body);
            $allHeaders[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $allHeaders;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new ApiException('Không thể kết nối đến hệ thống. ' . $curlError);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new ApiException('Hệ thống bên kia trả về dữ liệu không hợp lệ.', $status, ['raw' => mb_substr((string) $raw, 0, 1000)]);
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? ('Yêu cầu không thành công, mã lỗi ' . $status));
            $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
            throw new ApiException($message, $status, $decoded, $retryAfter);
        }

        return ['status' => $status, 'data' => $decoded, 'headers' => $responseHeaders];
    }
}
