<?php

namespace Nqt\CrawlerOtruyen\Services;

/**
 * Bước 1: lấy danh sách truyện từ API danh sách (có phân trang).
 */
class OtruyenCatalogService
{
    public function __construct(
        protected OtruyenApiClient $client
    ) {}

    /**
     * @return list<array{slug: string, name: string, thumb_url: string}>
     */
    public function fetchListPageRange(string $listUrl, int $startPage, int $endPage): array
    {
        $startPage = max(1, $startPage);
        $endPage = max($startPage, $endPage);

        $out = [];

        for ($page = $startPage; $page <= $endPage; $page++) {
            $url = $this->withPage($listUrl, $page);
            $data = $this->client->getJson($url);
            $items = $data['data']['items'] ?? [];
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $slug = (string) ($it['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $out[] = [
                    'slug' => $slug,
                    'name' => (string) ($it['name'] ?? $slug),
                    'thumb_url' => (string) ($it['thumb_url'] ?? ''),
                ];
            }

            $pagination = $data['data']['params']['pagination'] ?? null;
            if (is_array($pagination)) {
                $current = (int) ($pagination['currentPage'] ?? $page);
                $per = (int) ($pagination['totalItemsPerPage'] ?? 24);
                $total = (int) ($pagination['totalItems'] ?? 0);
                if ($per > 0 && $total > 0 && ($current * $per) >= $total) {
                    break;
                }
            }
        }

        return $out;
    }

    protected function withPage(string $url, int $page): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('URL danh sách không hợp lệ.');
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['page'] = $page;

        $path = $parts['path'] ?? '/';

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port
            .$path
            .'?'.http_build_query($query);
    }
}
