<?php

namespace Nqt\CrawlerOtruyen\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client gọi API công khai OTruyen (danh sách, chi tiết truyện, chi tiết chương).
 */
class OtruyenApiClient
{
    public function getJson(string $url): array
    {
        $response = $this->request($url);

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Phản hồi không phải JSON: '.$url);
        }

        if (($data['status'] ?? '') !== 'success') {
            throw new \RuntimeException('API trả lỗi: '.($data['message'] ?? 'unknown').' — '.$url);
        }

        return $data;
    }

    public function request(string $url): Response
    {
        return Http::timeout((int) config('crawler-otruyen.http_timeout', 40))
            ->withHeaders([
                'User-Agent' => config('crawler-otruyen.user_agent', 'TruyenApp-CrawlerOtruyen/1.0'),
                'Accept' => 'application/json',
            ])
            ->get($url);
    }
}
