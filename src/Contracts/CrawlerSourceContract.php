<?php

namespace Nqt\CrawlerOtruyen\Contracts;

/**
 * Một nguồn crawl (API/site khác nhau). Thêm nguồn mới: tạo class implements
 * contract này, đăng ký trong config `crawler-otruyen.sources`.
 */
interface CrawlerSourceContract
{
    /** Khóa cấu hình (vd: otruyen, nettruyen) — duy nhất */
    public function id(): string;

    /** Nhãn hiển thị trong admin / CLI */
    public function label(): string;

    /** URL danh sách mặc định (chế độ list) hoặc null nếu không dùng list */
    public function defaultListUrl(): ?string;

    /**
     * Lấy danh sách truyện từ API danh sách có phân trang.
     *
     * @return list<array{slug: string, name: string, thumb_url: string}>
     */
    public function fetchCatalog(string $listUrl, int $pageStart, int $pageEnd): array;

    /**
     * Parse khối text nhiều dòng (URL/slug) → danh sách truyện đã resolve metadata.
     *
     * @return list<array{slug: string, name: string, thumb_url: string}>
     */
    public function resolveMangasFromDetailLines(string $detailBlock): array;

    /**
     * Import đầy đủ truyện + chương vào DB (cùng định dạng kết quả cho wizard/SSE).
     *
     * @param  array<int, string>  $slugs
     * @param  callable|null  $onLog  (array $entry): void
     * @param  array<string, mixed>|null  $importOptions  Ghi đè image_mode, content_rewrite_enabled (wizard / CLI)
     * @return array<string, mixed>
     */
    public function importMangas(array $slugs, ?callable $onLog = null, ?array $importOptions = null): array;
}
